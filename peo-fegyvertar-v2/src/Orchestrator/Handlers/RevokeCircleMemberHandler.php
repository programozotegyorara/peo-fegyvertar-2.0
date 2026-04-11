<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Handlers;

use Peoft\Integrations\Circle\CircleClient;
use Peoft\Orchestrator\Queue\Task;
use Peoft\Orchestrator\Worker\PoisonException;

defined('ABSPATH') || exit;

/**
 * `circle.revoke_member` — remove the customer from the configured access
 * group so they lose access to the paid content. The member stays in the
 * community (so historical comments are preserved) — only the access group
 * membership is revoked.
 *
 * Payload shape:
 *   { "email": "...", "access_group_id": "53741" }
 *
 * Execution:
 *   removeFromAccessGroup(email, id) — swallows 404 (already absent)
 *
 * Guard: none. The remove is naturally idempotent, so retry is safe.
 *
 * Task-type idempotency key (built by router):
 *   sha256("circle.revoke_member:{env}:{email}:{access_group_id}")
 */
final class RevokeCircleMemberHandler implements TaskHandler
{
    public function __construct(
        private readonly CircleClient $circle,
    ) {}

    public static function type(): string
    {
        return 'circle.revoke_member';
    }

    public function loadContext(Task $task): TaskContext
    {
        $payload = $task->payload ?? [];
        $email = (string) ($payload['email'] ?? '');
        $accessGroupId = (string) ($payload['access_group_id'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PoisonException("Task #{$task->id} circle.revoke_member: missing/invalid email");
        }
        if ($accessGroupId === '') {
            throw new PoisonException("Task #{$task->id} circle.revoke_member: missing access_group_id");
        }

        return new TaskContext($task, [
            'email'           => $email,
            'access_group_id' => $accessGroupId,
        ]);
    }

    public function guard(TaskContext $context): bool
    {
        return false;
    }

    public function execute(TaskContext $context): void
    {
        $this->circle->removeFromAccessGroup(
            (string) $context->get('email'),
            (string) $context->get('access_group_id'),
        );
    }
}
