<?php

declare(strict_types=1);

namespace Peoft\Integrations;

use Peoft\Orchestrator\Worker\RetryableException;

defined('ABSPATH') || exit;

/**
 * Thrown by ApiClient when the HTTP request fails at the transport layer:
 * connection refused, DNS failure, TLS handshake error, request timeout,
 * etc. Always retryable — the downstream service never saw the request, so
 * the caller can safely try again.
 *
 * Subclass of RetryableException so the Dispatcher applies backoff
 * automatically without any handler-specific classification code.
 */
final class ApiTransportException extends RetryableException
{
}
