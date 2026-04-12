<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Handlers;

use Peoft\Core\Db\CounterRepository;
use Peoft\Integrations\Szamlazz\InvoiceInput;
use Peoft\Integrations\Szamlazz\SzamlazzClient;
use Peoft\Integrations\Szamlazz\XrefRepository;
use Peoft\Orchestrator\Queue\Task;
use Peoft\Orchestrator\Worker\PoisonException;

defined('ABSPATH') || exit;

/**
 * `szamlazz.issue_invoice` — issue a Számlázz invoice for a Stripe invoice
 * that just succeeded payment.
 *
 * Payload shape (set by StripeEventMap):
 *   {
 *     "stripe_invoice_id": "in_...",
 *     "currency": "HUF",
 *     "gross_amount_minor": 500000,
 *     "buyer": { "name", "email", "country", "postal_code", "city",
 *                "address_line_1", "address_line_2", "vat_id" },
 *     "item": { "name", "period" },
 *     "coupon_name": null
 *   }
 *
 * Idempotency: `peoft_szamlazz_xref` is the hard oracle. `guard()` queries
 * XrefRepository::findInvoice($stripeInvoiceId) — if a row exists, the task
 * short-circuits to `done` without making any Számlázz call. The xref row
 * is written in `execute()` immediately after a successful `issueInvoice()`.
 *
 * Order number allocation: on first execute, the handler calls
 * CounterRepository::allocate('order') to atomically grab the next
 * sequence value from the imported-from-1.0 counter (seeded at 16955).
 * The allocated number is embedded in the header comment and displayed
 * to Hungarian admins as the rendelésszám.
 */
final class IssueSzamlazzInvoiceHandler implements TaskHandler
{
    public function __construct(
        private readonly SzamlazzClient $client,
        private readonly XrefRepository $xref,
        private readonly CounterRepository $counters,
        private readonly string $invoicePrefix,
    ) {}

    public static function type(): string
    {
        return 'szamlazz.issue_invoice';
    }

    public function loadContext(Task $task): TaskContext
    {
        $payload = $task->payload ?? [];
        $stripeInvoiceId = (string) ($payload['stripe_invoice_id'] ?? '');
        if ($stripeInvoiceId === '') {
            throw new PoisonException("Task #{$task->id} szamlazz.issue_invoice: missing stripe_invoice_id");
        }
        $buyer = is_array($payload['buyer'] ?? null) ? $payload['buyer'] : [];
        $item = is_array($payload['item'] ?? null) ? $payload['item'] : [];

        return new TaskContext($task, [
            'stripe_invoice_id'  => $stripeInvoiceId,
            'currency'           => (string) ($payload['currency'] ?? 'HUF'),
            'gross_amount_minor' => (int) ($payload['gross_amount_minor'] ?? 0),
            'coupon_name'        => isset($payload['coupon_name']) ? (string) $payload['coupon_name'] : null,
            'buyer'              => [
                'name'           => (string) ($buyer['name'] ?? ''),
                'email'          => (string) ($buyer['email'] ?? ''),
                'country'        => (string) ($buyer['country'] ?? 'HU'),
                'postal_code'    => (string) ($buyer['postal_code'] ?? ''),
                'city'           => (string) ($buyer['city'] ?? ''),
                'address_line_1' => (string) ($buyer['address_line_1'] ?? ''),
                'address_line_2' => (string) ($buyer['address_line_2'] ?? ''),
                'vat_id'         => isset($buyer['vat_id']) ? (string) $buyer['vat_id'] : null,
                'country_name_hu'=> isset($buyer['country_name_hu']) ? (string) $buyer['country_name_hu'] : null,
            ],
            'item' => [
                'name'   => (string) ($item['name'] ?? 'Online oktatás'),
                'period' => (string) ($item['period'] ?? ''),
            ],
        ]);
    }

    public function guard(TaskContext $context): bool
    {
        $stripeInvoiceId = (string) $context->get('stripe_invoice_id');
        $existing = $this->xref->findInvoice($stripeInvoiceId);
        return $existing !== null;
    }

    public function execute(TaskContext $context): void
    {
        $stripeInvoiceId = (string) $context->get('stripe_invoice_id');
        $buyer = (array) $context->get('buyer');
        $item = (array) $context->get('item');

        // Allocate the next order number. This is the one number that
        // customers see on their Számlázz PDF as "rendelésszám".
        $orderNumberInt = $this->counters->allocate('order');
        $orderNumber = (string) $orderNumberInt;

        $input = new InvoiceInput(
            orderNumber:        $orderNumber,
            invoicePrefix:      $this->invoicePrefix,
            fulfillmentDate:    gmdate('Y-m-d'),
            currency:           (string) $context->get('currency'),
            grossAmountMinor:   (int) $context->get('gross_amount_minor'),
            buyerName:          (string) ($buyer['name'] ?: $buyer['email']),
            buyerEmail:         (string) $buyer['email'],
            buyerCountry:       (string) $buyer['country'],
            buyerPostalCode:    (string) $buyer['postal_code'],
            buyerCity:          (string) $buyer['city'],
            buyerAddress:       trim(((string) $buyer['address_line_1']) . ' ' . ((string) $buyer['address_line_2'])),
            buyerVatId:         $buyer['vat_id'],
            buyerCountryNameHu: $buyer['country_name_hu'] ?? null,
            itemName:           (string) $item['name'],
            itemPeriod:         (string) $item['period'],
            couponName:         $context->get('coupon_name'),
            stripeInvoiceId:    $stripeInvoiceId,
        );

        $ref = $this->client->issueInvoice($input);
        $this->xref->recordInvoice($stripeInvoiceId, $ref->documentNumber);
    }
}
