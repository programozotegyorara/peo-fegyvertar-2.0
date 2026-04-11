<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Handlers;

defined('ABSPATH') || exit;

/**
 * Registry of task_type → TaskHandler instances.
 *
 * Populated by Kernel::boot during service wiring. Adding a new task type in
 * the future means: (a) write a new handler class, (b) register it here in
 * Kernel::boot, (c) add the corresponding entry to StripeEventMap. No changes
 * to the Worker or Dispatcher.
 */
final class TaskRegistry
{
    /** @var array<string, TaskHandler> */
    private array $handlers = [];

    public function register(TaskHandler $handler): void
    {
        $this->handlers[$handler::type()] = $handler;
    }

    public function handlerFor(string $taskType): TaskHandler
    {
        if (!isset($this->handlers[$taskType])) {
            throw new \RuntimeException("No handler registered for task_type '{$taskType}'.");
        }
        return $this->handlers[$taskType];
    }

    public function has(string $taskType): bool
    {
        return isset($this->handlers[$taskType]);
    }

    /**
     * @return list<string>
     */
    public function registeredTypes(): array
    {
        return array_keys($this->handlers);
    }
}
