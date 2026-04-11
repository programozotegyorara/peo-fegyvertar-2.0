<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Handlers;

use Peoft\Integrations\Circle\CircleClient;
use Peoft\Orchestrator\Queue\Task;
use Peoft\Orchestrator\Worker\PoisonException;

defined('ABSPATH') || exit;

/**
 * `circle.enroll_member` — ensure the customer exists in Circle and has
 * access to the configured access group.
 *
 * Payload shape:
 *   { "email": "...", "name": "...", "access_group_id": "53741" }
 *
 * Execution:
 *   1. createMember(email, name)   — swallows "already exists" inside Live
 *   2. addToAccessGroup(email, id) — naturally idempotent at Circle
 *
 * Guard: none. The two execute steps are both idempotent, so retries are safe.
 *
 * Task-type idempotency key (built by router):
 *   sha256("circle.enroll_member:{env}:{email}:{access_group_id}")
 */
final class EnrollCircleMemberHandler implements TaskHandler
{
    public function __construct(
        private readonly CircleClient $circle,
    ) {}

    public static function type(): string
    {
        return 'circle.enroll_member';
    }

    public function loadContext(Task $task): TaskContext
    {
        $payload = $task->payload ?? [];
        $email = (string) ($payload['email'] ?? '');
        $name  = (string) ($payload['name'] ?? '');
        $accessGroupId = (string) ($payload['access_group_id'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PoisonException("Task #{$task->id} circle.enroll_member: missing/invalid email");
        }
        if ($accessGroupId === '') {
            throw new PoisonException("Task #{$task->id} circle.enroll_member: missing access_group_id");
        }

        return new TaskContext($task, [
            'email'           => $email,
            'name'            => $name,
            'access_group_id' => $accessGroupId,
            'skip_invitation' => (bool) ($payload['skip_invitation'] ?? false),
        ]);
    }

    public function guard(TaskContext $context): bool
    {
        // Both execute steps are naturally idempotent at Circle's side.
        // No pre-check needed.
        return false;
    }

    public function execute(TaskContext $context): void
    {
        $email = (string) $context->get('email');
        $name  = (string) $context->get('name');
        $accessGroupId = (string) $context->get('access_group_id');
        $skipInvitation = (bool) $context->get('skip_invitation');

        $this->circle->createMember($email, $name, $skipInvitation);
        $this->circle->addToAccessGroup($email, $accessGroupId);
    }
}
