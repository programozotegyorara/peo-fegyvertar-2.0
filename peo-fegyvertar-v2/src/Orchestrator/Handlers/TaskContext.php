<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Handlers;

use Peoft\Orchestrator\Queue\Task;

defined('ABSPATH') || exit;

/**
 * Runtime context passed to a TaskHandler's guard/execute steps.
 *
 * Contains the persisted Task row plus whatever context the handler loaded
 * from Stripe/Számlázz/Circle/AC/... during loadContext. Handlers produce
 * arbitrary shapes, so the `data` field is loosely typed.
 */
final class TaskContext
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(
        public readonly Task $task,
        public readonly array $data,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
