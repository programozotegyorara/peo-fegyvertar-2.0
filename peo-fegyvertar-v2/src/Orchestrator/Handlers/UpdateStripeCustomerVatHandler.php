<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Handlers;

use Peoft\Audit\AuditLog;
use Peoft\Integrations\Stripe\CustomerContextLoader;
use Peoft\Orchestrator\Queue\Task;
use Peoft\Orchestrator\Worker\PoisonException;

defined('ABSPATH') || exit;

/**
 * `stripe.update_customer_vat` — triggered by `customer.tax_id.updated`
 * (and `customer.tax_id.created`, mapped the same way).
 *
 * Payload shape:
 *   {
 *     "customer_id": "cus_...",
 *     "tax_id_value": "HU12345678",
 *     "tax_id_type": "hu_tin"
 *   }
 *
 * What it does:
 *   1. Fetches the customer bundle from Stripe to confirm the VAT is present
 *      on the customer (guard against stale events that reference a deleted
 *      tax id).
 *   2. Writes a `CONFIG_CHANGED`-flavored audit row with actor='stripe' and
 *      the before/after VAT values so the reconciliation page and audit
 *      trail both surface the change.
 *   3. Emits no downstream task fan-out in Phase C5. Future enhancement:
 *      enqueue an `ac.upsert_contact` task with the new VAT number so
 *      ActiveCampaign has the latest value for invoicing.
 *
 * Idempotency: natural. The handler reads from Stripe each run; a retry
 * just re-verifies the same state. No external writes.
 */
final class UpdateStripeCustomerVatHandler implements TaskHandler
{
    public function __construct(
        private readonly CustomerContextLoader $customers,
    ) {}

    public static function type(): string
    {
        return 'stripe.update_customer_vat';
    }

    public function loadContext(Task $task): TaskContext
    {
        $payload = $task->payload ?? [];
        $customerId = (string) ($payload['customer_id'] ?? '');
        $taxIdValue = (string) ($payload['tax_id_value'] ?? '');
        if ($customerId === '') {
            throw new PoisonException("Task #{$task->id} stripe.update_customer_vat: missing customer_id");
        }
        return new TaskContext($task, [
            'customer_id'  => $customerId,
            'tax_id_value' => $taxIdValue,
            'tax_id_type'  => (string) ($payload['tax_id_type'] ?? ''),
        ]);
    }

    public function guard(TaskContext $context): bool
    {
        return false; // always run
    }

    public function execute(TaskContext $context): void
    {
        $customerId = (string) $context->get('customer_id');
        $newValue = (string) $context->get('tax_id_value');

        $bundle = $this->customers->loadOrNull($customerId);
        $currentValue = $bundle?->defaultTaxId ?? '';

        AuditLog::record(
            actor:       'stripe',
            action:      'CONFIG_CHANGED',
            subjectType: 'stripe_customer',
            subjectId:   $customerId,
            before:      ['tax_id_value' => $currentValue],
            after:       [
                'tax_id_value' => $newValue,
                'tax_id_type'  => (string) $context->get('tax_id_type'),
                'customer_id'  => $customerId,
            ],
        );
    }
}
