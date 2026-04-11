<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Handlers;

use Peoft\Integrations\ActiveCampaign\ActiveCampaignClient;
use Peoft\Orchestrator\Queue\Task;
use Peoft\Orchestrator\Worker\PoisonException;

defined('ABSPATH') || exit;

/**
 * `ac.untag_contact` — remove a named tag from a contact.
 *
 * Payload shape: { "email": "...", "tag": "FT: DELETED" }
 *
 * Guard: if `hasTag($email, $tag) === false`, skip the call. Same shape as
 * the tag handler but inverted — we short-circuit when the desired end state
 * is already in place.
 *
 * Task-type idempotency key: sha256("ac_untag:{env}:{email}:{tag}").
 */
final class UntagActiveCampaignContactHandler implements TaskHandler
{
    public function __construct(
        private readonly ActiveCampaignClient $ac,
    ) {}

    public static function type(): string
    {
        return 'ac.untag_contact';
    }

    public function loadContext(Task $task): TaskContext
    {
        $payload = $task->payload ?? [];
        $email = (string) ($payload['email'] ?? '');
        $tag = (string) ($payload['tag'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PoisonException("Task #{$task->id} ac.untag_contact: missing/invalid email");
        }
        if ($tag === '') {
            throw new PoisonException("Task #{$task->id} ac.untag_contact: missing 'tag'");
        }
        return new TaskContext($task, [
            'email' => $email,
            'tag'   => $tag,
        ]);
    }

    public function guard(TaskContext $context): bool
    {
        return !$this->ac->hasTag(
            (string) $context->get('email'),
            (string) $context->get('tag')
        );
    }

    public function execute(TaskContext $context): void
    {
        $this->ac->untagContact(
            (string) $context->get('email'),
            (string) $context->get('tag')
        );
    }
}
