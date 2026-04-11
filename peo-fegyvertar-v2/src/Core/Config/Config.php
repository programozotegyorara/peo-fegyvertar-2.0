<?php

declare(strict_types=1);

namespace Peoft\Core\Config;

use Peoft\Core\Env;

defined('ABSPATH') || exit;

/**
 * Static facade over the request-scoped ConfigRepository.
 *
 * Usage:
 *   Config::for('stripe')->get('secret_key');
 *   Config::env();
 *
 * Bound by Kernel::boot() after the ConfigLoader has assembled values.
 */
final class Config
{
    private static ?ConfigRepository $repo = null;

    public static function bind(ConfigRepository $repo): void
    {
        self::$repo = $repo;
    }

    public static function reset(): void
    {
        self::$repo = null;
    }

    public static function isBound(): bool
    {
        return self::$repo !== null;
    }

    public static function for(string $section): ConfigSection
    {
        return self::repo()->section($section);
    }

    public static function env(): Env
    {
        return self::repo()->env;
    }

    public static function get(string $fqKey, mixed $default = null): mixed
    {
        return self::repo()->get($fqKey, $default);
    }

    public static function repo(): ConfigRepository
    {
        if (self::$repo === null) {
            throw new \RuntimeException('Config facade used before Kernel::boot(). Call Config::bind($repo) first.');
        }
        return self::$repo;
    }
}
