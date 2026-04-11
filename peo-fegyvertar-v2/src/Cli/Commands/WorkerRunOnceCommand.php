<?php

declare(strict_types=1);

namespace Peoft\Cli\Commands;

use Peoft\Core\Container;
use Peoft\Orchestrator\Queue\TaskRepository;
use Peoft\Orchestrator\Worker\Worker;

defined('ABSPATH') || exit;

/**
 * `wp peoft worker:run-task <task_id> [--reset]`
 *
 * Run a single task bypassing the claim transaction. Used for:
 *   - Admin "Run now" button on the Tasks Inbox.
 *   - Interactive debugging of a stuck task.
 *
 * If the task is in a terminal state (done/dead), pass --reset to put it
 * back to pending (attempts=0, next_run_at=now) before dispatching.
 */
final class WorkerRunOnceCommand
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        if (!isset($args[0])) {
            \WP_CLI::error('Usage: wp peoft worker:run-task <task_id> [--reset]');
            return;
        }
        $id = (int) $args[0];
        if ($id < 1) {
            \WP_CLI::error('task_id must be a positive integer.');
            return;
        }

        $repo = $this->container->get(TaskRepository::class);
        $task = $repo->findById($id);
        if ($task === null) {
            \WP_CLI::error("Task #{$id} not found.");
            return;
        }

        if ($task->status->isTerminal()) {
            if (empty($assoc['reset'])) {
                \WP_CLI::error("Task #{$id} is in terminal status '{$task->status->value}'. Pass --reset to re-run.");
                return;
            }
            $repo->resetForRetry($id);
        }

        $worker = $this->container->get(Worker::class);
        $outcome = $worker->runOnce($id);
        \WP_CLI::success("Task #{$id} outcome: {$outcome}");
    }
}
