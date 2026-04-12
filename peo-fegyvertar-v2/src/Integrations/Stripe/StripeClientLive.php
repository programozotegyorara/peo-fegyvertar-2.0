<?php

declare(strict_types=1);

namespace Peoft\Integrations\Stripe;

use Peoft\Audit\ApiCallRecord;
use Peoft\Audit\AuditLog;
use Peoft\Orchestrator\Worker\PoisonException;
use Peoft\Orchestrator\Worker\RetryableException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient as StripeSdkClient;

defined('ABSPATH') || exit;

/**
 * Real StripeClient backed by the official \Stripe\StripeClient SDK.
 *
 * Error classification (mapped to our worker exceptions):
 *   - ApiConnectionException, RateLimitException → RetryableException
 *   - InvalidRequestException (404, 400)          → returns null / throws Poison
 *   - other ApiErrorException (5xx-ish)            → RetryableException
 *   - anything else                                → rethrown as Poison
 *
 * Uses `->customers->retrieve(..., expand=[...])` to fetch the customer plus
 * default payment method plus tax IDs in a single HTTP call. Active
 * subscription lookup is a second call (subscriptions->all with limit=1).
 *
 * Audit: writes one `API_CALL` row per SDK method invocation with
 * method=GET/POST, url=stripe-api://..., status and duration. Headers are
 * never passed to the SDK directly so there's nothing to redact from
 * request headers.
 */
final class StripeClientLive implements StripeClient
{
    public function __construct(
        private readonly StripeSdkClient $sdk,
    ) {}

    public function fetchCustomerBundle(string $customerId): CustomerBundle
    {
        $startedMs = (int) (microtime(true) * 1000);
        try {
            $customer = $this->sdk->customers->retrieve($customerId, [
                'expand' => [
                    'invoice_settings.default_payment_method',
                    'tax_ids',
                ],
            ]);
        } catch (InvalidRequestException $e) {
            $this->audit('GET', "/v1/customers/{$customerId}", 0, $startedMs, $e->getMessage());
            throw new PoisonException("Stripe customer not found or invalid id: {$customerId} — " . $e->getMessage());
        } catch (ApiConnectionException | RateLimitException $e) {
            $this->audit('GET', "/v1/customers/{$customerId}", 0, $startedMs, $e->getMessage());
            throw new RetryableException('Stripe transport/rate-limit: ' . $e->getMessage(), 0, $e);
        } catch (ApiErrorException $e) {
            $this->audit('GET', "/v1/customers/{$customerId}", 0, $startedMs, $e->getMessage());
            if ($e->getHttpStatus() !== null && $e->getHttpStatus() >= 500) {
                throw new RetryableException('Stripe 5xx: ' . $e->getMessage(), 0, $e);
            }
            throw new PoisonException('Stripe API error: ' . $e->getMessage(), 0, $e);
        }
        $this->audit('GET', "/v1/customers/{$customerId}", 200, $startedMs, null);

        // Active subscription — best-effort. Failure here does not fail the
        // whole bundle fetch; handlers tolerate the null fields.
        $activeSubscriptionId = null;
        $activePriceId = null;
        try {
            $subs = $this->sdk->subscriptions->all([
                'customer' => $customerId,
                'status'   => 'active',
                'limit'    => 1,
            ]);
            $firstSub = $subs->data[0] ?? null;
            if ($firstSub !== null) {
                $activeSubscriptionId = (string) $firstSub->id;
                $activePriceId = isset($firstSub->items->data[0]->price->id)
                    ? (string) $firstSub->items->data[0]->price->id
                    : null;
            }
        } catch (\Throwable $e) {
            error_log('[peoft] Stripe subscriptions list failed for ' . $customerId . ': ' . $e->getMessage());
        }

        return new CustomerBundle(
            customerId:           (string) $customer->id,
            email:                (string) ($customer->email ?? ''),
            name:                 (string) ($customer->name ?? ''),
            address:              $this->addressFromStripeObject($customer->address ?? null),
            defaultCard:          $this->cardFromDefaultPaymentMethod($customer->invoice_settings->default_payment_method ?? null),
            defaultTaxId:         $this->firstTaxIdValue($customer->tax_ids->data ?? []),
            customerPortalUrl:    null, // not generated here — handlers call createBillingPortalSession if needed
            activeSubscriptionId: $activeSubscriptionId,
            activePriceId:        $activePriceId,
        );
    }

    public function fetchInvoiceRaw(string $invoiceId): ?array
    {
        $startedMs = (int) (microtime(true) * 1000);
        try {
            $invoice = $this->sdk->invoices->retrieve($invoiceId);
        } catch (InvalidRequestException $e) {
            $this->audit('GET', "/v1/invoices/{$invoiceId}", 0, $startedMs, $e->getMessage());
            return null;
        } catch (\Throwable $e) {
            $this->audit('GET', "/v1/invoices/{$invoiceId}", 0, $startedMs, $e->getMessage());
            throw new RetryableException('Stripe fetchInvoiceRaw: ' . $e->getMessage(), 0, $e);
        }
        $this->audit('GET', "/v1/invoices/{$invoiceId}", 200, $startedMs, null);
        return $invoice->toArray();
    }

    public function fetchCheckoutSessionRaw(string $sessionId): ?array
    {
        $startedMs = (int) (microtime(true) * 1000);
        try {
            $session = $this->sdk->checkout->sessions->retrieve($sessionId);
        } catch (InvalidRequestException $e) {
            $this->audit('GET', "/v1/checkout/sessions/{$sessionId}", 0, $startedMs, $e->getMessage());
            return null;
        } catch (\Throwable $e) {
            $this->audit('GET', "/v1/checkout/sessions/{$sessionId}", 0, $startedMs, $e->getMessage());
            throw new RetryableException('Stripe fetchCheckoutSessionRaw: ' . $e->getMessage(), 0, $e);
        }
        $this->audit('GET', "/v1/checkout/sessions/{$sessionId}", 200, $startedMs, null);
        return $session->toArray();
    }

    private function addressFromStripeObject(mixed $raw): StripeAddress
    {
        if (!is_object($raw)) {
            return StripeAddress::empty();
        }
        return new StripeAddress(
            country:    (string) ($raw->country ?? ''),
            postalCode: (string) ($raw->postal_code ?? ''),
            city:       (string) ($raw->city ?? ''),
            line1:      (string) ($raw->line1 ?? ''),
            line2:      (string) ($raw->line2 ?? ''),
            state:      (string) ($raw->state ?? ''),
        );
    }

    private function cardFromDefaultPaymentMethod(mixed $pm): ?StripeCardDetails
    {
        if (!is_object($pm)) {
            return null;
        }
        $card = $pm->card ?? null;
        if (!is_object($card)) {
            return null;
        }
        return new StripeCardDetails(
            brand:           (string) ($card->brand ?? ''),
            last4:           (string) ($card->last4 ?? ''),
            expMonth:        (int)    ($card->exp_month ?? 0),
            expYear:         (int)    ($card->exp_year ?? 0),
            paymentMethodId: isset($pm->id) ? (string) $pm->id : null,
        );
    }

    /**
     * @param list<object>|array<int,object> $taxIdObjects
     */
    private function firstTaxIdValue(mixed $taxIdObjects): ?string
    {
        if (!is_array($taxIdObjects)) {
            return null;
        }
        foreach ($taxIdObjects as $tid) {
            if (is_object($tid) && isset($tid->value)) {
                return (string) $tid->value;
            }
        }
        return null;
    }

    private function audit(string $method, string $path, int $status, int $startedMs, ?string $error): void
    {
        AuditLog::record(
            actor: 'worker',
            action: 'API_CALL',
            subjectType: 'stripe',
            subjectId: null,
            api: new ApiCallRecord(
                method: $method,
                url: 'https://api.stripe.com' . $path,
                status: $status,
                reqBody: null,
                resBody: null,
                durationMs: max(0, (int) (microtime(true) * 1000) - $startedMs),
            ),
            error: $error,
        );
    }
}
