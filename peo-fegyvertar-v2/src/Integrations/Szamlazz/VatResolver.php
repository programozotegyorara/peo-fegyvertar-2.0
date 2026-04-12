<?php

declare(strict_types=1);

namespace Peoft\Integrations\Szamlazz;

defined('ABSPATH') || exit;

/**
 * Decides what Hungarian VAT rate + code + header comment to apply to an
 * invoice based on the buyer's country and whether they provided a VAT number.
 *
 * Ported from 1.0 szamlazz-integration.php lines 89-131. The Hungarian legal
 * references in the header comments are kept verbatim so the output matches
 * what the tax authority has seen in the past.
 *
 * Decision matrix:
 *
 *   | buyer country | has vat_id | rate | vat_name | setEuVat | legal note |
 *   |---------------|------------|------|----------|----------|-----------|
 *   | HU            | *          | 27%  | "27"     | false    | —          |
 *   | EU            | yes        | 0%   | EUFAD37  | true     | Áfa tv. 37. § — reverse charge |
 *   | EU            | no         | 27%  | "27"     | false    | — (distance selling) |
 *   | non-EU        | yes        | 0%   | HO       | true     | Áfa tv. 37. § — outside territorial scope |
 *   | non-EU        | no         | 27%  | "27"     | false    | — (private export, default rate) |
 *
 * `setEuVat` flips a header flag in the SzamlaAgent SDK that tells Számlázz
 * the invoice is an EU-scope transaction requiring special reporting.
 */
final class VatResolver
{
    /** Member-state ISO-2 codes as of 2026. Includes HU for table consistency. */
    public const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
        'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    /**
     * @param string $countryCode ISO-2 ('HU', 'DE', 'US', ...). Case-insensitive.
     * @param string|null $vatId buyer's VAT number (null/empty = private buyer)
     */
    public function resolve(string $countryCode, ?string $vatId = null): VatDecision
    {
        $country = strtoupper(trim($countryCode));
        $vatId = $vatId !== null ? trim($vatId) : '';
        $isCompany = $vatId !== '';
        $isEu = in_array($country, self::EU_COUNTRIES, true);

        // HU — domestic, full 27% always (private or B2B).
        if ($country === 'HU') {
            return new VatDecision(
                rate: 27,
                vatName: '27',
                headerComment: null,
                setEuVat: false,
                decisionReason: 'hu_domestic',
            );
        }

        // EU + VAT number → reverse-charge B2B, 0% (EUFAD37).
        if ($isEu && $isCompany) {
            return new VatDecision(
                rate: 0,
                vatName: 'EUFAD37',
                headerComment: 'Az Áfa tv. 37. § alapján mentes az adó alól az, az áfa megfizetésére a vevő kötelezett (fordított adózás)',
                setEuVat: true,
                decisionReason: 'eu_b2b_reverse_charge',
            );
        }

        // EU + private → distance selling, HU 27% applies (simplified;
        // the real threshold-based logic is out of scope here).
        if ($isEu && !$isCompany) {
            return new VatDecision(
                rate: 27,
                vatName: '27',
                headerComment: null,
                setEuVat: false,
                decisionReason: 'eu_private_distance_selling',
            );
        }

        // Non-EU + VAT number → outside territorial scope, 0% (HO).
        if (!$isEu && $isCompany) {
            return new VatDecision(
                rate: 0,
                vatName: 'HO',
                headerComment: 'Az Áfa tv. 37. § alapján az Áfa tv. területi hatályán kívüli szolgáltatás nyújtás',
                setEuVat: true,
                decisionReason: 'non_eu_b2b_outside_scope',
            );
        }

        // Non-EU + private → default 27% (conservative).
        return new VatDecision(
            rate: 27,
            vatName: '27',
            headerComment: null,
            setEuVat: false,
            decisionReason: 'non_eu_private_default',
        );
    }

    /**
     * Compute net amount from gross by reversing the VAT rate.
     * E.g. gross=500000, rate=27 → net=round(500000/1.27, 0) = 393701 (HUF, whole fillér).
     * For non-HUF currencies round to 2 decimals.
     */
    public function netFromGross(int|float $grossMinorUnits, int $vatRatePercent, bool $zeroDecimal = true): int|float
    {
        if ($vatRatePercent === 0) {
            return $grossMinorUnits;
        }
        $net = $grossMinorUnits / (1 + ($vatRatePercent / 100));
        return $zeroDecimal ? (int) round($net) : round($net, 2);
    }
}
