<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Queue;

use Peoft\Audit\AuditLog;
use Peoft\Core\Db\Connection;

defined('ABSPATH') || exit;

/**
 * Inserts TaskSpec rows into peoft_tasks. Uses INSERT IGNORE on the unique
 * idempotency_key so enqueueing the same spec twice is a no-op.
 *
 * Every successful insert writes a TASK_ENQUEUED audit row.
 */
final class TaskEnqueuer
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @param list<TaskSpec> $specs
     * @return array{inserted:int, skipped:int, ids:list<int>}
     */
    public function enqueueMany(array $specs, ?string $sourceEventId = null): array
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_tasks');
        $now = gmdate('Y-m-d H:i:s');

        $inserted = 0;
        $skipped = 0;
        $ids = [];

        foreach ($specs as $spec) {
            $runAt = $spec->runAt?->format('Y-m-d H:i:s') ?? $now;
            $payloadJson = $spec->payload !== null ? wp_json_encode($spec->payload) : null;
            $effectiveSource = $spec->sourceEventId ?? $sourceEventId;

            $result = $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO `{$table}`
                        (idempotency_key, task_type, stripe_ref, payload_json, status, attempts, next_run_at, source_event_id, actor, created_at, updated_at)
                     VALUES (%s, %s, %s, %s, 'pending', 0, %s, %s, %s, %s, %s)",
                    $spec->idempotencyKey,
                    $spec->taskType,
                    $spec->stripeRef,
                    $payloadJson,
                    $runAt,
                    $effectiveSource,
                    $spec->actor,
                    $now,
                    $now
                )
            );

            if ($result === 0) {
                $skipped++;
                continue;
            }
            if ($result === false) {
                error_log('[peoft] task enqueue failed: ' . $wpdb->last_error);
                $skipped++;
                continue;
            }

            $id = (int) $wpdb->insert_id;
            $ids[] = $id;
            $inserted++;

            AuditLog::record(
                actor:       $spec->actor,
                action:      'TASK_ENQUEUED',
                subjectType: 'task',
                subjectId:   (string) $id,
                taskId:      $id,
                after:       [
                    'task_type'       => $spec->taskType,
                    'stripe_ref'      => $spec->stripeRef,
                    'idempotency_key' => $spec->idempotencyKey,
                    'source_event_id' => $effectiveSource,
                    'next_run_at'     => $runAt,
                ],
            );
        }

        return ['inserted' => $inserted, 'skipped' => $skipped, 'ids' => $ids];
    }
}
