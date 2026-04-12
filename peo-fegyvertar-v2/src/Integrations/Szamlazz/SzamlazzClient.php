<?php

declare(strict_types=1);

namespace Peoft\Integrations\Szamlazz;

defined('ABSPATH') || exit;

/**
 * Számlázz client — interface only.
 *
 * Kernel binds one of:
 *   - `mock` / `demo` → SzamlazzClientMock (no API calls, audits stubbed ops)
 *   - `live`          → SzamlazzClientLive (SzamlaAgent SDK against real Számlázz)
 *
 * (In C4 we collapse the `demo` vs `mock` distinction: both resolve to Mock.
 * A real demo-endpoint Live mode using the SDK's `$testMode` flag is a Phase G
 * concern when we have a Számlázz demo account configured.)
 *
 * Handlers never instantiate the SDK directly — they go through this facade
 * so the mode switch is the only place Live-vs-Mock is decided.
 */
interface SzamlazzClient
{
    /**
     * Issue a new invoice at Számlázz. Returns the reference for storing
     * in peoft_szamlazz_xref and downloading the PDF later.
     *
     * Throws:
     *   - RetryableException on transient SDK/network failure
     *   - PoisonException on structural error (malformed input, auth failure)
     */
    public function issueInvoice(InvoiceInput $input): SzamlazzInvoiceRef;

    /**
     * Issue a storno (reverse) invoice against an existing Számlázz document.
     * Returns the storno's own document number.
     */
    public function issueStornoFor(string $originalDocumentNumber): SzamlazzInvoiceRef;

    /**
     * Fallback idempotency oracle for the (rare) case where peoft_szamlazz_xref
     * has no row but we want to know if an invoice with a given Stripe id
     * already exists at Számlázz. Searches Számlázz records for the
     * `STRIPE_INVOICE_ID: in_xxx` marker embedded in the header comment.
     *
     * Phase C4 implementations may return null (search unsupported) —
     * callers rely primarily on peoft_szamlazz_xref, this is belt+braces.
     */
    public function findInvoiceByExternalRef(string $stripeInvoiceId): ?SzamlazzInvoiceRef;
}
