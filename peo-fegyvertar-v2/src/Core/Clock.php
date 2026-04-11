<?php

declare(strict_types=1);

namespace Peoft\Core;

defined('ABSPATH') || exit;

final class Clock
{
    private static ?\DateTimeImmutable $frozen = null;

    public function nowUtc(): \DateTimeImmutable
    {
        return self::$frozen ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function nowUtcString(): string
    {
        return $this->nowUtc()->format('Y-m-d H:i:s');
    }

    public function nowUtcMicro(): string
    {
        return $this->nowUtc()->format('Y-m-d H:i:s.u');
    }

    public static function freeze(\DateTimeImmutable $at): void
    {
        self::$frozen = $at->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function unfreeze(): void
    {
        self::$frozen = null;
    }
}
