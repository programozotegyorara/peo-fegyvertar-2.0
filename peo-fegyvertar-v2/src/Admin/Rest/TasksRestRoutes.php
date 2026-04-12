<?php

declare(strict_types=1);

namespace Peoft\Admin\Rest;

use Peoft\Audit\AuditLog;
use Peoft\Orchestrator\Queue\TaskRepository;
use Peoft\Orchestrator\Worker\Worker;

defined('ABSPATH') || exit;

/**
 * REST mutations for the Tasks Inbox page.
 *
 * Routes registered under /wp-json/peo-fegyvertar/v2/admin/tasks/...:
 *   - POST   /{id}/retry        — reset a failed/dead task to pending, attempts=0
 *   - POST   /{id}/skip         — mark a task done without running it
 *   - POST   /{id}/cancel       — mark a task dead (no retry, no execution)
 *   - POST   /{id}/run-now      — execute immediately via Worker::runOnce
 *
 * Every action writes an audit row with actor='admin:{user_id}' and the
 * before/after task state.
 */
final class TasksRestRoutes extends AdminRestController
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly Worker $worker,
    ) {}

    public function register(): void
    {
        $ns = self::NAMESPACE;
        $args = [
            'permission_callback' => [$this, 'permissionCallback'],
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => static fn ($v) => is_numeric($v) && (int) $v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ];

        register_rest_route($ns, '/admin/tasks/(?P<id>\d+)/retry',   ['methods' => 'POST', 'callback' => [$this, 'retry']]   + $args);
        register_rest_route($ns, '/admin/tasks/(?P<id>\d+)/skip',    ['methods' => 'POST', 'callback' => [$this, 'skip']]    + $args);
        register_rest_route($ns, '/admin/tasks/(?P<id>\d+)/cancel',  ['methods' => 'POST', 'callback' => [$this, 'cancel']]  + $args);
        register_rest_route($ns, '/admin/tasks/(?P<id>\d+)/run-now', ['methods' => 'POST', 'callback' => [$this, 'runNow']]  + $args);
    }

    public function retry(\WP_REST_Request $req): \WP_REST_Response
    {
        if ($err = $this->authorize($req)) {
            return $err;
        }
        $id = (int) $req->get_param('id');
        $before = $this->tasks->findById($id);
        if ($before === null) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'not_found'], 404);
        }
        $refreshed = $this->tasks->resetForRetry($id);
        AuditLog::record(
            actor: $this->actorString(),
            action: 'TASK_MANUAL_RETRY',
            subjectType: 'task',
            subjectId: (string) $id,
            taskId: $id,
            before: ['status' => $before->status->value, 'attempts' => $before->attempts, 'last_error' => $before->lastError],
            after:  ['status' => $refreshed?->status->value ?? 'pending', 'attempts' => 0],
        );
        return new \WP_REST_Response(['ok' => true, 'task_id' => $id, 'new_status' => 'pending'], 200);
    }

    public function skip(\WP_REST_Request $req): \WP_REST_Response
    {
        if ($err = $this->authorize($req)) {
            return $err;
        }
        $id = (int) $req->get_param('id');
        $before = $this->tasks->findById($id);
        if ($before === null) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'not_found'], 404);
        }
        $this->tasks->markDone($id);
        AuditLog::record(
            actor: $this->actorString(),
            action: 'TASK_MANUAL_SKIP',
            subjectType: 'task',
            subjectId: (string) $id,
            taskId: $id,
            before: ['status' => $before->status->value],
            after:  ['status' => 'done', 'reason' => 'manual_skip'],
        );
        return new \WP_REST_Response(['ok' => true, 'task_id' => $id, 'new_status' => 'done'], 200);
    }

    public function cancel(\WP_REST_Request $req): \WP_REST_Response
    {
        if ($err = $this->authorize($req)) {
            return $err;
        }
        $id = (int) $req->get_param('id');
        $before = $this->tasks->findById($id);
        if ($before === null) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'not_found'], 404);
        }
        $this->tasks->markDead($id, 'manually cancelled by ' . $this->actorString());
        AuditLog::record(
            actor: $this->actorString(),
            action: 'TASK_MANUAL_CANCEL',
            subjectType: 'task',
            subjectId: (string) $id,
            taskId: $id,
            before: ['status' => $before->status->value],
            after:  ['status' => 'dead', 'reason' => 'manual_cancel'],
        );
        return new \WP_REST_Response(['ok' => true, 'task_id' => $id, 'new_status' => 'dead'], 200);
    }

    public function runNow(\WP_REST_Request $req): \WP_REST_Response
    {
        if ($err = $this->authorize($req)) {
            return $err;
        }
        $id = (int) $req->get_param('id');
        $task = $this->tasks->findById($id);
        if ($task === null) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'not_found'], 404);
        }
        if ($task->status->isTerminal()) {
            // Reset first so the worker can re-run the handler.
            $this->tasks->resetForRetry($id);
        }
        try {
            $outcome = $this->worker->runOnce($id);
        } catch (\Throwable $e) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'run_failed', 'detail' => $e->getMessage()], 500);
        }
        return new \WP_REST_Response(['ok' => true, 'task_id' => $id, 'outcome' => $outcome], 200);
    }
}
