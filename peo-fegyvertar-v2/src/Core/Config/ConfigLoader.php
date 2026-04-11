<?php

declare(strict_types=1);

namespace Peoft\Core\Config;

use Peoft\Core\Env;

defined('ABSPATH') || exit;

/**
 * Loads config values from layered sources, highest priority first:
 *
 *   1. PEOFT_CONFIG_OVERRIDES constant    (tests / CLI only)
 *   2. /wp-content/peoft-env.php          (preferred location for secrets)
 *   3. peoft_config DB table              (env-scoped rows)
 *   4. wp_options fallback                (non-secret config editable via Config Editor)
 *
 * Merges them into a nested map shape: [section][key] = value.
 * JSON scalar values are auto-decoded at load time.
 *
 * Missing-key enforcement happens at *read* time in ConfigSection, not here —
 * the loader just assembles whatever layers contribute.
 */
final class ConfigLoader
{
    public const ENV_FILE_PATH = WP_CONTENT_DIR . '/peoft-env.php';

    public function __construct(
        private readonly Env $env,
        private readonly \wpdb $wpdb,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function load(): array
    {
        $merged = [];

        // Layer 4 (lowest): wp_options fallback
        $this->mergeInto($merged, $this->fromWpOptions());

        // Layer 3: peoft_config DB table
        $this->mergeInto($merged, $this->fromDbTable());

        // Layer 2: /wp-content/peoft-env.php
        $this->mergeInto($merged, $this->fromEnvFile());

        // Layer 1 (highest): PEOFT_CONFIG_OVERRIDES constant
        $this->mergeInto($merged, $this->fromOverridesConstant());

        return $merged;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fromOverridesConstant(): array
    {
        if (!defined('PEOFT_CONFIG_OVERRIDES')) {
            return [];
        }
        $raw = constant('PEOFT_CONFIG_OVERRIDES');
        if (!is_array($raw)) {
            return [];
        }
        // Accept either env-scoped or flat shape. Prefer env-scoped.
        if (isset($raw[$this->env->value]) && is_array($raw[$this->env->value])) {
            return $this->normalizeSections($raw[$this->env->value]);
        }
        return $this->normalizeSections($raw);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fromEnvFile(): array
    {
        if (!is_file(self::ENV_FILE_PATH)) {
            return [];
        }
        // PROD guard: refuse to read a world-readable secret file.
        if ($this->env->isProd()) {
            $perms = fileperms(self::ENV_FILE_PATH);
            if ($perms !== false && ($perms & 0o044) !== 0) {
                throw new \RuntimeException(
                    'peoft-env.php is readable by group/world in PROD. chmod 600 required.'
                );
            }
        }
        /** @var mixed $raw */
        $raw = include self::ENV_FILE_PATH;
        if (!is_array($raw)) {
            return [];
        }
        if (isset($raw[$this->env->value]) && is_array($raw[$this->env->value])) {
            return $this->normalizeSections($raw[$this->env->value]);
        }
        return $this->normalizeSections($raw);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fromDbTable(): array
    {
        $table = $this->wpdb->prefix . 'peoft_config';
        // Guard: if the table doesn't exist (pre-activation), return empty.
        $exists = $this->wpdb->get_var(
            $this->wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );
        if ($exists === null) {
            return [];
        }
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT config_key, config_value FROM `{$table}` WHERE env = %s",
                $this->env->value
            ),
            ARRAY_A
        ) ?: [];

        $flat = [];
        foreach ($rows as $row) {
            $flat[$row['config_key']] = self::decodeJsonIfNeeded($row['config_value']);
        }
        return $this->normalizeFlat($flat);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fromWpOptions(): array
    {
        $prefix = 'peoft_config_' . $this->env->value . '_';
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $prefix . '%'
            ),
            ARRAY_A
        ) ?: [];

        $flat = [];
        foreach ($rows as $row) {
            $fqKey = substr((string) $row['option_name'], strlen($prefix));
            if ($fqKey === '' || !str_contains($fqKey, '.')) {
                continue;
            }
            $flat[$fqKey] = self::decodeJsonIfNeeded((string) $row['option_value']);
        }
        return $this->normalizeFlat($flat);
    }

    /**
     * Accepts a mixed section-shaped map (section => keys OR deeply nested).
     * @param array<string,mixed> $shape
     * @return array<string, array<string, mixed>>
     */
    private function normalizeSections(array $shape): array
    {
        $out = [];
        foreach ($shape as $section => $kv) {
            if (!is_array($kv)) {
                continue;
            }
            $out[(string) $section] = $kv;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $flat  fq-key => value
     * @return array<string, array<string, mixed>>
     */
    private function normalizeFlat(array $flat): array
    {
        $out = [];
        foreach ($flat as $fqKey => $value) {
            if (!is_string($fqKey) || !str_contains($fqKey, '.')) {
                continue;
            }
            [$section, $key] = explode('.', $fqKey, 2);
            $out[$section][$key] = $value;
        }
        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $dst
     * @param array<string, array<string, mixed>> $src
     */
    private function mergeInto(array &$dst, array $src): void
    {
        foreach ($src as $section => $kv) {
            foreach ($kv as $k => $v) {
                $dst[$section][$k] = $v;
            }
        }
    }

    private static function decodeJsonIfNeeded(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return $value;
        }
        $decoded = json_decode($trimmed, true);
        return $decoded ?? $value;
    }
}
