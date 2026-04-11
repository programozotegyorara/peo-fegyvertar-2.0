<?php

declare(strict_types=1);

namespace Peoft\Core\Db;

defined('ABSPATH') || exit;

/**
 * Database connection abstraction.
 *
 * Phase A uses wpdb exclusively. A raw PDO handle is added lazily in Phase B
 * for the Worker's `SELECT ... FOR UPDATE SKIP LOCKED` claim, which wpdb
 * cannot express cleanly.
 */
final class Connection
{
    private ?\PDO $pdo = null;

    public function __construct(
        private readonly \wpdb $wpdb,
    ) {}

    public function wpdb(): \wpdb
    {
        return $this->wpdb;
    }

    public function prefix(): string
    {
        return $this->wpdb->prefix;
    }

    public function table(string $suffix): string
    {
        return $this->wpdb->prefix . $suffix;
    }

    /**
     * Lazy raw PDO handle for features wpdb can't express. Phase A does not
     * call this. Intended for the Worker's SELECT ... FOR UPDATE SKIP LOCKED
     * in Phase B. Credentials are taken from wp-config.php DB_* constants
     * so we stay consistent with wpdb.
     */
    public function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER')) {
            throw new \RuntimeException('DB_HOST/DB_NAME/DB_USER constants missing; cannot create raw PDO.');
        }
        $host = (string) constant('DB_HOST');
        $name = (string) constant('DB_NAME');
        $user = (string) constant('DB_USER');
        $pass = defined('DB_PASSWORD') ? (string) constant('DB_PASSWORD') : '';

        // DB_HOST may be "host:port" or "host:/socket"
        $port = 3306;
        $socket = null;
        if (str_contains($host, ':')) {
            [$host, $tail] = explode(':', $host, 2);
            if (is_numeric($tail)) {
                $port = (int) $tail;
            } else {
                $socket = $tail;
            }
        }

        $dsn = $socket !== null
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $name)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        $this->pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->pdo->query("SET time_zone = '+00:00'");
        return $this->pdo;
    }
}
