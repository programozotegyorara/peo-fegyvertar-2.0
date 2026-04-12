<?php

declare(strict_types=1);

namespace Peoft\Integrations\Szamlazz;

use Peoft\Audit\ApiCallRecord;
use Peoft\Audit\AuditLog;

defined('ABSPATH') || exit;

/**
 * Mock Számlázz client. Used when `szamlazz.mode` is `mock` or `demo`.
 *
 * Does NOT touch Számlázz. Generates deterministic fake document numbers
 * (`MOCK-FEGY-{sha1-prefix}`) so the same input produces the same output
 * across repeated calls, writes `API_CALL` audit rows with the intended
 * operation, and returns stub `SzamlazzInvoiceRef` values.
 *
 * The PDF path is set to null — no file is actually written. Phase D's
 * reconciliation page must tolerate missing PDFs for mock-mode data.
 */
final class SzamlazzClientMock implements SzamlazzClient
{
    public function issueInvoice(InvoiceInput $input): SzamlazzInvoiceRef
    {
        $docNumber = $this->deterministicDocNumber($input->invoicePrefix, $input->stripeInvoiceId);
        AuditLog::record(
            actor: 'worker',
            action: 'API_CALL',
            subjectType: 'szamlazz',
            subjectId: $input->stripeInvoiceId,
            api: new ApiCallRecord(
                method: 'POST',
                url: 'szamlazz://mock/generateInvoice',
                status: 200,
                reqBody: json_encode([
                    'mock' => true,
                    'op' => 'issueInvoice',
                    'order_number' => $input->orderNumber,
                    'prefix' => $input->invoicePrefix,
                    'currency' => $input->currency,
                    'gross_minor' => $input->grossAmountMinor,
                    'buyer_email' => $input->buyerEmail,
                    'buyer_country' => $input->buyerCountry,
                    'stripe_invoice_id' => $input->stripeInvoiceId,
                ], JSON_UNESCAPED_UNICODE) ?: null,
                resBody: json_encode([
                    'document_number' => $docNumber,
                    'success' => true,
                ], JSON_UNESCAPED_UNICODE) ?: null,
                durationMs: 0,
            ),
        );
        return new SzamlazzInvoiceRef($docNumber, null);
    }

    public function issueStornoFor(string $originalDocumentNumber): SzamlazzInvoiceRef
    {
        $stornoNumber = 'MOCK-STORNO-' . substr(sha1($originalDocumentNumber), 0, 10);
        AuditLog::record(
            actor: 'worker',
            action: 'API_CALL',
            subjectType: 'szamlazz',
            subjectId: $originalDocumentNumber,
            api: new ApiCallRecord(
                method: 'POST',
                url: 'szamlazz://mock/generateReverseInvoice',
                status: 200,
                reqBody: json_encode([
                    'mock' => true,
                    'op' => 'issueStornoFor',
                    'original_document_number' => $originalDocumentNumber,
                ], JSON_UNESCAPED_UNICODE) ?: null,
                resBody: json_encode([
                    'storno_document_number' => $stornoNumber,
                    'success' => true,
                ], JSON_UNESCAPED_UNICODE) ?: null,
                durationMs: 0,
            ),
        );
        return new SzamlazzInvoiceRef($stornoNumber, null);
    }

    public function findInvoiceByExternalRef(string $stripeInvoiceId): ?SzamlazzInvoiceRef
    {
        return null;
    }

    private function deterministicDocNumber(string $prefix, string $stripeInvoiceId): string
    {
        $safePrefix = preg_replace('/[^A-Z0-9]/i', '', $prefix) ?: 'FEGY';
        return 'MOCK-' . strtoupper($safePrefix) . '-' . substr(sha1($stripeInvoiceId), 0, 10);
    }
}
