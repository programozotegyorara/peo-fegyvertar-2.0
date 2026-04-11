<?php

declare(strict_types=1);

namespace Peoft\Integrations;

defined('ABSPATH') || exit;

/**
 * Immutable HTTP response DTO returned by ApiClient::request().
 *
 * Callers that need structured JSON call ->json(), otherwise raw ->body.
 */
final class ApiClientResponse
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        public readonly int $durationMs,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<mixed,mixed>|null
     */
    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
