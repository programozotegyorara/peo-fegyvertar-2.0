<?php

declare(strict_types=1);

namespace Peoft\Audit;

defined('ABSPATH') || exit;

/**
 * Structured detail of a single outbound API call, attached to an AuditEvent
 * when `action = API_CALL`. Bodies are already redacted+truncated at this point.
 */
final class ApiCallRecord
{
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly ?int $status,
        public readonly ?string $reqBody,
        public readonly ?string $resBody,
        public readonly ?int $durationMs,
    ) {}
}
