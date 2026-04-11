<?php

declare(strict_types=1);

namespace Peoft\Core;

defined('ABSPATH') || exit;

enum Env: string
{
    case Dev = 'dev';
    case Uat = 'uat';
    case Prod = 'prod';

    public static function fromConstant(): self
    {
        if (!defined('PEOFT_ENV')) {
            throw new \RuntimeException('PEOFT_ENV constant is not defined in wp-config.php.');
        }
        $raw = (string) constant('PEOFT_ENV');
        return self::tryFrom($raw)
            ?? throw new \RuntimeException("PEOFT_ENV has invalid value '{$raw}'. Expected: dev | uat | prod.");
    }

    public function isProd(): bool
    {
        return $this === self::Prod;
    }

    public function isDev(): bool
    {
        return $this === self::Dev;
    }

    public function label(): string
    {
        return match ($this) {
            self::Dev => 'DEV',
            self::Uat => 'UAT',
            self::Prod => 'PROD',
        };
    }
}
