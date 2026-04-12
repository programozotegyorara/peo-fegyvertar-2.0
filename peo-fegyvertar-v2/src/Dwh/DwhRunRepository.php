<?php

declare(strict_types=1);

namespace Peoft\Dwh;

use Peoft\Core\Db\Connection;

defined('ABSPATH') || exit;

/**
 * Manages the lifecycle of `peoft_dwh_runs` rows.
 *
 * A DWH rebuild has three stages:
 *   startRun()     → INSERT status='running'   → returns $runId
 *   completeRun()  → UPDATE status='done'
 *   failRun()      → UPDATE status='failed'
 *
 * stats_json is a free-form JSON blob that the extractors + builders
 * populate progressively as they finish their work. DwhStatusPage reads it.
 */
final class DwhRunRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function startRun(): int
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_dwh_runs');
        $wpdb->insert($table, [
            'started_at' => gmdate('Y-m-d H:i:s'),
            'status'     => 'running',
            'stats_json' => '{}',
            'error_text' => null,
        ], ['%s', '%s', '%s', '%s']);
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $stats
     */
    public function completeRun(int $runId, array $stats): void
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_dwh_runs');
        $wpdb->update($table, [
            'ended_at'   => gmdate('Y-m-d H:i:s'),
            'status'     => 'done',
            'stats_json' => wp_json_encode($stats),
        ], ['id' => $runId], ['%s', '%s', '%s'], ['%d']);
    }

    public function failRun(int $runId, string $error): void
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_dwh_runs');
        $wpdb->update($table, [
            'ended_at'   => gmdate('Y-m-d H:i:s'),
            'status'     => 'failed',
            'error_text' => substr($error, 0, 4096),
        ], ['id' => $runId], ['%s', '%s', '%s'], ['%d']);
    }

    /**
     * @param array<string,mixed> $partialStats
     */
    public function updateStats(int $runId, array $partialStats): void
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_dwh_runs');
        $wpdb->update($table, [
            'stats_json' => wp_json_encode($partialStats),
        ], ['id' => $runId], ['%s'], ['%d']);
    }
}
