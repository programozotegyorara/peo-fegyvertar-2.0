<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Queue;

defined('ABSPATH') || exit;

/**
 * DTO describing a task that should be enqueued. Not yet persisted.
 *
 * Built by EventRouter from a Stripe event, or by admin manual-trigger forms.
 * The TaskEnqueuer turns a list of these into INSERT IGNORE rows in peoft_tasks.
 */
final class TaskSpec
{
    /**
     * @param array<string,mixed>|null $payload
     */
    public function __construct(
        public readonly string $taskType,
        public readonly string $idempotencyKey,
        public readonly ?string $stripeRef = null,
        public readonly ?array $payload = null,
        public readonly ?string $sourceEventId = null,
        public readonly string $actor = 'stripe',
        public readonly ?\DateTimeImmutable $runAt = null,
    ) {}
}
