<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Queue;

defined('ABSPATH') || exit;

/**
 * Fixed-schedule exponential-ish backoff for failed task retries.
 *
 * After attempt N (1-indexed), wait STEPS[N-1] seconds before retrying.
 * After MAX_ATTEMPTS, the dispatcher marks the task as dead.
 *
 *   1st failure → retry in  60s   (1m)
 *   2nd failure → retry in 300s   (5m)
 *   3rd failure → retry in 900s   (15m)
 *   4th failure → retry in 3600s  (1h)
 *   5th failure → retry in 21600s (6h)
 *   6th failure → retry in 86400s (24h)
 *   7th failure → dead
 */
final class Backoff
{
    public const STEPS = [60, 300, 900, 3600, 21600, 86400];
    public const MAX_ATTEMPTS = 6;

    /**
     * Delay in seconds after the given attempt count.
     * @param int $attempts number of attempts that have already failed (>=1)
     */
    public static function delayFor(int $attempts): int
    {
        if ($attempts < 1) {
            return self::STEPS[0];
        }
        $index = min($attempts - 1, count(self::STEPS) - 1);
        return self::STEPS[$index];
    }

    public static function isTerminal(int $attempts): bool
    {
        return $attempts >= self::MAX_ATTEMPTS;
    }
}
