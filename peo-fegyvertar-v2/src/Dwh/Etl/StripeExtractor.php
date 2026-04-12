<?php

declare(strict_types=1);

namespace Peoft\Dwh\Etl;

use Peoft\Core\Config\Config;
use Peoft\Core\Db\Connection;
use Stripe\StripeClient;

defined('ABSPATH') || exit;

/**
 * Pulls subscriptions, invoices, and refunds from the Stripe API and
 * writes them into `peoft_dwh_subscription_data`, `peoft_dwh_invoices_data`,
 * `peoft_dwh_refunds_data`.
 *
 * Creates its OWN StripeClient from `stripe.secret_key` config — does NOT
 * reuse the mode-gated StripeClient from the Kernel container. The DWH is
 * a batch job that always hits real Stripe (even if the orchestrator is in
 * mock mode). If no secret key is configured, returns 0 for all counts.
 *
 * Subscriptions: TRUNCATE + bulk INSERT (full refresh).
 * Invoices + Refunds: upsert ON DUPLICATE KEY UPDATE (incremental-safe).
 *
 * Chunked: processes pages of ~100 at a time (Stripe's default page size
 * from autoPagingIterator), commits to the DB per chunk.
 */
final class StripeExtractor
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @return array{subscriptions:int, invoices:int, refunds:int}
     */
    public function run(): array
    {
        $secretKey = (string) Config::for('stripe')->get('secret_key', '');
        if ($secretKey === '') {
            return ['subscriptions' => 0, 'invoices' => 0, 'refunds' => 0];
        }
        $stripe = new StripeClient($secretKey);

        $subs = $this->extractSubscriptions($stripe);
        $invs = $this->extractInvoices($stripe);
        $refs = $this->extractRefunds($stripe);

        return ['subscriptions' => $subs, 'invoices' => $invs, 'refunds' => $refs];
    }

    private function extractSubscriptions(StripeClient $stripe): int
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_dwh_subscription_data');
        $wpdb->query("TRUNCATE TABLE `{$table}`");

        $count = 0;
        $subs = $stripe->subscriptions->all(['limit' => 100, 'status' => 'all']);
        foreach ($subs->autoPagingIterator() as $sub) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$table}` (subscription_id, customer_id, status, current_period_start, current_period_end, cancel_at, canceled_at, ended_at, created, currency, amount, interval_unit, raw_json, imported_at)
                 VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
                (string) $sub->id,
                (string) ($sub->customer ?? ''),
                (string) ($sub->status ?? ''),
                $sub->current_period_start ? gmdate('Y-m-d H:i:s', $sub->current_period_start) : null,
                $sub->current_period_end   ? gmdate('Y-m-d H:i:s', $sub->current_period_end) : null,
                $sub->cancel_at            ? gmdate('Y-m-d H:i:s', $sub->cancel_at) : null,
                $sub->canceled_at          ? gmdate('Y-m-d H:i:s', $sub->canceled_at) : null,
                $sub->ended_at             ? gmdate('Y-m-d H:i:s', $sub->ended_at) : null,
                $sub->created              ? gmdate('Y-m-d H:i:s', $sub->created) : null,
                strtoupper((string) ($sub->currency ?? 'huf')),
                isset($sub->items->data[0]->price->unit_amount) ? ($sub->items->data[0]->price->unit_amount / 100) : null,
                (string) ($sub->items->data[0]->price->recurring->interval ?? ''),
                wp_json_encode($sub->toArray()),
                gmdate('Y-m-d H:i:s')
            ));
            $count++;
        }
        return $count;
    }

    private function extractInvoices(StripeClient $stripe): int
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_dwh_invoices_data');
        $count = 0;
        $invoices = $stripe->invoices->all(['limit' => 100]);
        foreach ($invoices->autoPagingIterator() as $inv) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$table}` (invoice_id, subscription_id, customer_id, status, amount_due, amount_paid, currency, created, paid_at, raw_json, imported_at)
                 VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    status=VALUES(status), amount_due=VALUES(amount_due), amount_paid=VALUES(amount_paid),
                    paid_at=VALUES(paid_at), raw_json=VALUES(raw_json), imported_at=VALUES(imported_at)",
                (string) $inv->id,
                (string) ($inv->subscription ?? ''),
                (string) ($inv->customer ?? ''),
                (string) ($inv->status ?? ''),
                ($inv->amount_due ?? 0) / 100,
                ($inv->amount_paid ?? 0) / 100,
                strtoupper((string) ($inv->currency ?? 'huf')),
                $inv->created ? gmdate('Y-m-d H:i:s', $inv->created) : null,
                isset($inv->status_transitions->paid_at) && $inv->status_transitions->paid_at
                    ? gmdate('Y-m-d H:i:s', $inv->status_transitions->paid_at) : null,
                wp_json_encode($inv->toArray()),
                gmdate('Y-m-d H:i:s')
            ));
            $count++;
        }
        return $count;
    }

    private function extractRefunds(StripeClient $stripe): int
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_dwh_refunds_data');
        $count = 0;
        $refunds = $stripe->refunds->all(['limit' => 100]);
        foreach ($refunds->autoPagingIterator() as $ref) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$table}` (refund_id, charge_id, payment_intent, invoice_id, customer_id, amount, currency, status, reason, created, raw_json, imported_at)
                 VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    status=VALUES(status), raw_json=VALUES(raw_json), imported_at=VALUES(imported_at)",
                (string) $ref->id,
                (string) ($ref->charge ?? ''),
                (string) ($ref->payment_intent ?? ''),
                '', // Stripe refund objects don't directly carry invoice_id; join via charge→invoice in reporting
                '', // customer_id also requires a join through the charge
                ($ref->amount ?? 0) / 100,
                strtoupper((string) ($ref->currency ?? 'huf')),
                (string) ($ref->status ?? ''),
                (string) ($ref->reason ?? ''),
                $ref->created ? gmdate('Y-m-d H:i:s', $ref->created) : null,
                wp_json_encode($ref->toArray()),
                gmdate('Y-m-d H:i:s')
            ));
            $count++;
        }
        return $count;
    }
}
