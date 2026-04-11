<?php

declare(strict_types=1);

namespace Peoft\Core\Config;

defined('ABSPATH') || exit;

/**
 * Read-only view of one logical section of the loaded config (e.g. "stripe").
 * Created via ConfigRepository::section($name) or the Config facade.
 */
final class ConfigSection
{
    /**
     * @param array<string,mixed> $values flattened key → value for this section
     */
    public function __construct(
        public readonly string $name,
        private readonly array $values,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function getString(string $key, ?string $default = null): ?string
    {
        $v = $this->values[$key] ?? $default;
        return $v === null ? null : (string) $v;
    }

    public function getInt(string $key, ?int $default = null): ?int
    {
        $v = $this->values[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        return (int) $v;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $v = $this->values[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower((string) $v);
        return in_array($s, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Returns the integration mode ('live' | 'demo' | 'mock'). Defaults to 'live'
     * so PROD behavior is the safe default if the key is missing.
     */
    public function mode(): string
    {
        $mode = $this->getString('mode', 'live');
        return in_array($mode, ConfigSchema::VALID_MODES, true) ? $mode : 'live';
    }
}
