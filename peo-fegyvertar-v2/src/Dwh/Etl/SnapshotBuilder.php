<?php

declare(strict_types=1);

namespace Peoft\Dwh\Etl;

use Peoft\Core\Db\Connection;

defined('ABSPATH') || exit;

/**
 * Builds daily snapshot rows in `peoft_dwh_subscription_snapshots` and
 * `peoft_dwh_invoices_snapshots` from the current DWH data tables.
 *
 * Idempotent: `DELETE WHERE snapshot_date=? THEN INSERT` for the target date.
 * Can safely re-run for the same date.
 *
 * Ports 1.0's `dwh-etl-full.php` lines 352-423.
 *
 * Also prunes snapshots older than $retentionDays (default 14, matching
 * observed 1.0 behavior of ~14-day retention).
 */
final class SnapshotBuilder
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @return array{subscription_snapshots:int, invoice_snapshots:int, pruned:int}
     */
    public function forDate(string $snapshotDate, int $retentionDays = 14): array
    {
        $wpdb = $this->db->wpdb();
        $prefix = $this->db->prefix();

        // --- Subscription snapshots ---
        $subSnapTable = $prefix . 'peoft_dwh_subscription_snapshots';
        $subDataTable = $prefix . 'peoft_dwh_subscription_data';

        $wpdb->query($wpdb->prepare("DELETE FROM `{$subSnapTable}` WHERE snapshot_date = %s", $snapshotDate));
        $subCount = (int) $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$subSnapTable}` (snapshot_date, subscription_id, customer_id, status, current_period_start, current_period_end, cancel_at, canceled_at, created, currency, amount, interval_unit)
                 SELECT %s, subscription_id, customer_id, status, current_period_start, current_period_end, cancel_at, canceled_at, created, currency, amount, interval_unit
                   FROM `{$subDataTable}`",
                $snapshotDate
            )
        );

        // --- Invoice snapshots ---
        $invSnapTable = $prefix . 'peoft_dwh_invoices_snapshots';
        $invDataTable = $prefix . 'peoft_dwh_invoices_data';

        $wpdb->query($wpdb->prepare("DELETE FROM `{$invSnapTable}` WHERE snapshot_date = %s", $snapshotDate));
        $invCount = (int) $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$invSnapTable}` (snapshot_date, invoice_id, subscription_id, customer_id, status, amount_due, amount_paid, currency, created, paid_at)
                 SELECT %s, invoice_id, subscription_id, customer_id, status, amount_due, amount_paid, currency, created, paid_at
                   FROM `{$invDataTable}`",
                $snapshotDate
            )
        );

        // --- Prune old snapshots ---
        $cutoff = gmdate('Y-m-d', strtotime("-{$retentionDays} days"));
        $pruned = 0;
        $pruned += (int) $wpdb->query($wpdb->prepare("DELETE FROM `{$subSnapTable}` WHERE snapshot_date < %s", $cutoff));
        $pruned += (int) $wpdb->query($wpdb->prepare("DELETE FROM `{$invSnapTable}` WHERE snapshot_date < %s", $cutoff));

        return [
            'subscription_snapshots' => max(0, $subCount),
            'invoice_snapshots'      => max(0, $invCount),
            'pruned'                 => $pruned,
        ];
    }
}
