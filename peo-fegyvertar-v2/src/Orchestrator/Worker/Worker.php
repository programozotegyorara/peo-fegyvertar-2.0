<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Worker;

use Peoft\Orchestrator\Queue\Task;
use Peoft\Orchestrator\Queue\TaskRepository;

defined('ABSPATH') || exit;

/**
 * Drains the task outbox. Called by `wp peoft worker:tick` once a minute
 * (from system cron on the DEV/UAT/PROD hosts, flock-guarded).
 *
 * Each tick:
 *   1. Resets orphaned `running` rows (started >10min ago) back to `pending`.
 *   2. Claims a batch of `pending` rows via SELECT ... FOR UPDATE SKIP LOCKED.
 *   3. Runs each claimed task through the Dispatcher sequentially, stopping
 *      early if the tick's max runtime would be exceeded.
 *   4. Returns a TickResult summary.
 *
 * The Worker itself is deliberately thin — the complexity lives in the
 * Dispatcher (per-task state transitions) and TaskRepository (locking SQL).
 */
final class Worker
{
    public function __construct(
        private readonly TaskRepository $repo,
        private readonly Dispatcher $dispatcher,
    ) {}

    /**
     * @return array{
     *   claimed:int,
     *   done:int,
     *   skipped:int,
     *   retry:int,
     *   dead:int,
     *   orphans_swept:int,
     *   runtime_ms:int,
     *   stopped_early:bool
     * }
     */
    public function tick(int $batchSize = 20, int $maxRuntimeSeconds = 55): array
    {
        $tickStart = microtime(true);
        $deadline = $tickStart + $maxRuntimeSeconds;

        $orphans = $this->repo->orphanSweep(10);

        $claimed = $this->repo->claimBatch($batchSize);

        $counts = ['done' => 0, 'skipped' => 0, 'retry' => 0, 'dead' => 0];
        $stoppedEarly = false;

        foreach ($claimed as $task) {
            if (microtime(true) >= $deadline) {
                $stoppedEarly = true;
                // Return the remaining claimed rows to pending so another tick picks them up.
                $this->repo->markRetry($task->id, $task->attempts, 0, '[runtime-guard] returned to pending');
                continue;
            }
            $outcome = $this->dispatcher->run($task);
            if (isset($counts[$outcome])) {
                $counts[$outcome]++;
            }
        }

        return [
            'claimed'       => count($claimed),
            'done'          => $counts['done'],
            'skipped'       => $counts['skipped'],
            'retry'         => $counts['retry'],
            'dead'          => $counts['dead'],
            'orphans_swept' => $orphans,
            'runtime_ms'    => (int) ((microtime(true) - $tickStart) * 1000),
            'stopped_early' => $stoppedEarly,
        ];
    }

    /**
     * Run a single task by id, bypassing the claim transaction.
     * Used by the admin "Run now" action and `wp peoft worker:run-task <id>`.
     *
     * Refuses to run tasks already in a terminal state (done/dead) unless
     * the caller has explicitly reset them first (via TaskRepository::resetForRetry).
     */
    public function runOnce(int $taskId): string
    {
        $task = $this->repo->findById($taskId);
        if ($task === null) {
            throw new \RuntimeException("Task #{$taskId} not found.");
        }
        if ($task->status->isTerminal()) {
            throw new \RuntimeException("Task #{$taskId} is in terminal status '{$task->status->value}'. Reset it first.");
        }
        return $this->dispatcher->run($task);
    }
}
