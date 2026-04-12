<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Handlers;

use Peoft\Integrations\Szamlazz\SzamlazzClient;
use Peoft\Integrations\Szamlazz\XrefRepository;
use Peoft\Orchestrator\Queue\Task;
use Peoft\Orchestrator\Worker\PoisonException;
use Peoft\Orchestrator\Worker\RetryableException;

defined('ABSPATH') || exit;

/**
 * `szamlazz.issue_storno` — issue a reverse (storno) invoice when Stripe
 * reports a refund.
 *
 * Payload shape (set by StripeEventMap):
 *   {
 *     "stripe_charge_id": "ch_...",
 *     "stripe_invoice_id": "in_..."   // the invoice whose charge is being refunded
 *   }
 *
 * Idempotency: two-layer guard.
 *   1. `XrefRepository::findStorno($chargeId)` — if the storno was already
 *      issued for this refund, skip.
 *   2. After loadContext, we need the ORIGINAL invoice's Számlázz document
 *      number. This comes from `XrefRepository::findInvoice($stripeInvoiceId)`.
 *      If the original xref is missing, the storno can't proceed yet — this
 *      is a retryable state (the invoice handler may be running concurrently
 *      or earlier in the task queue).
 *
 * The storno's xref row records `linked_original` so the admin
 * reconciliation page can display the original/storno pair side-by-side.
 */
final class IssueStornoInvoiceHandler implements TaskHandler
{
    public function __construct(
        private readonly SzamlazzClient $client,
        private readonly XrefRepository $xref,
    ) {}

    public static function type(): string
    {
        return 'szamlazz.issue_storno';
    }

    public function loadContext(Task $task): TaskContext
    {
        $payload = $task->payload ?? [];
        $chargeId = (string) ($payload['stripe_charge_id'] ?? '');
        $invoiceId = (string) ($payload['stripe_invoice_id'] ?? '');
        if ($chargeId === '') {
            throw new PoisonException("Task #{$task->id} szamlazz.issue_storno: missing stripe_charge_id");
        }
        if ($invoiceId === '') {
            throw new PoisonException("Task #{$task->id} szamlazz.issue_storno: missing stripe_invoice_id — can't resolve original Számlázz document");
        }

        $originalXref = $this->xref->findInvoice($invoiceId);
        if ($originalXref === null) {
            // The original invoice hasn't been recorded yet. Could be a
            // race (invoice.payment_succeeded task still in-flight), could
            // be a pre-cutover legacy invoice we never xref'd. Retry once
            // — if the original task finishes in the next tick, this one
            // succeeds. After MAX_ATTEMPTS the Dispatcher dead-letters it.
            throw new RetryableException(
                "No peoft_szamlazz_xref row for stripe_invoice_id={$invoiceId}; original Számlázz document unknown"
            );
        }

        return new TaskContext($task, [
            'stripe_charge_id'         => $chargeId,
            'stripe_invoice_id'        => $invoiceId,
            'original_document_number' => $originalXref->szamlazzDocumentNumber,
        ]);
    }

    public function guard(TaskContext $context): bool
    {
        $existing = $this->xref->findStorno((string) $context->get('stripe_charge_id'));
        return $existing !== null;
    }

    public function execute(TaskContext $context): void
    {
        $chargeId = (string) $context->get('stripe_charge_id');
        $originalDocumentNumber = (string) $context->get('original_document_number');

        $ref = $this->client->issueStornoFor($originalDocumentNumber);
        $this->xref->recordStorno($chargeId, $ref->documentNumber, $originalDocumentNumber);
    }
}
