<?php

declare(strict_types=1);

namespace Peoft\Integrations\Stripe;

defined('ABSPATH') || exit;

/**
 * Thin wrapper around StripeClient::fetchCustomerBundle that task handlers
 * use to enrich their TaskContext with fresh customer data from Stripe.
 *
 * **Why this exists as a separate class** (rather than handlers calling
 * `$stripe->fetchCustomerBundle` directly):
 *   - Handlers can be tested with a fake context loader without pulling in
 *     the whole Stripe SDK
 *   - Future enrichment sources (e.g. Számlázz lookup to add invoice_number
 *     to the bundle) can be added here without every handler knowing
 *   - Phase D's reconciliation page uses the same entry point to fetch
 *     "everything we know about this customer"
 */
final class CustomerContextLoader
{
    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    /**
     * Best-effort fetch. Returns null if the customer id is empty or if
     * the underlying Stripe call throws a non-retryable error (handler
     * can fall back to whatever data is already in the task payload).
     *
     * Note: RetryableException from StripeClient is NOT swallowed here —
     * it propagates so the Dispatcher can apply backoff. Only structural
     * failures (PoisonException, unknown errors) are caught and reported
     * as null so handlers can proceed with degraded data.
     */
    public function loadOrNull(?string $customerId): ?CustomerBundle
    {
        $customerId = (string) $customerId;
        if ($customerId === '') {
            return null;
        }
        try {
            return $this->stripe->fetchCustomerBundle($customerId);
        } catch (\Peoft\Orchestrator\Worker\RetryableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[peoft] CustomerContextLoader::loadOrNull failed for ' . $customerId . ': ' . $e->getMessage());
            return null;
        }
    }
}
