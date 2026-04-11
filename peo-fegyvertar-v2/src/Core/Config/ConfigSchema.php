<?php

declare(strict_types=1);

namespace Peoft\Core\Config;

defined('ABSPATH') || exit;

/**
 * Central declaration of every config key the plugin knows about.
 *
 * - SECRET_KEYS: values that must be redacted when written to audit bodies
 *   or rendered in the Config Editor's default view.
 * - INTEGRATION_HOST_ALLOWLIST: SSRF guard for config values that are URLs/hosts;
 *   ConfigRepository::set rejects writes that don't match.
 */
final class ConfigSchema
{
    public const SECRET_KEYS = [
        'stripe.secret_key',
        'stripe.publishable_key',
        'stripe.webhook_secret',
        'szamlazz.api_key',
        'circle.v2_api_key',
        'activecampaign.api_key',
        'mailer.password',
    ];

    /**
     * Host allowlist per integration section. Values may include wildcards
     * of the form `*.example.com`. ConfigRepository::set validates any
     * `*.api_url` / `*.host` write against this list.
     */
    public const INTEGRATION_HOST_ALLOWLIST = [
        'stripe'         => ['api.stripe.com'],
        'szamlazz'       => ['*.szamlazz.hu', 'szamlazz.hu'],
        'activecampaign' => ['*.api-us1.com', '*.activecampaign.com'],
        'circle'         => ['*.circle.so', '*.circle.com'],
        'mailer'         => ['sandbox.smtp.mailtrap.io', 'smtp.mailtrap.io', 'vps.wpkurzus.hu'],
    ];

    /**
     * Valid mode values for per-integration mode flag.
     * 'live'  — hit the real API
     * 'demo'  — hit the provider's built-in demo/test endpoint (Számlázz only)
     * 'mock'  — short-circuit with stubbed responses, audit calls with mock=true
     */
    public const VALID_MODES = ['live', 'demo', 'mock'];

    public static function isSecret(string $fqKey): bool
    {
        return in_array($fqKey, self::SECRET_KEYS, true);
    }

    public static function isModeKey(string $fqKey): bool
    {
        return str_ends_with($fqKey, '.mode');
    }

    public static function isHostKey(string $fqKey): bool
    {
        return str_ends_with($fqKey, '.api_url')
            || str_ends_with($fqKey, '.host');
    }

    /**
     * @param string $section e.g. 'stripe'
     * @param string $urlOrHost
     */
    public static function hostMatchesAllowlist(string $section, string $urlOrHost): bool
    {
        $allowed = self::INTEGRATION_HOST_ALLOWLIST[$section] ?? null;
        if ($allowed === null) {
            return true; // no allowlist defined → permissive
        }
        $host = self::extractHost($urlOrHost);
        foreach ($allowed as $pattern) {
            if (self::hostMatches($host, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private static function extractHost(string $value): string
    {
        if (str_contains($value, '://')) {
            $parsed = parse_url($value);
            return strtolower($parsed['host'] ?? '');
        }
        return strtolower(trim($value));
    }

    private static function hostMatches(string $host, string $pattern): bool
    {
        if (!str_starts_with($pattern, '*.')) {
            return $host === strtolower($pattern);
        }
        $suffix = substr($pattern, 1); // ".example.com"
        return str_ends_with($host, $suffix) || $host === substr($suffix, 1);
    }
}
