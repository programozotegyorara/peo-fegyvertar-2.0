<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Worker;

defined('ABSPATH') || exit;

/**
 * Thrown by a handler when the task's work cannot succeed regardless of retries.
 * The Dispatcher responds by marking the task as 'dead' immediately — no backoff,
 * no further attempts. A dead-letter alert is emitted for human inspection.
 *
 * Examples: malformed payload, 404/401/403 from a downstream that indicates the
 * target no longer exists, schema-validation failure on manual-trigger input.
 */
class PoisonException extends \RuntimeException
{
}
