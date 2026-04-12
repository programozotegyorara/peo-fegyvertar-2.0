<?php

declare(strict_types=1);

namespace Peoft\Integrations\Szamlazz;

defined('ABSPATH') || exit;

/**
 * Result of VatResolver::resolve(). Immutable.
 *
 * `rate` is the percent (integer, 0 or 27) actually applied to line items.
 * `vatName` is the Számlázz-side code for the VAT category:
 *   - "27"      → standard Hungarian rate
 *   - "EUFAD37" → EU reverse-charge B2B
 *   - "HO"      → outside territorial scope (non-EU B2B services)
 *
 * `setEuVat` flips the invoice-header flag that tells Számlázz to treat the
 * transaction as an EU-scope event for reporting.
 *
 * `headerComment` is the legal note prepended to the invoice's comment field.
 * Kept verbatim in Hungarian from 1.0 so tax-authority readers see the same
 * wording they've seen for years.
 */
final class VatDecision
{
    public function __construct(
        public readonly int $rate,
        public readonly string $vatName,
        public readonly ?string $headerComment,
        public readonly bool $setEuVat,
        public readonly string $decisionReason,
    ) {}
}
