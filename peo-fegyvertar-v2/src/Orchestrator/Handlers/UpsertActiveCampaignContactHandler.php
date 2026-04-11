<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Handlers;

use Peoft\Integrations\ActiveCampaign\ActiveCampaignClient;
use Peoft\Orchestrator\Queue\Task;
use Peoft\Orchestrator\Worker\PoisonException;

defined('ABSPATH') || exit;

/**
 * `ac.upsert_contact` — ensure a contact exists in ActiveCampaign.
 *
 * Payload shape (from StripeEventMap or admin manual-trigger):
 *   { "email": "...", "first_name": "...", "last_name": "..." }
 *
 * Idempotency: AC's /contact/sync is upsert-by-email natively. The handler
 * doesn't need a local guard — calling it twice with the same email is
 * safe at AC's side.
 *
 * Task-type idempotency key (built by the router): sha256("ac_upsert:{env}:{email}").
 */
final class UpsertActiveCampaignContactHandler implements TaskHandler
{
    public function __construct(
        private readonly ActiveCampaignClient $ac,
    ) {}

    public static function type(): string
    {
        return 'ac.upsert_contact';
    }

    public function loadContext(Task $task): TaskContext
    {
        $payload = $task->payload ?? [];
        $email = (string) ($payload['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PoisonException("Task #{$task->id} ac.upsert_contact: missing/invalid email");
        }
        return new TaskContext($task, [
            'email'      => $email,
            'first_name' => isset($payload['first_name']) ? (string) $payload['first_name'] : null,
            'last_name'  => isset($payload['last_name'])  ? (string) $payload['last_name']  : null,
        ]);
    }

    public function guard(TaskContext $context): bool
    {
        return false; // AC's /contact/sync is naturally idempotent.
    }

    public function execute(TaskContext $context): void
    {
        $this->ac->upsertContact(
            email:     (string) $context->get('email'),
            firstName: $context->get('first_name'),
            lastName:  $context->get('last_name'),
        );
    }
}
