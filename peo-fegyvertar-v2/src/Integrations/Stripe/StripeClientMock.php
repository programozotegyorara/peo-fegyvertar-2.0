<?php

declare(strict_types=1);

namespace Peoft\Integrations\Stripe;

use Peoft\Audit\ApiCallRecord;
use Peoft\Audit\AuditLog;

defined('ABSPATH') || exit;

/**
 * Mock StripeClient. Used when `stripe.mode === 'mock'`.
 *
 * Returns deterministic stub data keyed off the customer id so repeated
 * calls in the same test produce identical bundles. The stub fills enough
 * fields that SendTransactionalEmailHandler can show meaningful
 * substitutions in the rendered email (address, card details, etc.).
 *
 * DEV uses mock by default (see ImportFromLegacyDbCommand safety net) so
 * smoke tests with fake fixture IDs don't need real Stripe objects. To
 * exercise the real Live client, flip `stripe.mode=live` in
 * /wp-content/peoft-env.php or via the Config editor.
 */
final class StripeClientMock implements StripeClient
{
    public function fetchCustomerBundle(string $customerId): CustomerBundle
    {
        $this->audit('GET', "/v1/customers/{$customerId}", 200);

        return new CustomerBundle(
            customerId: $customerId,
            email:      $this->deterministicEmail($customerId),
            name:       'Mock Buyer ' . substr(sha1($customerId), 0, 6),
            address:    new StripeAddress(
                country:    'HU',
                postalCode: '1051',
                city:       'Budapest',
                line1:      'Mock utca 1.',
                line2:      '',
                state:      '',
            ),
            defaultCard: new StripeCardDetails(
                brand:           'visa',
                last4:           '4242',
                expMonth:        12,
                expYear:         (int) gmdate('Y') + 2,
                paymentMethodId: 'pm_mock_' . substr(sha1($customerId), 0, 8),
            ),
            defaultTaxId:         null,
            customerPortalUrl:    null,
            activeSubscriptionId: 'sub_mock_' . substr(sha1($customerId), 0, 8),
            activePriceId:        'price_mock_' . substr(sha1($customerId), 0, 8),
        );
    }

    public function fetchInvoiceRaw(string $invoiceId): ?array
    {
        $this->audit('GET', "/v1/invoices/{$invoiceId}", 200);
        return [
            'id'              => $invoiceId,
            'object'          => 'invoice',
            'mock'            => true,
            'amount_paid'     => 500000,
            'currency'        => 'huf',
            'customer'        => 'cus_mock_' . substr(sha1($invoiceId), 0, 8),
            'customer_email'  => 'mock@example.test',
            'status'          => 'paid',
        ];
    }

    public function fetchCheckoutSessionRaw(string $sessionId): ?array
    {
        $this->audit('GET', "/v1/checkout/sessions/{$sessionId}", 200);
        return [
            'id'              => $sessionId,
            'object'          => 'checkout.session',
            'mock'            => true,
            'customer'        => 'cus_mock_' . substr(sha1($sessionId), 0, 8),
            'subscription'    => 'sub_mock_' . substr(sha1($sessionId), 0, 8),
            'payment_status'  => 'paid',
            'mode'            => 'subscription',
        ];
    }

    private function deterministicEmail(string $customerId): string
    {
        return 'mock-' . substr(sha1($customerId), 0, 8) . '@example.test';
    }

    private function audit(string $method, string $path, int $status): void
    {
        AuditLog::record(
            actor: 'worker',
            action: 'API_CALL',
            subjectType: 'stripe',
            subjectId: null,
            api: new ApiCallRecord(
                method: $method,
                url: 'stripe://mock' . $path,
                status: $status,
                reqBody: json_encode(['mock' => true], JSON_UNESCAPED_UNICODE) ?: null,
                resBody: null,
                durationMs: 0,
            ),
        );
    }
}
