<?php

declare(strict_types=1);

namespace Peoft\Audit;

defined('ABSPATH') || exit;

/**
 * Scrubs sensitive values from data about to be written to the audit log.
 *
 * Two layers of redaction:
 *   - Header names (case-insensitive) on HEADER_DENY are replaced wholesale.
 *   - JSON key names matching any pattern in KEY_DENY_PATTERNS have their
 *     values replaced by [REDACTED]. Recursive walk.
 *
 * Bodies that aren't JSON pass through unchanged — callers should only route
 * JSON bodies through redactJson. Both calls return the number of replacements
 * made via the out-parameter so the caller can record `redactions_applied`
 * on the audit row for transparency.
 */
final class Redactor
{
    public const HEADER_DENY = [
        'authorization',
        'api-token',
        'x-api-key',
        'stripe-signature',
        'x-peoft-worker-key',
        'cookie',
        'set-cookie',
    ];

    public const KEY_DENY_PATTERNS = [
        '/secret_key/i',
        '/api_key/i',
        '/password/i',
        '/token/i',
        '/whsec/i',
        '/client_secret/i',
        '/webhook_secret/i',
        '/signing_secret/i',
        '/private_key/i',
        '/access_token/i',
        '/refresh_token/i',
        '/bearer/i',
        '/session_secret/i',
        '/authorization/i',
    ];

    public const REDACTED_MARKER = '[REDACTED]';

    /**
     * @param array<string, string|list<string>> $headers
     * @return array<string, string|list<string>>
     */
    public static function redactHeaders(array $headers, ?int &$count = null): array
    {
        $count ??= 0;
        $out = [];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string) $name), self::HEADER_DENY, true)) {
                $out[$name] = self::REDACTED_MARKER;
                $count++;
                continue;
            }
            $out[$name] = $value;
        }
        return $out;
    }

    /**
     * Redact a JSON string. If parsing fails, returns the original unchanged.
     */
    public static function redactJsonString(string $json, ?int &$count = null): string
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $json;
        }
        $count ??= 0;
        $walked = self::walkAndRedact($decoded, $count);
        $encoded = json_encode($walked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? $json : $encoded;
    }

    /**
     * Redact an array in-place-style, returning a new redacted copy.
     *
     * @param array<mixed,mixed> $data
     * @return array<mixed,mixed>
     */
    public static function redactArray(array $data, ?int &$count = null): array
    {
        $count ??= 0;
        return self::walkAndRedact($data, $count);
    }

    /**
     * @param array<mixed,mixed> $data
     * @return array<mixed,mixed>
     */
    private static function walkAndRedact(array $data, int &$count): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::keyMatchesDeny($key)) {
                $data[$key] = self::REDACTED_MARKER;
                $count++;
                continue;
            }
            if (is_array($value)) {
                $data[$key] = self::walkAndRedact($value, $count);
            }
        }
        return $data;
    }

    private static function keyMatchesDeny(string $key): bool
    {
        foreach (self::KEY_DENY_PATTERNS as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }
        return false;
    }
}
