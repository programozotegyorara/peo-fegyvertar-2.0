<?php

declare(strict_types=1);

namespace Peoft\Integrations\Stripe;

defined('ABSPATH') || exit;

/**
 * Minimal view of a Stripe card — the fields we actually surface in
 * transactional emails (sikeres_rendeles, uj_bankkartya_hozzaadva) and
 * in the Phase D reconciliation page.
 *
 * Stripe returns richer data (fingerprint, funding type, issuer country,
 * 3DS status, etc.) but we intentionally don't mirror those unless a
 * specific template or UI page needs them.
 */
final class StripeCardDetails
{
    public function __construct(
        public readonly string $brand,      // 'visa' | 'mastercard' | 'amex' | ...
        public readonly string $last4,      // '4242'
        public readonly int $expMonth,      // 1-12
        public readonly int $expYear,       // 4-digit
        public readonly ?string $paymentMethodId = null,
    ) {}

    public function displayBrand(): string
    {
        return match (strtolower($this->brand)) {
            'visa'            => 'Visa',
            'mastercard'      => 'Mastercard',
            'amex', 'american_express' => 'American Express',
            'discover'        => 'Discover',
            'jcb'             => 'JCB',
            'diners', 'diners_club'    => 'Diners Club',
            'unionpay'        => 'UnionPay',
            default           => ucfirst($this->brand),
        };
    }
}
