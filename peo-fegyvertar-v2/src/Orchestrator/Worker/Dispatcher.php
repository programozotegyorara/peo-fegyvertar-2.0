<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Worker;

use Peoft\Audit\AuditLog;
use Peoft\Orchestrator\Handlers\TaskRegistry;
use Peoft\Orchestrator\Queue\Backoff;
use Peoft\Orchestrator\Queue\Task;
use Peoft\Orchestrator\Queue\TaskRepository;

defined('ABSPATH') || exit;

/**
 * Runs a single already-claimed task through its handler, handles exceptions,
 * transitions the task's persisted state, and writes audit rows for each step.
 *
 * The Worker is responsible for *selecting* which tasks run; the Dispatcher is
 * responsible for what happens to *one* of them.
 */
final class Dispatcher
{
    public function __construct(
        private readonly TaskRepository $repo,
        private readonly TaskRegistry $registry,
    ) {}

    /**
     * @return string one of 'done', 'retry', 'dead', 'skipped'
     */
    public function run(Task $task): string
    {
        AuditLog::record(
            actor:       'worker',
            action:      'TASK_STARTED',
            subjectType: 'task',
            subjectId:   (string) $task->id,
            taskId:      $task->id,
            after:       ['task_type' => $task->taskType, 'attempts' => $task->attempts],
        );

        $startedMs = (int) (microtime(true) * 1000);

        if (!$this->registry->has($task->taskType)) {
            $this->finishDead($task, "no handler registered for task_type '{$task->taskType}'", $startedMs);
            return 'dead';
        }

        $handler = $this->registry->handlerFor($task->taskType);

        try {
            $context = $handler->loadContext($task);
            if ($handler->guard($context)) {
                $this->repo->markDone($task->id);
                $this->auditDone($task, $startedMs, skipped: true);
                return 'skipped';
            }
            $handler->execute($context);
        } catch (PoisonException $e) {
            $this->finishDead($task, 'PoisonException: ' . $e->getMessage(), $startedMs);
            return 'dead';
        } catch (RetryableException $e) {
            return $this->finishRetry($task, 'RetryableException: ' . $e->getMessage(), $startedMs);
        } catch (\Throwable $e) {
            // Unknown errors are treated as retryable — we'd rather retry a
            // transient bug than dead-letter it immediately. Phase C will
            // tighten the classification once ApiHttpException / ApiTransportException
            // (in Integrations/ApiClient) are available.
            return $this->finishRetry($task, $this->describe($e), $startedMs);
        }

        $this->repo->markDone($task->id);
        $this->auditDone($task, $startedMs, skipped: false);
        return 'done';
    }

    private function finishRetry(Task $task, string $error, int $startedMs): string
    {
        $newAttempts = $task->attempts + 1;
        if (Backoff::isTerminal($newAttempts)) {
            $this->finishDead($task, "exhausted retries: {$error}", $startedMs);
            return 'dead';
        }
        $delay = Backoff::delayFor($newAttempts);
        $this->repo->markRetry($task->id, $newAttempts, $delay, $error);
        AuditLog::record(
            actor:       'worker',
            action:      'TASK_FAILED_RETRY',
            subjectType: 'task',
            subjectId:   (string) $task->id,
            taskId:      $task->id,
            after:       [
                'attempts'     => $newAttempts,
                'max_attempts' => Backoff::MAX_ATTEMPTS,
                'delay_seconds'=> $delay,
                'duration_ms'  => $this->elapsed($startedMs),
            ],
            error:       substr($error, 0, 500),
        );
        return 'retry';
    }

    private function finishDead(Task $task, string $error, int $startedMs): void
    {
        $this->repo->markDead($task->id, $error);
        AuditLog::record(
            actor:       'worker',
            action:      'TASK_FAILED_DEAD',
            subjectType: 'task',
            subjectId:   (string) $task->id,
            taskId:      $task->id,
            after:       [
                'attempts'    => $task->attempts + 1,
                'duration_ms' => $this->elapsed($startedMs),
            ],
            error:       substr($error, 0, 500),
        );
    }

    private function auditDone(Task $task, int $startedMs, bool $skipped): void
    {
        AuditLog::record(
            actor:       'worker',
            action:      'TASK_SUCCEEDED',
            subjectType: 'task',
            subjectId:   (string) $task->id,
            taskId:      $task->id,
            after:       [
                'task_type'   => $task->taskType,
                'skipped'     => $skipped,
                'duration_ms' => $this->elapsed($startedMs),
            ],
        );
    }

    private function elapsed(int $startedMs): int
    {
        return max(0, (int) (microtime(true) * 1000) - $startedMs);
    }

    private function describe(\Throwable $e): string
    {
        return sprintf('%s: %s', $e::class, $e->getMessage());
    }
}
