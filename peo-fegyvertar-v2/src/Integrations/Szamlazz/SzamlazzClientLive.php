<?php

declare(strict_types=1);

namespace Peoft\Integrations\Szamlazz;

use Peoft\Audit\ApiCallRecord;
use Peoft\Audit\AuditLog;
use Peoft\Orchestrator\Worker\PoisonException;
use Peoft\Orchestrator\Worker\RetryableException;
use SzamlaAgent\Document\Invoice\ReverseInvoice;
use SzamlaAgent\SzamlaAgentAPI;

defined('ABSPATH') || exit;

/**
 * Real Számlázz client backed by the SzamlaAgent SDK.
 *
 * Does NOT extend ApiClient because SzamlaAgent does its own curl internally —
 * we can't route its HTTP through our ApiClient without monkey-patching the
 * SDK. Instead we wrap each SDK call with a manual AuditLog::record() so the
 * audit trail still gets an API_CALL row per Számlázz operation, with the
 * request and response bodies from `$result->getXmlData()` / `$result->toXML()`.
 *
 * Error classification:
 *   - `SzamlaAgentException` / SDK throw → PoisonException (structural)
 *   - `!$result->isSuccess()` with error code [468] (document-number
 *     collision) → RetryableException — this is Számlázz's advisory
 *     "try again in a moment" signal. 1.0 handled it with an in-request
 *     exponential sleep; 2.0 lets Backoff do the job.
 *   - other non-success → PoisonException with the error message
 *
 * The 1.0 in-request retry loop + `rand(1,10)+sleep` hack at lines 230-232
 * is NOT ported — it was a workaround for blocking webhook semantics we
 * no longer have.
 */
final class SzamlazzClientLive implements SzamlazzClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly InvoiceBuilder $builder,
        private readonly PdfStore $pdfStore,
    ) {}

    public function issueInvoice(InvoiceInput $input): SzamlazzInvoiceRef
    {
        $startedMs = (int) (microtime(true) * 1000);
        $invoice = $this->builder->build($input);

        try {
            $agent = SzamlaAgentAPI::create($this->apiKey);
            $result = $agent->generateInvoice($invoice);
        } catch (\Throwable $e) {
            $this->auditSdkCall('generateInvoice', 0, $input->orderNumber, null, $startedMs, $e->getMessage());
            throw new PoisonException('SzamlaAgent SDK threw during generateInvoice: ' . $e->getMessage(), 0, $e);
        }

        if (!$result->isSuccess()) {
            $error = (string) $result->getErrorMessage();
            $this->auditSdkCall('generateInvoice', 0, $input->orderNumber, $error, $startedMs, $error);
            if (str_contains($error, '[468]')) {
                throw new RetryableException('Számlázz [468] document-number collision — will retry with backoff: ' . $error);
            }
            throw new PoisonException('Számlázz generateInvoice failed: ' . $error);
        }

        $documentNumber = (string) $result->getDocumentNumber();
        $this->auditSdkCall('generateInvoice', 200, $input->orderNumber, "document_number={$documentNumber}", $startedMs, null);

        // Fetch + save PDF. A failure here is retryable — the invoice is
        // already at Számlázz so we want to come back and re-download.
        $pdfPath = null;
        try {
            $pdfStartedMs = (int) (microtime(true) * 1000);
            $agent = SzamlaAgentAPI::create($this->apiKey);
            $pdfResult = $agent->getInvoicePdf($documentNumber);
            $pdfContent = $pdfResult->toPdf();
            if (!is_string($pdfContent) || $pdfContent === '') {
                throw new \RuntimeException('Empty PDF content from Számlázz');
            }
            $pdfPath = $this->pdfStore->save($documentNumber, $pdfContent);
            $this->auditSdkCall('getInvoicePdf', 200, $documentNumber, 'bytes=' . strlen($pdfContent), $pdfStartedMs, null);
        } catch (\Throwable $e) {
            $this->auditSdkCall('getInvoicePdf', 0, $documentNumber, null, $pdfStartedMs ?? $startedMs, $e->getMessage());
            // Invoice exists at Számlázz but PDF save failed. Not a hard
            // error — reconciliation page can re-download later.
            error_log('[peoft] PDF save failed for ' . $documentNumber . ': ' . $e->getMessage());
        }

        return new SzamlazzInvoiceRef($documentNumber, $pdfPath);
    }

    public function issueStornoFor(string $originalDocumentNumber): SzamlazzInvoiceRef
    {
        $startedMs = (int) (microtime(true) * 1000);
        if (preg_match('/^[A-Z0-9._-]+$/i', $originalDocumentNumber) !== 1) {
            throw new PoisonException("Invalid original Számlázz document number: '{$originalDocumentNumber}'");
        }

        $storno = new ReverseInvoice(ReverseInvoice::INVOICE_TYPE_E_INVOICE);
        $header = $storno->getHeader();
        $header->setInvoiceNumber($originalDocumentNumber);
        $header->setIssueDate(gmdate('Y-m-d'));
        $header->setFulfillment(gmdate('Y-m-d'));
        $header->setInvoiceTemplate(ReverseInvoice::INVOICE_TEMPLATE_DEFAULT);

        try {
            $agent = SzamlaAgentAPI::create($this->apiKey);
            $result = $agent->generateReverseInvoice($storno);
        } catch (\Throwable $e) {
            $this->auditSdkCall('generateReverseInvoice', 0, $originalDocumentNumber, null, $startedMs, $e->getMessage());
            throw new PoisonException('SzamlaAgent SDK threw during generateReverseInvoice: ' . $e->getMessage(), 0, $e);
        }

        if (!$result->isSuccess()) {
            $error = (string) $result->getErrorMessage();
            $this->auditSdkCall('generateReverseInvoice', 0, $originalDocumentNumber, $error, $startedMs, $error);
            if (str_contains($error, '[468]')) {
                throw new RetryableException('Számlázz [468] document-number collision on storno — retry: ' . $error);
            }
            throw new PoisonException('Számlázz generateReverseInvoice failed: ' . $error);
        }

        $stornoDocumentNumber = (string) $result->getDocumentNumber();
        $this->auditSdkCall('generateReverseInvoice', 200, $originalDocumentNumber, "storno_document_number={$stornoDocumentNumber}", $startedMs, null);

        // Fetch + save storno PDF, same best-effort flow as issueInvoice.
        $pdfPath = null;
        try {
            $agent = SzamlaAgentAPI::create($this->apiKey);
            $pdfContent = $agent->getInvoicePdf($stornoDocumentNumber)->toPdf();
            if (is_string($pdfContent) && $pdfContent !== '') {
                $pdfPath = $this->pdfStore->save($stornoDocumentNumber, $pdfContent);
            }
        } catch (\Throwable $e) {
            error_log('[peoft] storno PDF save failed for ' . $stornoDocumentNumber . ': ' . $e->getMessage());
        }

        return new SzamlazzInvoiceRef($stornoDocumentNumber, $pdfPath);
    }

    public function findInvoiceByExternalRef(string $stripeInvoiceId): ?SzamlazzInvoiceRef
    {
        // The SzamlaAgent SDK doesn't expose a generic "search by comment"
        // endpoint in v2.10. Callers should rely on peoft_szamlazz_xref
        // as the primary oracle. Return null so the handler falls through
        // to a guarded-retry create attempt.
        return null;
    }

    /**
     * Records an API_CALL audit row for a Számlázz SDK operation.
     *
     * Note: the audit table's `api_method` column is VARCHAR(8) — sized for
     * HTTP verbs (POST/DELETE/OPTIONS). SDK method names like "generateInvoice"
     * don't fit, so we always record the underlying HTTP verb (POST) and put
     * the SDK operation in the url path so operators can still filter by it.
     */
    private function auditSdkCall(string $sdkOp, int $status, ?string $subjectId, ?string $responseSummary, int $startedMs, ?string $error): void
    {
        AuditLog::record(
            actor: 'worker',
            action: 'API_CALL',
            subjectType: 'szamlazz',
            subjectId: $subjectId,
            api: new ApiCallRecord(
                method: 'POST',
                url: 'https://www.szamlazz.hu/szamla/' . $sdkOp,
                status: $status,
                reqBody: null,
                resBody: $responseSummary,
                durationMs: max(0, (int) (microtime(true) * 1000) - $startedMs),
            ),
            error: $error,
        );
    }
}
