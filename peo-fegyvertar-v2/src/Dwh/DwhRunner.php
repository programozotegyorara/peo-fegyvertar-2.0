<?php

declare(strict_types=1);

namespace Peoft\Dwh;

use Peoft\Audit\AuditLog;
use Peoft\Dwh\Etl\CircleExtractor;
use Peoft\Dwh\Etl\KpiBuilder;
use Peoft\Dwh\Etl\SnapshotBuilder;
use Peoft\Dwh\Etl\StripeExtractor;
use Peoft\Dwh\Etl\SzamlazzExtractor;

defined('ABSPATH') || exit;

/**
 * Orchestrates the nightly DWH rebuild.
 *
 * Sequence (per plan §10):
 *   1. StripeExtractor::run()           — subs + invoices + refunds from Stripe
 *   2. SzamlazzExtractor::run()         — invoices from Számlázz (skeleton in E)
 *   3. CircleExtractor::run()           — members from Circle (skeleton in E)
 *   4. SnapshotBuilder::forDate(yesterday) — daily snapshot from current DWH data
 *   5. KpiBuilder::forDate(yesterday)   — daily KPI calc from snapshots
 *
 * Each step is wrapped in a try/catch so a single extractor failure doesn't
 * take down the entire run. Partial results are persisted and the error is
 * recorded in the run row and audit trail.
 *
 * **Isolation**: never reads/writes peoft_tasks, peoft_webhook_events, or
 * peoft_audit_log (the orchestrator tables). The only shared table is the
 * audit log for DWH_RUN_STARTED / DWH_RUN_DONE / DWH_RUN_FAILED audit rows.
 */
final class DwhRunner
{
    public function __construct(
        private readonly DwhRunRepository $runs,
        private readonly StripeExtractor $stripe,
        private readonly SzamlazzExtractor $szamlazz,
        private readonly CircleExtractor $circle,
        private readonly SnapshotBuilder $snapshots,
        private readonly KpiBuilder $kpi,
    ) {}

    /**
     * @return array{run_id:int, stats:array<string,mixed>, error:?string}
     */
    public function run(): array
    {
        $runId = $this->runs->startRun();
        $stats = [];
        $errors = [];

        AuditLog::record(
            actor: 'cli',
            action: 'DWH_RUN_STARTED',
            subjectType: 'dwh_run',
            subjectId: (string) $runId,
        );

        // Step 1: Stripe
        try {
            $stats['stripe'] = $this->stripe->run();
        } catch (\Throwable $e) {
            $errors[] = 'stripe: ' . $e->getMessage();
            $stats['stripe'] = ['error' => substr($e->getMessage(), 0, 200)];
        }
        $this->runs->updateStats($runId, $stats);

        // Step 2: Számlázz
        try {
            $stats['szamlazz'] = ['rows' => $this->szamlazz->run()];
        } catch (\Throwable $e) {
            $errors[] = 'szamlazz: ' . $e->getMessage();
            $stats['szamlazz'] = ['error' => substr($e->getMessage(), 0, 200)];
        }
        $this->runs->updateStats($runId, $stats);

        // Step 3: Circle
        try {
            $stats['circle'] = ['rows' => $this->circle->run()];
        } catch (\Throwable $e) {
            $errors[] = 'circle: ' . $e->getMessage();
            $stats['circle'] = ['error' => substr($e->getMessage(), 0, 200)];
        }
        $this->runs->updateStats($runId, $stats);

        // Step 4: Snapshots (yesterday UTC)
        $yesterday = gmdate('Y-m-d', strtotime('-1 day'));
        try {
            $stats['snapshots'] = $this->snapshots->forDate($yesterday);
        } catch (\Throwable $e) {
            $errors[] = 'snapshots: ' . $e->getMessage();
            $stats['snapshots'] = ['error' => substr($e->getMessage(), 0, 200)];
        }
        $this->runs->updateStats($runId, $stats);

        // Step 5: KPIs (yesterday)
        try {
            $this->kpi->forDate($yesterday);
            $stats['kpi'] = ['date' => $yesterday, 'ok' => true];
        } catch (\Throwable $e) {
            $errors[] = 'kpi: ' . $e->getMessage();
            $stats['kpi'] = ['error' => substr($e->getMessage(), 0, 200)];
        }

        $errorSummary = $errors !== [] ? implode('; ', $errors) : null;

        if ($errors !== []) {
            $this->runs->failRun($runId, $errorSummary ?? '');
            AuditLog::record(
                actor: 'cli',
                action: 'DWH_RUN_FAILED',
                subjectType: 'dwh_run',
                subjectId: (string) $runId,
                after: $stats,
                error: $errorSummary,
            );
        } else {
            $this->runs->completeRun($runId, $stats);
            AuditLog::record(
                actor: 'cli',
                action: 'DWH_RUN_DONE',
                subjectType: 'dwh_run',
                subjectId: (string) $runId,
                after: $stats,
            );
        }

        return ['run_id' => $runId, 'stats' => $stats, 'error' => $errorSummary];
    }
}
