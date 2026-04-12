<?php

declare(strict_types=1);

namespace Peoft\Dwh\Etl;

use Peoft\Core\Db\Connection;

defined('ABSPATH') || exit;

/**
 * Calculates daily KPIs from the snapshot tables and writes one row into
 * `peoft_dwh_daily_kpi` via REPLACE INTO (idempotent for the target date).
 *
 * Ports 1.0's `dwh-etl-full.php` lines 425-666.
 *
 * All 17 KPI columns are calculated from the subscription + invoice
 * snapshot tables for the target date. If the snapshot tables are empty
 * (e.g. first run, or DEV with no Stripe test data), all values are 0.
 */
final class KpiBuilder
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function forDate(string $kpiDate): bool
    {
        $wpdb = $this->db->wpdb();
        $prefix = $this->db->prefix();

        $subSnap = $prefix . 'peoft_dwh_subscription_snapshots';
        $invSnap = $prefix . 'peoft_dwh_invoices_snapshots';
        $kpiTable = $prefix . 'peoft_dwh_daily_kpi';

        // Active subscriptions by interval
        $activeSubs     = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$subSnap}` WHERE snapshot_date=%s AND status='active'", $kpiDate));
        $activeMonthly  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$subSnap}` WHERE snapshot_date=%s AND status='active' AND interval_unit='month'", $kpiDate));
        $activeYearly   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$subSnap}` WHERE snapshot_date=%s AND status='active' AND interval_unit='year'", $kpiDate));
        $activeTrial    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$subSnap}` WHERE snapshot_date=%s AND status='trialing'", $kpiDate));

        // Churn: subscriptions that ended/cancelled in the last 30 days relative to kpiDate
        $churn30Start = gmdate('Y-m-d', strtotime($kpiDate . ' -30 days'));
        $churnCount30 = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$subSnap}` WHERE snapshot_date=%s AND status IN ('canceled','ended','unpaid') AND canceled_at >= %s",
            $kpiDate, $churn30Start . ' 00:00:00'
        ));
        $churnRate = $activeSubs > 0 ? round(($churnCount30 / $activeSubs) * 100, 2) : 0;

        // MRR: sum of monthly-equivalent amounts for active subscriptions
        $monthlySum = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM `{$subSnap}` WHERE snapshot_date=%s AND status='active' AND interval_unit='month'",
            $kpiDate
        ));
        $yearlySum = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM `{$subSnap}` WHERE snapshot_date=%s AND status='active' AND interval_unit='year'",
            $kpiDate
        ));
        $mrr = round($monthlySum + ($yearlySum / 12), 2);

        // ARPU
        $arpu = $activeSubs > 0 ? round($mrr / $activeSubs, 2) : 0;

        // ACL (average customer lifetime in months): rough estimate from
        // churn rate. If churn_rate=0 we can't compute; default to 0.
        $acl = $churnRate > 0 ? round(1 / ($churnRate / 100), 2) : 0;

        // LTV = ARPU * ACL
        $ltv = round($arpu * $acl, 2);

        // Daily counts from invoices snapshot
        $purchasesCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$invSnap}` WHERE snapshot_date=%s AND status='paid' AND DATE(paid_at)=%s",
            $kpiDate, $kpiDate
        ));

        // Trial → active conversions (subscriptions that were trialing yesterday
        // and are active today). Requires comparing two snapshot dates — skip
        // if the previous date doesn't have data.
        $prevDate = gmdate('Y-m-d', strtotime($kpiDate . ' -1 day'));
        $trialToActive = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$subSnap}` s1
               JOIN `{$subSnap}` s0 ON s0.subscription_id = s1.subscription_id AND s0.snapshot_date = %s
              WHERE s1.snapshot_date = %s AND s1.status = 'active' AND s0.status = 'trialing'",
            $prevDate, $kpiDate
        ));

        // Cancellations and ended counts on this date
        $cancellationsCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$subSnap}` WHERE snapshot_date=%s AND DATE(canceled_at)=%s",
            $kpiDate, $kpiDate
        ));
        $endedCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$subSnap}` WHERE snapshot_date=%s AND status='ended'",
            $kpiDate
        ));

        // Trial lifecycle
        $trialCreatedCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$subSnap}` WHERE snapshot_date=%s AND status='trialing' AND DATE(created)=%s",
            $kpiDate, $kpiDate
        ));
        $trialCanceledCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$subSnap}` WHERE snapshot_date=%s AND status IN ('canceled','ended') AND DATE(canceled_at)=%s AND DATE(created) > %s",
            $kpiDate, $kpiDate, gmdate('Y-m-d', strtotime($kpiDate . ' -30 days'))
        ));

        $wpdb->query($wpdb->prepare(
            "REPLACE INTO `{$kpiTable}` (
                kpi_date, active_subs, active_monthly, active_yearly, active_trial,
                churn_count_30, churn_rate, arpu, mrr, ltv, acl,
                purchases_count, trial_to_active_count, cancellations_count,
                ended_count, trial_created_count, trial_canceled_count, created_at
            ) VALUES (
                %s, %d, %d, %d, %d,
                %d, %f, %f, %f, %f, %f,
                %d, %d, %d,
                %d, %d, %d, %s
            )",
            $kpiDate, $activeSubs, $activeMonthly, $activeYearly, $activeTrial,
            $churnCount30, $churnRate, $arpu, $mrr, $ltv, $acl,
            $purchasesCount, $trialToActive, $cancellationsCount,
            $endedCount, $trialCreatedCount, $trialCanceledCount, gmdate('Y-m-d H:i:s')
        ));

        return true;
    }
}
