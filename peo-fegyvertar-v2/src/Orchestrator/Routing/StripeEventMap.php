<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Routing;

use Peoft\Core\Config\Config;
use Peoft\Core\Env;
use Peoft\Orchestrator\Queue\IdempotencyKey;
use Peoft\Orchestrator\Queue\TaskSpec;
use Stripe\Event as StripeEvent;

defined('ABSPATH') || exit;

/**
 * The single source of truth for "which Stripe event produces which tasks".
 *
 * Phase B: every mapped event produces exactly one `noop.log_only` task, so we
 * can verify the end-to-end flow (webhook → router → enqueue → worker → done)
 * before any real integration handlers exist.
 *
 * Phase C replaces each closure with the real TaskSpec fan-out per the plan §5
 * task registry. Events that only trigger tracking-with-no-side-effect stay
 * unmapped — EventRouter returns an empty list for them, the webhook is acked
 * but no task rows are created.
 */
final class StripeEventMap
{
    /**
     * @return array<string, callable(StripeEvent, Env): list<TaskSpec>>
     */
    public static function build(): array
    {
        return [
            'invoice.payment_succeeded' => self::invoicePaymentSucceeded(),
            // Remaining noops (invoice.payment_failed, customer.subscription.updated,
            // customer.updated, payment_method.attached) get real fan-outs in
            // a follow-up — not critical for the core subscription flow.
            'invoice.payment_failed'    => self::noop('invoice.payment_failed', 'in_'),
            'charge.refunded'           => self::chargeRefunded(),
            'customer.subscription.deleted' => self::customerSubscriptionDeleted(),
            'customer.subscription.updated' => self::noop('customer.subscription.updated', 'sub_'),
            'checkout.session.completed'    => self::checkoutSessionCompleted(),
            'customer.tax_id.updated'       => self::customerTaxIdUpdated(),
            'customer.tax_id.created'       => self::customerTaxIdUpdated(),
            'customer.updated'              => self::noop('customer.updated', 'cus_'),
            'payment_method.attached'       => self::noop('payment_method.attached', 'pm_'),
        ];
    }

    /**
     * Phase C1 routing: `invoice.payment_succeeded` fans out to a single
     * `email.send_success_purchase` task. Phase C2 adds AC tasks, C3 adds
     * Circle enroll, C4 adds Számlázz invoice — all in the same list here.
     *
     * Extracts as many `sikeres_rendeles` template variables as we can from
     * the Stripe invoice alone. What we can't get (card details, Számlázz
     * invoice number, product name) is left unset; the handler fills unknown
     * declared vars with empty strings.
     *
     * @return callable(StripeEvent, Env): list<TaskSpec>
     */
    private static function invoicePaymentSucceeded(): callable
    {
        return static function (StripeEvent $event, Env $env): array {
            $inv = $event->data->object ?? null;
            $invoiceId     = is_object($inv) && isset($inv->id)               ? (string) $inv->id               : $event->id;
            $customerEmail = is_object($inv) && isset($inv->customer_email)   ? (string) $inv->customer_email   : '';
            $customerName  = is_object($inv) && isset($inv->customer_name)    ? (string) $inv->customer_name    : '';
            $amountTotal   = is_object($inv) && isset($inv->amount_paid)      ? (int) $inv->amount_paid         : 0;
            $currency      = is_object($inv) && isset($inv->currency)         ? strtoupper((string) $inv->currency) : 'HUF';

            // Stripe amounts are integer minor units (cents/fillér). Format
            // for display; currency-specific formatting lives in C4 when we
            // have Számlázz data too. For now, plain "X <code>".
            $totalAmountDisplay = self::formatAmount($amountTotal, $currency);

            $tasks = [];

            if ($customerEmail === '') {
                // No email → no fan-out. Webhook is still acked + deduped.
                return $tasks;
            }

            // C1 + C5: confirmation email. `stripe_customer_id` triggers the
            // SendTransactionalEmailHandler's CustomerContextLoader enrichment
            // at dispatch time, filling address/card/name vars from Stripe.
            $stripeCustomerId = is_object($inv) && isset($inv->customer) ? (string) $inv->customer : '';
            $tasks[] = new TaskSpec(
                taskType:       'email.send_success_purchase',
                idempotencyKey: IdempotencyKey::for(
                    'email.send_success_purchase',
                    $env->value,
                    $invoiceId
                ),
                stripeRef:      $invoiceId,
                payload:        [
                    'template_slug'      => 'sikeres_rendeles',
                    'to'                 => $customerEmail,
                    'reply_to'           => null,
                    'stripe_customer_id' => $stripeCustomerId,
                    'vars'               => [
                        'customer_email' => $customerEmail,
                        'order_id'       => $invoiceId,
                        'total_amount'   => $totalAmountDisplay,
                        'email_subject'  => 'Fegyvertár - Sikeres rendelés',
                        'this_year'      => (string) gmdate('Y'),
                        // Remaining ~15 declared vars are filled by C5's
                        // CustomerContextLoader enrichment in the handler
                        // (address/card/name from Stripe) and by the xref
                        // lookup for invoice_number.
                    ],
                ],
                sourceEventId:  $event->id,
                actor:          'stripe',
            );

            // C2: ActiveCampaign fan-out — upsert, then tag ACTIVE,
            // then ensure the DELETED tag is removed (handles the case
            // where a previously-cancelled customer re-subscribes).
            $firstName = trim(explode(' ', $customerName, 2)[0] ?? '');
            $lastName  = trim(explode(' ', $customerName, 2)[1] ?? '');

            $tasks[] = new TaskSpec(
                taskType:       'ac.upsert_contact',
                idempotencyKey: IdempotencyKey::for('ac.upsert_contact', $env->value, $customerEmail),
                stripeRef:      $invoiceId,
                payload: [
                    'email'      => $customerEmail,
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                ],
                sourceEventId: $event->id,
                actor:         'stripe',
            );

            $tasks[] = new TaskSpec(
                taskType:       'ac.tag_contact',
                idempotencyKey: IdempotencyKey::for('ac.tag_contact', $env->value, $customerEmail, 'FT: ACTIVE'),
                stripeRef:      $invoiceId,
                payload: [
                    'email' => $customerEmail,
                    'tag'   => 'FT: ACTIVE',
                ],
                sourceEventId: $event->id,
                actor:         'stripe',
            );

            $tasks[] = new TaskSpec(
                taskType:       'ac.untag_contact',
                idempotencyKey: IdempotencyKey::for('ac.untag_contact', $env->value, $customerEmail, 'FT: DELETED'),
                stripeRef:      $invoiceId,
                payload: [
                    'email' => $customerEmail,
                    'tag'   => 'FT: DELETED',
                ],
                sourceEventId: $event->id,
                actor:         'stripe',
            );

            // C3: Circle enrollment. Access group id is read from config at
            // closure-invocation time (inside the webhook request, with
            // Config already bound by Kernel::boot).
            $accessGroupId = (string) Config::for('circle')->get('access_group_id', '');
            if ($accessGroupId !== '') {
                $tasks[] = new TaskSpec(
                    taskType:       'circle.enroll_member',
                    idempotencyKey: IdempotencyKey::for('circle.enroll_member', $env->value, $customerEmail, $accessGroupId),
                    stripeRef:      $invoiceId,
                    payload: [
                        'email'           => $customerEmail,
                        'name'            => $customerName,
                        'access_group_id' => $accessGroupId,
                        'skip_invitation' => false,
                    ],
                    sourceEventId: $event->id,
                    actor:         'stripe',
                );
            }

            // C4: Számlázz invoice. Buyer address comes from the Stripe
            // invoice's customer_address sub-object; vat_id from customer_tax_ids
            // if present. Net amount is computed by the handler from gross.
            $address = (is_object($inv) && isset($inv->customer_address) && is_object($inv->customer_address)) ? $inv->customer_address : null;
            $buyerCountry   = $address && isset($address->country)     ? strtoupper((string) $address->country) : 'HU';
            $buyerPostal    = $address && isset($address->postal_code) ? (string) $address->postal_code : '';
            $buyerCity      = $address && isset($address->city)        ? (string) $address->city : '';
            $buyerAddress1  = $address && isset($address->line1)       ? (string) $address->line1 : '';
            $buyerAddress2  = $address && isset($address->line2)       ? (string) $address->line2 : '';
            $buyerVatId = null;
            if (is_object($inv) && isset($inv->customer_tax_ids) && is_array($inv->customer_tax_ids)) {
                foreach ($inv->customer_tax_ids as $taxId) {
                    if (is_object($taxId) && isset($taxId->value)) {
                        $buyerVatId = (string) $taxId->value;
                        break;
                    }
                }
            }

            $tasks[] = new TaskSpec(
                taskType:       'szamlazz.issue_invoice',
                idempotencyKey: IdempotencyKey::for('szamlazz.issue_invoice', $env->value, $invoiceId),
                stripeRef:      $invoiceId,
                payload: [
                    'stripe_invoice_id'  => $invoiceId,
                    'currency'           => $currency,
                    'gross_amount_minor' => $amountTotal,
                    'coupon_name'        => null,
                    'buyer' => [
                        'name'           => $customerName !== '' ? $customerName : $customerEmail,
                        'email'          => $customerEmail,
                        'country'        => $buyerCountry,
                        'postal_code'    => $buyerPostal,
                        'city'           => $buyerCity,
                        'address_line_1' => $buyerAddress1,
                        'address_line_2' => $buyerAddress2,
                        'vat_id'         => $buyerVatId,
                    ],
                    'item' => [
                        'name'   => 'Online oktatás',
                        'period' => '',
                    ],
                ],
                sourceEventId: $event->id,
                actor:         'stripe',
            );

            return $tasks;
        };
    }

    /**
     * `charge.refunded` — a Stripe charge was refunded. Route to:
     *   - szamlazz.issue_storno (reverse the Számlázz invoice)
     *   - (C5 will add: email.send_refund, ac.untag FT: ACTIVE, ac.tag FT: CANCELLED)
     *
     * @return callable(StripeEvent, Env): list<TaskSpec>
     */
    private static function chargeRefunded(): callable
    {
        return static function (StripeEvent $event, Env $env): array {
            $charge = $event->data->object ?? null;
            $chargeId = is_object($charge) && isset($charge->id) ? (string) $charge->id : $event->id;
            // Stripe Charge objects carry `invoice` when they're the
            // payment for an invoice (subscription case). Without it
            // we can't resolve the original Számlázz document.
            $stripeInvoiceId = is_object($charge) && isset($charge->invoice) ? (string) $charge->invoice : '';

            if ($stripeInvoiceId === '') {
                // One-off payments (no invoice) are out of scope for C4.
                return [];
            }

            return [
                new TaskSpec(
                    taskType:       'szamlazz.issue_storno',
                    idempotencyKey: IdempotencyKey::for('szamlazz.issue_storno', $env->value, $chargeId),
                    stripeRef:      $chargeId,
                    payload: [
                        'stripe_charge_id'  => $chargeId,
                        'stripe_invoice_id' => $stripeInvoiceId,
                    ],
                    sourceEventId: $event->id,
                    actor:         'stripe',
                ),
            ];
        };
    }

    /**
     * `customer.subscription.deleted` — the customer's subscription has ended.
     * Phase C3 routing:
     *   - circle.revoke_member: remove them from the paid access group
     *   - ac.tag_contact FT: DELETED: mark them as churned in AC
     *   - ac.untag_contact FT: ACTIVE: remove the active flag
     *
     * Phase C1/C5 will add an email task (elofizetesed_lemondva_azonnali)
     * and the Stripe customer-bundle-based VAT cleanup. For now the
     * minimal Circle + AC flow ships.
     *
     * @return callable(StripeEvent, Env): list<TaskSpec>
     */
    private static function customerSubscriptionDeleted(): callable
    {
        return static function (StripeEvent $event, Env $env): array {
            $sub = $event->data->object ?? null;
            $subscriptionId = is_object($sub) && isset($sub->id) ? (string) $sub->id : $event->id;

            // Subscription webhooks carry customer id but not always the
            // email directly. For C3 we fall back to whatever the event
            // exposes; C5 (StripeClient) will fetch the customer record to
            // get the canonical email.
            $customerEmail = '';
            if (is_object($sub)) {
                if (isset($sub->customer_email) && is_string($sub->customer_email)) {
                    $customerEmail = (string) $sub->customer_email;
                } elseif (isset($sub->metadata) && is_object($sub->metadata) && isset($sub->metadata->customer_email)) {
                    $customerEmail = (string) $sub->metadata->customer_email;
                }
            }

            if ($customerEmail === '') {
                // No email → cannot route anything meaningful. Ack the webhook
                // with an empty fan-out; Phase C5's customer-bundle lookup
                // will resolve the email from the Stripe customer id.
                return [];
            }

            $tasks = [];

            $accessGroupId = (string) Config::for('circle')->get('access_group_id', '');
            if ($accessGroupId !== '') {
                $tasks[] = new TaskSpec(
                    taskType:       'circle.revoke_member',
                    idempotencyKey: IdempotencyKey::for('circle.revoke_member', $env->value, $customerEmail, $accessGroupId),
                    stripeRef:      $subscriptionId,
                    payload: [
                        'email'           => $customerEmail,
                        'access_group_id' => $accessGroupId,
                    ],
                    sourceEventId: $event->id,
                    actor:         'stripe',
                );
            }

            $tasks[] = new TaskSpec(
                taskType:       'ac.tag_contact',
                idempotencyKey: IdempotencyKey::for('ac.tag_contact', $env->value, $customerEmail, 'FT: DELETED'),
                stripeRef:      $subscriptionId,
                payload: [
                    'email' => $customerEmail,
                    'tag'   => 'FT: DELETED',
                ],
                sourceEventId: $event->id,
                actor:         'stripe',
            );

            $tasks[] = new TaskSpec(
                taskType:       'ac.untag_contact',
                idempotencyKey: IdempotencyKey::for('ac.untag_contact', $env->value, $customerEmail, 'FT: ACTIVE'),
                stripeRef:      $subscriptionId,
                payload: [
                    'email' => $customerEmail,
                    'tag'   => 'FT: ACTIVE',
                ],
                sourceEventId: $event->id,
                actor:         'stripe',
            );

            return $tasks;
        };
    }

    /**
     * `customer.tax_id.updated` (or .created) — customer added/changed their
     * VAT number at Stripe. Fan out to:
     *   - `stripe.update_customer_vat`: audits the change and prepares for
     *     downstream AC contact update (which will be added in a follow-up).
     *
     * @return callable(StripeEvent, Env): list<TaskSpec>
     */
    private static function customerTaxIdUpdated(): callable
    {
        return static function (StripeEvent $event, Env $env): array {
            $taxId = $event->data->object ?? null;
            $customerId = is_object($taxId) && isset($taxId->customer) ? (string) $taxId->customer : '';
            $value      = is_object($taxId) && isset($taxId->value)    ? (string) $taxId->value    : '';
            $type       = is_object($taxId) && isset($taxId->type)     ? (string) $taxId->type     : '';

            if ($customerId === '') {
                return [];
            }

            return [
                new TaskSpec(
                    taskType:       'stripe.update_customer_vat',
                    idempotencyKey: IdempotencyKey::for('stripe.update_customer_vat', $env->value, $customerId, $value),
                    stripeRef:      $customerId,
                    payload: [
                        'customer_id'  => $customerId,
                        'tax_id_value' => $value,
                        'tax_id_type'  => $type,
                    ],
                    sourceEventId: $event->id,
                    actor:         'stripe',
                ),
            ];
        };
    }

    /**
     * `checkout.session.completed` — customer just completed a Stripe Checkout.
     *
     * For 1.0's paid-trial flow (trial = one-time payment followed by real
     * subscription creation), we enqueue a `trial.convert_to_subscription`
     * task that retry-schedules until the conversion is complete. See
     * TrialConvertToSubscriptionHandler for the mechanic and the open
     * question of whether this can be replaced with Stripe-native trial
     * periods post-cutover (plan §17 item 8).
     *
     * Non-trial sessions (regular subscription checkouts) don't need this
     * task — Stripe sends its own `invoice.payment_succeeded` which drives
     * the fan-out in that path.
     *
     * @return callable(StripeEvent, Env): list<TaskSpec>
     */
    private static function checkoutSessionCompleted(): callable
    {
        return static function (StripeEvent $event, Env $env): array {
            $session = $event->data->object ?? null;
            $sessionId = is_object($session) && isset($session->id) ? (string) $session->id : $event->id;
            $mode      = is_object($session) && isset($session->mode) ? (string) $session->mode : '';
            $metadata  = is_object($session) && isset($session->metadata) ? $session->metadata : null;
            $isTrial = is_object($metadata) && isset($metadata->flow) && (string) $metadata->flow === 'trial';

            if (!$isTrial) {
                // Non-trial subscription checkouts rely on the subsequent
                // invoice.payment_succeeded event for fan-out. Ack with no
                // tasks.
                return [];
            }

            $targetPriceId = is_object($metadata) && isset($metadata->target_price_id)
                ? (string) $metadata->target_price_id
                : null;

            return [
                new TaskSpec(
                    taskType:       'trial.convert_to_subscription',
                    idempotencyKey: IdempotencyKey::for('trial.convert_to_subscription', $env->value, $sessionId),
                    stripeRef:      $sessionId,
                    payload: [
                        'checkout_session_id' => $sessionId,
                        'mode'                => $mode,
                        'target_price_id'     => $targetPriceId,
                    ],
                    sourceEventId: $event->id,
                    actor:         'stripe',
                ),
            ];
        };
    }

    private static function formatAmount(int $minorUnits, string $currency): string
    {
        // HUF and JPY are effectively zero-decimal in day-to-day display.
        // Everything else uses 2-decimal formatting. The business only sells
        // in HUF today; the fallback is defensive.
        $zeroDecimal = in_array($currency, ['HUF', 'JPY'], true);
        if ($zeroDecimal) {
            return number_format($minorUnits, 0, ',', ' ') . ' ' . $currency;
        }
        return number_format($minorUnits / 100, 2, '.', ',') . ' ' . $currency;
    }

    /**
     * @return callable(StripeEvent, Env): list<TaskSpec>
     */
    private static function noop(string $eventType, string $expectedPrefix): callable
    {
        return static function (StripeEvent $event, Env $env) use ($eventType, $expectedPrefix): array {
            $object = $event->data->object ?? null;
            $ref = is_object($object) && isset($object->id) ? (string) $object->id : $event->id;

            return [
                new TaskSpec(
                    taskType:       'noop.log_only',
                    idempotencyKey: IdempotencyKey::for('noop.log_only', $env->value, $event->id, $eventType),
                    stripeRef:      $ref,
                    payload:        [
                        'event_type'      => $eventType,
                        'event_id'        => $event->id,
                        'object_id'       => $ref,
                        'expected_prefix' => $expectedPrefix,
                    ],
                    sourceEventId:  $event->id,
                    actor:          'stripe',
                ),
            ];
        };
    }
}
