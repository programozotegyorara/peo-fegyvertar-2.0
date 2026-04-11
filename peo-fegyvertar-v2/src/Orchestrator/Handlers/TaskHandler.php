<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Handlers;

use Peoft\Orchestrator\Queue\Task;

defined('ABSPATH') || exit;

/**
 * Contract every task handler implements.
 *
 * Execution order per task (managed by Dispatcher):
 *   1. loadContext($task)  — fetch anything the handler needs (Stripe, etc.)
 *   2. guard($context)     — return true to short-circuit to 'done' without
 *                            side effects (used by idempotency checks: "already
 *                            done downstream, skip")
 *   3. execute($context)   — perform the side effect. May throw:
 *                              - RetryableException  → backoff retry
 *                              - PoisonException     → mark dead immediately
 *                              - anything else       → treated as retryable
 *
 * Handlers should be stateless. All per-task state lives in $task and $context.
 */
interface TaskHandler
{
    /** The task_type string this handler responds to (e.g. 'szamlazz.issue_invoice'). */
    public static function type(): string;

    public function loadContext(Task $task): TaskContext;

    /** @return bool true = already done, skip execute() */
    public function guard(TaskContext $context): bool;

    public function execute(TaskContext $context): void;
}
