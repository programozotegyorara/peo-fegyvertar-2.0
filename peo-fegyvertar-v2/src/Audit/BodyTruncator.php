<?php

declare(strict_types=1);

namespace Peoft\Audit;

defined('ABSPATH') || exit;

final class BodyTruncator
{
    public const MAX = 65_536;

    public static function truncate(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }
        $len = strlen($body);
        if ($len <= self::MAX) {
            return $body;
        }
        $dropped = $len - self::MAX;
        return substr($body, 0, self::MAX) . "\n...[TRUNCATED {$dropped} bytes]";
    }
}
