<?php

declare(strict_types=1);

namespace Peoft\Integrations\Szamlazz;

defined('ABSPATH') || exit;

/**
 * All the inputs needed to build one Számlázz invoice, as a flat DTO.
 *
 * StripeEventMap closures + the IssueSzamlazzInvoiceHandler populate this from
 * the Stripe invoice body (amounts, currency, customer address) and from the
 * env config (szamlazz.prefix). Phase C5 will fetch richer buyer data via
 * Stripe's customer-bundle API.
 *
 * Amounts are integer **minor units** (HUF has zero decimals so minor = whole Ft,
 * EUR has 2 decimals so minor = cents). Internally the InvoiceBuilder divides
 * by 100 if the currency is non-HUF.
 */
final class InvoiceInput
{
    public function __construct(
        public readonly string $orderNumber,
        public readonly string $invoicePrefix,
        public readonly string $fulfillmentDate, // 'Y-m-d'
        public readonly string $currency,        // 'HUF' | 'EUR' | ...
        public readonly int $grossAmountMinor,
        // Buyer
        public readonly string $buyerName,
        public readonly string $buyerEmail,
        public readonly string $buyerCountry,    // ISO-2
        public readonly string $buyerPostalCode,
        public readonly string $buyerCity,
        public readonly string $buyerAddress,
        public readonly ?string $buyerVatId,
        public readonly ?string $buyerCountryNameHu,  // for non-HU display
        // Line item
        public readonly string $itemName,        // e.g. "Online oktatás"
        public readonly string $itemPeriod,      // e.g. "Havi előfizetés"
        public readonly ?string $couponName,     // appended to header comment if present
        // Idempotency + traceability
        public readonly string $stripeInvoiceId, // embedded in header comment so Számlázz records are searchable
    ) {}

    public function isZeroDecimalCurrency(): bool
    {
        return in_array(strtoupper($this->currency), ['HUF', 'JPY'], true);
    }
}
