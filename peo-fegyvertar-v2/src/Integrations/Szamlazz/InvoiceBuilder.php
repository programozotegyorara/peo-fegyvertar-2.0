<?php

declare(strict_types=1);

namespace Peoft\Integrations\Szamlazz;

use SzamlaAgent\Buyer;
use SzamlaAgent\Document\Invoice\Invoice;
use SzamlaAgent\Item\InvoiceItem;

defined('ABSPATH') || exit;

/**
 * Assembles a fully-populated SzamlaAgent Invoice from an InvoiceInput DTO.
 *
 * Ports the logic from 1.0 `szamlazz-integration.php` lines 86-213, but:
 *   - reads from a plain DTO instead of a PEOFT_PAYMENTS row
 *   - delegates VAT decision to VatResolver
 *   - embeds `STRIPE_INVOICE_ID: in_xxx` in the header comment so Számlázz
 *     records are retroactively searchable by their originating Stripe id
 *   - does NOT block, sleep, or retry in-process — handler + Backoff
 *     handle retries
 */
final class InvoiceBuilder
{
    public function __construct(
        private readonly VatResolver $vatResolver,
    ) {}

    public function build(InvoiceInput $input): Invoice
    {
        $invoice = new Invoice(Invoice::INVOICE_TYPE_E_INVOICE);
        $vat = $this->vatResolver->resolve($input->buyerCountry, $input->buyerVatId);

        // ---- Header ----
        $header = $invoice->getHeader();
        $header->setFulfillment($input->fulfillmentDate);
        $header->setPrefix($input->invoicePrefix);
        $header->setPaid(true);
        $header->setPaymentMethod(Invoice::PAYMENT_METHOD_BANKCARD);
        if ($vat->setEuVat) {
            $header->setEuVat(true);
        }
        $header->setComment($this->buildComment($input, $vat));

        // ---- Buyer ----
        $buyer = new Buyer(
            $input->buyerName,
            $input->buyerPostalCode,
            $input->buyerCity,
            $input->buyerAddress,
        );
        if (strtoupper($input->buyerCountry) !== 'HU' && $input->buyerCountryNameHu !== null && $input->buyerCountryNameHu !== '') {
            $buyer->setCountry($input->buyerCountryNameHu);
        }
        $buyer->setEmail($input->buyerEmail);
        $buyer->setSendEmail(false);
        $buyer->setTaxNumber($input->buyerVatId !== null && $input->buyerVatId !== '' ? $this->formatHungarianVat($input->buyerVatId) : '-');
        $invoice->setBuyer($buyer);

        // ---- Line item ----
        $grossMinor = $input->grossAmountMinor;
        $zeroDecimal = $input->isZeroDecimalCurrency();
        // Stripe always stores amounts as minor units; Számlázz expects display
        // units (HUF: whole forint, EUR: decimal euros). Convert here.
        $grossDisplay = $zeroDecimal ? $grossMinor : round($grossMinor / 100, 2);
        $netDisplay = $this->vatResolver->netFromGross($grossDisplay, $vat->rate, $zeroDecimal);
        $vatDisplay = $zeroDecimal
            ? (int) ($grossDisplay - $netDisplay)
            : round($grossDisplay - $netDisplay, 2);

        $item = new InvoiceItem($input->itemName, $netDisplay);
        $item->setVat($vat->vatName);
        $item->setNetPrice($netDisplay);
        $item->setVatAmount($vatDisplay);
        $item->setGrossAmount($grossDisplay);
        if ($input->itemPeriod !== '') {
            $item->setComment($input->itemPeriod);
        }
        $invoice->addItem($item);

        return $invoice;
    }

    /**
     * Compose the header comment string from VAT legal note + coupon mention +
     * Stripe correlation marker.
     */
    private function buildComment(InvoiceInput $input, VatDecision $vat): string
    {
        $parts = [];
        if ($vat->headerComment !== null && $vat->headerComment !== '') {
            $parts[] = $vat->headerComment;
        }
        if ($input->couponName !== null && $input->couponName !== '') {
            $parts[] = 'Felhasznált kedvezménykupon: ' . $input->couponName;
        }
        // STRIPE_INVOICE_ID: marker makes Számlázz records searchable for
        // reconciliation. Used by SzamlazzClient::findInvoiceByExternalRef
        // as a fallback oracle when the peoft_szamlazz_xref table doesn't
        // have a record (e.g. pre-cutover legacy invoices).
        $parts[] = 'STRIPE_INVOICE_ID: ' . $input->stripeInvoiceId;
        return implode(' | ', $parts);
    }

    /**
     * Port of 1.0's formatHungarianVAT helper. HU VAT numbers are 8-1-2
     * formatted (e.g. "12345678-1-23"). Strips whitespace/hyphens and
     * re-applies the canonical format; returns input as-is if it doesn't
     * look like a HU number.
     */
    private function formatHungarianVat(string $vatId): string
    {
        $digits = preg_replace('/\D/', '', $vatId) ?? '';
        if (strlen($digits) === 11) {
            return substr($digits, 0, 8) . '-' . substr($digits, 8, 1) . '-' . substr($digits, 9, 2);
        }
        return trim($vatId);
    }
}
