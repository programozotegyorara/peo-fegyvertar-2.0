<?php

declare(strict_types=1);

namespace Peoft\Core\Db;

use Peoft\Core\Env;

defined('ABSPATH') || exit;

/**
 * Atomic counter allocation over the peoft_counters table.
 *
 * Each row is `(env, counter_key)` → `value`. The `allocate()` method
 * increments the value and returns the new number in a single atomic
 * statement using MySQL/MariaDB's `LAST_INSERT_ID(expr)` trick:
 *
 *   UPDATE peoft_counters SET value = LAST_INSERT_ID(value + 1)
 *     WHERE env=? AND counter_key=?;
 *   SELECT LAST_INSERT_ID();
 *
 * The LAST_INSERT_ID(expr) form stores `expr` in the connection-scoped
 * "last insert id" slot and returns the new value. This works on
 * MariaDB 10.4+ and MySQL 5.5+ without requiring the `UPDATE ... RETURNING`
 * syntax that our MariaDB 10.4 install doesn't support.
 *
 * The singleton-per-(env,key) row was seeded by ImportFromLegacyDbCommand
 * at `MAX(PEOFT_COUNTERS.id)+1` from the 1.0 table — so the first allocation
 * after cutover returns 16955 (the first free order number). Sequence
 * continuity is preserved.
 *
 * If a counter key doesn't exist yet (fresh install with no import), the
 * first allocate() initializes it to 1.
 */
final class CounterRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Env $env,
    ) {}

    public function allocate(string $counterKey): int
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_counters');

        // First try an atomic increment. Affects 0 rows if the row doesn't
        // yet exist, in which case we fall through to the INSERT.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                    SET value = LAST_INSERT_ID(value + 1),
                        updated_at = %s
                  WHERE env = %s AND counter_key = %s",
                gmdate('Y-m-d H:i:s'),
                $this->env->value,
                $counterKey
            )
        );

        if ($updated > 0) {
            $newValue = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
            return $newValue;
        }

        // Row missing — initialize at 1 and return 1. Use INSERT IGNORE so
        // that a concurrent initializer doesn't double-seed.
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO `{$table}` (counter_key, env, value, updated_at) VALUES (%s, %s, %d, %s)",
                $counterKey,
                $this->env->value,
                1,
                gmdate('Y-m-d H:i:s')
            )
        );
        // Re-run the increment in case another worker raced us past 1.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                    SET value = LAST_INSERT_ID(value + 1),
                        updated_at = %s
                  WHERE env = %s AND counter_key = %s",
                gmdate('Y-m-d H:i:s'),
                $this->env->value,
                $counterKey
            )
        );
        return (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
    }

    public function peek(string $counterKey): ?int
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_counters');
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT value FROM `{$table}` WHERE env = %s AND counter_key = %s LIMIT 1",
                $this->env->value,
                $counterKey
            )
        );
        return $value !== null ? (int) $value : null;
    }
}
