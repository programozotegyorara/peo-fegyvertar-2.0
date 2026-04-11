<?php

declare(strict_types=1);

namespace Peoft\Integrations;

defined('ABSPATH') || exit;

/**
 * Thrown by ApiClient when the HTTP request completed but the remote returned
 * a non-2xx status code. Carries the numeric status so the caller (usually a
 * task handler) can classify retry semantics per the plan §8:
 *
 *   5xx / 408 / 429  → retryable (wrap in RetryableException)
 *   400 / 401 / 403 / 404 → poison (wrap in PoisonException)
 *   anything else    → caller decides; default should be retry
 *
 * The raw response body is preserved on ->responseBody so handlers can log
 * the downstream error for troubleshooting.
 */
final class ApiHttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $method,
        public readonly string $url,
        public readonly string $responseBody,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        if ($this->status >= 500) {
            return true;
        }
        return in_array($this->status, [408, 425, 429], true);
    }
}
