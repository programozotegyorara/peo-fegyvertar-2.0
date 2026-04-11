<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Handlers;

use Peoft\Integrations\ActiveCampaign\ActiveCampaignClient;
use Peoft\Orchestrator\Queue\Task;
use Peoft\Orchestrator\Worker\PoisonException;

defined('ABSPATH') || exit;

/**
 * `ac.tag_contact` — assign a named tag to a contact.
 *
 * Payload shape: { "email": "...", "tag": "FT: ACTIVE" }
 *
 * Guard: if `hasTag($email, $tag) === true`, skip the call. This is the
 * idempotency oracle for this task type. Either outcome is safe: AC itself
 * accepts duplicate contactTag POSTs with a 200 OK, but the guard avoids
 * a pointless call + audit row.
 *
 * Task-type idempotency key: sha256("ac_tag:{env}:{email}:{tag}").
 */
final class TagActiveCampaignContactHandler implements TaskHandler
{
    public function __construct(
        private readonly ActiveCampaignClient $ac,
    ) {}

    public static function type(): string
    {
        return 'ac.tag_contact';
    }

    public function loadContext(Task $task): TaskContext
    {
        $payload = $task->payload ?? [];
        $email = (string) ($payload['email'] ?? '');
        $tag = (string) ($payload['tag'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PoisonException("Task #{$task->id} ac.tag_contact: missing/invalid email");
        }
        if ($tag === '') {
            throw new PoisonException("Task #{$task->id} ac.tag_contact: missing 'tag'");
        }
        return new TaskContext($task, [
            'email' => $email,
            'tag'   => $tag,
        ]);
    }

    public function guard(TaskContext $context): bool
    {
        return $this->ac->hasTag(
            (string) $context->get('email'),
            (string) $context->get('tag')
        );
    }

    public function execute(TaskContext $context): void
    {
        $this->ac->tagContact(
            (string) $context->get('email'),
            (string) $context->get('tag')
        );
    }
}
