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
            // Phase C4/C5 replace the remaining noops with real fan-outs.
            'invoice.payment_failed'    => self::noop('invoice.payment_failed', 'in_'),
            'charge.refunded'           => self::noop('charge.refunded', 'ch_'),
            'customer.subscription.deleted' => self::customerSubscriptionDeleted(),
            'customer.subscription.updated' => self::noop('customer.subscription.updated', 'sub_'),
            'checkout.session.completed'    => self::noop('checkout.session.completed', 'cs_'),
            'customer.tax_id.updated'       => self::noop('customer.tax_id.updated', 'cus_'),
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

            // C1: confirmation email
            $tasks[] = new TaskSpec(
                taskType:       'email.send_success_purchase',
                idempotencyKey: IdempotencyKey::for(
                    'email.send_success_purchase',
                    $env->value,
                    $invoiceId
                ),
                stripeRef:      $invoiceId,
                payload:        [
                    'template_slug' => 'sikeres_rendeles',
                    'to'            => $customerEmail,
                    'reply_to'      => null,
                    'vars'          => [
                        'customer_email' => $customerEmail,
                        'order_id'       => $invoiceId,
                        'total_amount'   => $totalAmountDisplay,
                        'email_subject'  => 'Fegyvertár - Sikeres rendelés',
                        'this_year'      => (string) gmdate('Y'),
                        // The remaining ~15 declared vars are filled as
                        // later sub-phases (C4 Számlázz, C5 Stripe customer
                        // bundle) come online. Until then the handler
                        // substitutes empty strings for undefined declared vars.
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

            // C4 will add szamlazz.issue_invoice here.

            return $tasks;
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
