<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Queue;

use Peoft\Core\Db\Connection;

defined('ABSPATH') || exit;

/**
 * All direct DB access to peoft_tasks lives here. Handlers and the Worker
 * never touch wpdb / PDO directly for task rows.
 */
final class TaskRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function table(): string
    {
        return $this->db->table('peoft_tasks');
    }

    public function findById(int $id): ?Task
    {
        $wpdb = $this->db->wpdb();
        $table = $this->table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id),
            ARRAY_A
        );
        return $row !== null ? Task::fromRow($row) : null;
    }

    public function findByIdempotencyKey(string $key): ?Task
    {
        $wpdb = $this->db->wpdb();
        $table = $this->table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE idempotency_key = %s LIMIT 1", $key),
            ARRAY_A
        );
        return $row !== null ? Task::fromRow($row) : null;
    }

    /**
     * Claim up to $batchSize pending tasks whose next_run_at has arrived.
     * Returns an array of claimed Task objects, all transitioned to status=running.
     *
     * **Concurrency model**: we deliberately do NOT use `FOR UPDATE SKIP LOCKED`
     * here. Two reasons:
     *   1. MariaDB 10.4 (XAMPP default) doesn't support SKIP LOCKED;
     *      it was added in MariaDB 10.6 / MySQL 8.0.
     *   2. The cron-level `flock -n /tmp/peoft-worker-{env}.lock -c 'wp peoft worker:tick'`
     *      guarantees only one worker process per env runs at a time, so row-level
     *      lock contention between workers never happens in production either.
     *
     * Two layers of defense against "same row gets claimed twice":
     *   (a) SELECT .. FOR UPDATE inside a transaction serializes any concurrent
     *       claim attempt; a second transaction blocks until the first commits.
     *   (b) The UPDATE predicate `status='pending' AND started_at IS NULL`
     *       ensures the UPDATE affects 0 rows if the state changed between
     *       SELECT and UPDATE. The follow-up SELECT filters by the same shape
     *       so callers only ever see self-consistent claimed rows.
     *
     * `orphanSweep()` clears `started_at` back to NULL when it rescues a stuck
     * running task, so orphan-swept rows are eligible to be claimed again.
     *
     * @return list<Task>
     */
    public function claimBatch(int $batchSize): array
    {
        $pdo = $this->db->pdo();
        $table = $this->table();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT id FROM `{$table}`
                  WHERE status = 'pending' AND started_at IS NULL AND next_run_at <= UTC_TIMESTAMP()
               ORDER BY next_run_at ASC
                  LIMIT :batch
              FOR UPDATE"
            );
            $stmt->bindValue(':batch', $batchSize, \PDO::PARAM_INT);
            $stmt->execute();
            $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));

            if ($ids === []) {
                $pdo->commit();
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $now = gmdate('Y-m-d H:i:s');
            $update = $pdo->prepare(
                "UPDATE `{$table}`
                    SET status = 'running',
                        started_at = ?,
                        updated_at = ?
                  WHERE status = 'pending' AND started_at IS NULL AND id IN ($placeholders)"
            );
            $update->execute(array_merge([$now, $now], $ids));
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Load full rows via wpdb (separate connection — no lock held).
        // Filter on the same shape we just wrote so we never return a row
        // whose state was changed between our commit and this fetch.
        $wpdb = $this->db->wpdb();
        $idsCsv = implode(',', array_map('intval', $ids));
        $rows = $wpdb->get_results(
            "SELECT * FROM `{$table}`
              WHERE id IN ({$idsCsv})
                AND status = 'running'
                AND started_at IS NOT NULL
              ORDER BY next_run_at ASC",
            ARRAY_A
        ) ?: [];
        return array_map(Task::fromRow(...), $rows);
    }

    /**
     * Reset any task stuck in `running` for more than $minutesOld minutes.
     * Returns the number of rows reset. Called at the start of every Worker tick.
     *
     * Clears `started_at` back to NULL so the row is eligible for
     * claimBatch() on a future tick (which requires started_at IS NULL).
     */
    public function orphanSweep(int $minutesOld = 10): int
    {
        $wpdb = $this->db->wpdb();
        $table = $this->table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($minutesOld * 60));
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                    SET status = 'pending',
                        started_at = NULL,
                        last_error = CONCAT(COALESCE(last_error, ''), '\n[orphan-sweep ', %s, ']'),
                        updated_at = %s
                  WHERE status = 'running' AND started_at < %s",
                gmdate('Y-m-d H:i:s'),
                gmdate('Y-m-d H:i:s'),
                $cutoff
            )
        );
        return $result === false ? 0 : (int) $result;
    }

    public function markDone(int $taskId): void
    {
        $wpdb = $this->db->wpdb();
        $table = $this->table();
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                    SET status = 'done',
                        finished_at = %s,
                        updated_at = %s,
                        last_error = NULL
                  WHERE id = %d",
                $now, $now, $taskId
            )
        );
    }

    public function markRetry(int $taskId, int $newAttemptCount, int $delaySeconds, string $errorMessage): void
    {
        $wpdb = $this->db->wpdb();
        $table = $this->table();
        $nextRun = gmdate('Y-m-d H:i:s', time() + $delaySeconds);
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                    SET status = 'pending',
                        attempts = %d,
                        next_run_at = %s,
                        last_error = %s,
                        updated_at = %s
                  WHERE id = %d",
                $newAttemptCount, $nextRun, $errorMessage, $now, $taskId
            )
        );
    }

    public function markDead(int $taskId, string $errorMessage): void
    {
        $wpdb = $this->db->wpdb();
        $table = $this->table();
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                    SET status = 'dead',
                        finished_at = %s,
                        updated_at = %s,
                        last_error = %s
                  WHERE id = %d",
                $now, $now, $errorMessage, $taskId
            )
        );
    }

    /**
     * Used by the admin "Run now" action for dead/failed tasks.
     * Returns the refreshed Task row.
     */
    public function resetForRetry(int $taskId): ?Task
    {
        $wpdb = $this->db->wpdb();
        $table = $this->table();
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                    SET status = 'pending',
                        attempts = 0,
                        next_run_at = %s,
                        last_error = NULL,
                        updated_at = %s
                  WHERE id = %d",
                $now, $now, $taskId
            )
        );
        return $this->findById($taskId);
    }
}
