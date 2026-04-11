<?php

declare(strict_types=1);

namespace Peoft\Core\Config;

use Peoft\Core\Env;

defined('ABSPATH') || exit;

/**
 * In-memory config store for the current request.
 * Populated by ConfigLoader at boot; read via ConfigSection.
 *
 * Write APIs (set/setMany) go to the underlying DB table and bust this cache;
 * they are used by ConfigEditor (admin UI) and ImportFromLegacyDbCommand.
 */
final class ConfigRepository
{
    /** @var array<string, array<string, mixed>>  section => key => value */
    private array $values;

    public function __construct(
        public readonly Env $env,
        array $values,
        private readonly \wpdb $wpdb,
    ) {
        $this->values = $values;
    }

    public function section(string $name): ConfigSection
    {
        return new ConfigSection($name, $this->values[$name] ?? []);
    }

    public function get(string $fqKey, mixed $default = null): mixed
    {
        [$section, $key] = $this->split($fqKey);
        return $this->values[$section][$key] ?? $default;
    }

    public function has(string $fqKey): bool
    {
        [$section, $key] = $this->split($fqKey);
        return isset($this->values[$section]) && array_key_exists($key, $this->values[$section]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Persists a single value to peoft_config (env-scoped) and updates the
     * in-memory cache. Enforces the SSRF host allowlist.
     */
    public function set(string $fqKey, mixed $value, ?string $updatedBy = null): void
    {
        [$section, $key] = $this->split($fqKey);

        if (ConfigSchema::isHostKey($fqKey) && is_string($value) && $value !== '') {
            if (!ConfigSchema::hostMatchesAllowlist($section, $value)) {
                throw new \InvalidArgumentException(
                    "Rejected: '{$fqKey}' value '{$value}' is not in the hardcoded host allowlist for section '{$section}'."
                );
            }
        }

        if (ConfigSchema::isModeKey($fqKey)) {
            if (!in_array($value, ConfigSchema::VALID_MODES, true)) {
                throw new \InvalidArgumentException(
                    "Rejected: '{$fqKey}' must be one of: " . implode(', ', ConfigSchema::VALID_MODES) . "."
                );
            }
        }

        $table = $this->wpdb->prefix . 'peoft_config';
        $stored = is_scalar($value) || $value === null ? $value : wp_json_encode($value);
        $isSecret = ConfigSchema::isSecret($fqKey) ? 1 : 0;
        $now = gmdate('Y-m-d H:i:s');

        $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT INTO `{$table}` (env, config_key, config_value, is_secret, updated_at, updated_by)
                 VALUES (%s, %s, %s, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    config_value = VALUES(config_value),
                    is_secret = VALUES(is_secret),
                    updated_at = VALUES(updated_at),
                    updated_by = VALUES(updated_by)",
                $this->env->value,
                $fqKey,
                $stored,
                $isSecret,
                $now,
                $updatedBy
            )
        );

        $this->values[$section][$key] = is_string($stored) && self::looksLikeJson($stored)
            ? (json_decode($stored, true) ?? $stored)
            : $stored;
    }

    /**
     * @param array<string, mixed> $kv fq-keyed
     */
    public function setMany(array $kv, ?string $updatedBy = null): void
    {
        foreach ($kv as $fqKey => $value) {
            $this->set($fqKey, $value, $updatedBy);
        }
    }

    public function delete(string $fqKey): void
    {
        [$section, $key] = $this->split($fqKey);
        $table = $this->wpdb->prefix . 'peoft_config';
        $this->wpdb->delete($table, ['env' => $this->env->value, 'config_key' => $fqKey], ['%s', '%s']);
        unset($this->values[$section][$key]);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function split(string $fqKey): array
    {
        if (!str_contains($fqKey, '.')) {
            throw new \InvalidArgumentException("Config key must be of the form 'section.key', got '{$fqKey}'.");
        }
        return explode('.', $fqKey, 2);
    }

    private static function looksLikeJson(string $s): bool
    {
        $s = trim($s);
        return ($s !== '') && ($s[0] === '{' || $s[0] === '[');
    }
}
