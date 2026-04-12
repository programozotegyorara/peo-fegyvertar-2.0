<?php

declare(strict_types=1);

namespace Peoft\Integrations\Stripe;

defined('ABSPATH') || exit;

/**
 * Stripe read-side API — interface only.
 *
 * Kernel binds one of:
 *   - `mock` → StripeClientMock (deterministic stub bundle, no network)
 *   - `live` → StripeClientLive (real \Stripe\StripeClient calls)
 *
 * Intentionally read-only for C5: fetch customer, invoice, subscription,
 * checkout session. Write operations (updateCustomerDefaultPaymentMethod,
 * createSubscriptionForPaidTrial, createBillingPortalSession) listed in
 * plan §6 are out of scope for C5 smoke testing and will be added when a
 * handler actually needs them.
 *
 * Note: webhook signature verification lives in StripeWebhookVerifier,
 * not here. Verification uses only `stripe.webhook_secret` and is
 * unaffected by the mode flag.
 */
interface StripeClient
{
    public function fetchCustomerBundle(string $customerId): CustomerBundle;

    /**
     * Lightweight fetch — returns the raw Stripe Invoice-shaped array or null
     * if the invoice doesn't exist. Used by handlers that need invoice-only
     * details without the full customer bundle.
     *
     * @return array<string,mixed>|null
     */
    public function fetchInvoiceRaw(string $invoiceId): ?array;

    /**
     * Lightweight fetch for a Stripe checkout session.
     *
     * @return array<string,mixed>|null
     */
    public function fetchCheckoutSessionRaw(string $sessionId): ?array;
}
