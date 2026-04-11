<?php

declare(strict_types=1);

namespace Peoft\Core\Db;

defined('ABSPATH') || exit;

/**
 * Tiny forward-only migration runner.
 *
 * Reads `.sql` files from src/Core/Db/migrations/ in lexicographic order,
 * applies the ones not yet recorded in `{prefix}peoft_schema_migrations`.
 *
 * Placeholders in SQL files:
 *   {prefix}   → $wpdb->prefix (e.g. "wp_")
 *
 * Each .sql file may contain multiple statements separated by `;` at the
 * end of a line. Comments starting with `--` are stripped.
 */
final class Migrator
{
    private const MIGRATIONS_DIR = __DIR__ . '/migrations';
    private const LEDGER_SUFFIX = 'peoft_schema_migrations';

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * Apply any pending migrations. Returns the list of versions applied in this run.
     * @return list<string>
     */
    public function up(): array
    {
        $this->ensureLedger();
        $applied = $this->appliedVersions();
        $pending = array_values(array_diff($this->availableVersions(), $applied));
        sort($pending);

        $justRan = [];
        foreach ($pending as $version) {
            $this->applyOne($version);
            $justRan[] = $version;
        }
        return $justRan;
    }

    /**
     * @return list<string>
     */
    public function appliedVersions(): array
    {
        $this->ensureLedger();
        $wpdb = $this->db->wpdb();
        $table = $this->db->table(self::LEDGER_SUFFIX);
        $rows = $wpdb->get_col("SELECT version FROM `{$table}` ORDER BY version ASC");
        return array_values(array_map('strval', $rows ?: []));
    }

    /**
     * @return list<string>
     */
    public function availableVersions(): array
    {
        $files = glob(self::MIGRATIONS_DIR . '/*.sql') ?: [];
        sort($files);
        $versions = [];
        foreach ($files as $path) {
            $versions[] = basename($path, '.sql');
        }
        return $versions;
    }

    private function ensureLedger(): void
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table(self::LEDGER_SUFFIX);
        $charset = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$table}` (
            version VARCHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL,
            PRIMARY KEY (version)
        ) {$charset}");
    }

    private function applyOne(string $version): void
    {
        $path = self::MIGRATIONS_DIR . '/' . $version . '.sql';
        if (!is_file($path)) {
            throw new \RuntimeException("Migration file missing: {$path}");
        }
        $sql = (string) file_get_contents($path);
        $sql = $this->substitutePlaceholders($sql);

        $wpdb = $this->db->wpdb();
        foreach ($this->splitStatements($sql) as $stmt) {
            $trimmed = trim($stmt);
            if ($trimmed === '') {
                continue;
            }
            $result = $wpdb->query($trimmed);
            if ($result === false) {
                throw new \RuntimeException(
                    "Migration {$version} failed: " . $wpdb->last_error . "\nSQL: " . $trimmed
                );
            }
        }

        $ledger = $this->db->table(self::LEDGER_SUFFIX);
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$ledger}` (version, applied_at) VALUES (%s, %s)",
                $version,
                gmdate('Y-m-d H:i:s')
            )
        );
    }

    private function substitutePlaceholders(string $sql): string
    {
        $charset = $this->db->wpdb()->get_charset_collate();
        return str_replace(
            ['{prefix}', '{charset_collate}'],
            [$this->db->prefix(), $charset],
            $sql
        );
    }

    /**
     * Splits a multi-statement SQL string on semicolon-at-end-of-line, ignoring
     * `--` line comments and `/* ... *\/` block comments. Sufficient for our
     * DDL-only migrations; no string-literal-with-semicolons edge cases.
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        // Strip block comments
        $sql = preg_replace('#/\*.*?\*/#s', '', $sql) ?? $sql;
        // Strip `--` line comments
        $lines = preg_split('/\R/u', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $stripped = preg_replace('/--.*$/', '', $line) ?? $line;
            $clean[] = rtrim($stripped);
        }
        $cleaned = implode("\n", $clean);

        $statements = [];
        $buffer = '';
        foreach (explode("\n", $cleaned) as $line) {
            $buffer .= $line . "\n";
            if (preg_match('/;\s*$/', $line)) {
                $statements[] = rtrim($buffer, " \n\t;") . ';';
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }
        return $statements;
    }
}
