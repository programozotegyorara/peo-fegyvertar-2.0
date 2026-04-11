<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Queue;

defined('ABSPATH') || exit;

/**
 * Persisted task row loaded from peoft_tasks. Immutable snapshot.
 * State transitions are performed via TaskRepository::markDone / markRetry /
 * markDead which return a fresh Task instance if callers need it.
 */
final class Task
{
    /**
     * @param array<string,mixed>|null $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly string $idempotencyKey,
        public readonly string $taskType,
        public readonly ?string $stripeRef,
        public readonly ?array $payload,
        public readonly TaskStatus $status,
        public readonly int $attempts,
        public readonly \DateTimeImmutable $nextRunAt,
        public readonly ?string $lastError,
        public readonly ?string $sourceEventId,
        public readonly string $actor,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly ?\DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $finishedAt,
    ) {}

    /**
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $utc = new \DateTimeZone('UTC');
        $payloadRaw = $row['payload_json'] ?? null;
        $payload = null;
        if (is_string($payloadRaw) && $payloadRaw !== '') {
            $decoded = json_decode($payloadRaw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            } else {
                // Silent null-fallback can hide corruption from handlers.
                // Log to error_log so operators see it before a handler
                // misbehaves on a null where an array was expected.
                error_log(sprintf(
                    '[peoft] Task #%d has unparseable payload_json (len=%d, json_last_error=%s)',
                    (int) ($row['id'] ?? 0),
                    strlen($payloadRaw),
                    json_last_error_msg()
                ));
            }
        }

        return new self(
            id:             (int) $row['id'],
            idempotencyKey: (string) $row['idempotency_key'],
            taskType:       (string) $row['task_type'],
            stripeRef:      $row['stripe_ref'] !== null ? (string) $row['stripe_ref'] : null,
            payload:        $payload,
            status:         TaskStatus::from((string) $row['status']),
            attempts:       (int) $row['attempts'],
            nextRunAt:      new \DateTimeImmutable((string) $row['next_run_at'], $utc),
            lastError:      $row['last_error'] !== null ? (string) $row['last_error'] : null,
            sourceEventId:  $row['source_event_id'] !== null ? (string) $row['source_event_id'] : null,
            actor:          (string) $row['actor'],
            createdAt:      new \DateTimeImmutable((string) $row['created_at'], $utc),
            updatedAt:      new \DateTimeImmutable((string) $row['updated_at'], $utc),
            startedAt:      isset($row['started_at'])  && $row['started_at']  !== null ? new \DateTimeImmutable((string) $row['started_at'],  $utc) : null,
            finishedAt:     isset($row['finished_at']) && $row['finished_at'] !== null ? new \DateTimeImmutable((string) $row['finished_at'], $utc) : null,
        );
    }
}
