<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Handlers;

use Peoft\Orchestrator\Queue\Task;

defined('ABSPATH') || exit;

/**
 * Placeholder handler used during Phase B before real integration handlers exist.
 *
 * Does nothing beyond recording the task's payload into its TaskContext so the
 * Dispatcher's audit row has visible detail. Always succeeds; never throws.
 *
 * Replaced in Phase C by real handlers (IssueSzamlazzInvoiceHandler,
 * EnrollCircleMemberHandler, etc.).
 */
final class NoopLogOnlyHandler implements TaskHandler
{
    public static function type(): string
    {
        return 'noop.log_only';
    }

    public function loadContext(Task $task): TaskContext
    {
        return new TaskContext(
            task: $task,
            data: [
                'task_id'    => $task->id,
                'task_type'  => $task->taskType,
                'stripe_ref' => $task->stripeRef,
                'payload'    => $task->payload ?? [],
            ],
        );
    }

    public function guard(TaskContext $context): bool
    {
        return false; // never short-circuit; always "do" the noop
    }

    public function execute(TaskContext $context): void
    {
        // Intentionally empty. Dispatcher will mark the task done and write audit.
    }
}
