<?php

declare(strict_types=1);

namespace Peoft\Orchestrator\Worker;

defined('ABSPATH') || exit;

/**
 * Thrown by a handler when the task's work failed for a transient reason.
 * The Dispatcher responds by incrementing attempts, applying Backoff::delayFor,
 * and returning the task to status=pending so it will be picked up by a later tick.
 */
class RetryableException extends \RuntimeException
{
}
