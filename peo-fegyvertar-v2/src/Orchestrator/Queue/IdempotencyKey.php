<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Queue;

defined('ABSPATH') || exit;

/**
 * Deterministic sha256 idempotency key builder.
 *
 * The canonical string is "{task_type}:{env}:{part1}:{part2}:...". Two calls
 * with the same inputs always produce the same key, which is what makes the
 * UNIQUE constraint on peoft_tasks.idempotency_key double as an at-most-once
 * enqueue guarantee.
 *
 * Examples:
 *   IdempotencyKey::for('szamlazz.issue_invoice', 'dev', 'in_abc123')
 *   IdempotencyKey::for('circle.enroll_member', 'dev', 'user@example.com', 'ag_53741')
 */
final class IdempotencyKey
{
    public static function for(string $taskType, string $env, string ...$parts): string
    {
        $canonical = $taskType . ':' . $env;
        foreach ($parts as $p) {
            $canonical .= ':' . $p;
        }
        return hash('sha256', $canonical);
    }
}
