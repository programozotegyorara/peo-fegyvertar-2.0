<?php

declare(strict_types=1);

namespace Peoft\Cli\Commands;

use Peoft\Audit\AuditLog;
use Peoft\Core\Config\Config;
use Peoft\Core\Config\ConfigRepository;
use Peoft\Core\Config\ConfigSchema;
use Peoft\Core\Container;
use Peoft\Core\Db\Connection;
use Peoft\Core\Env;

defined('ABSPATH') || exit;

/**
 * `wp peoft import:from-legacy-db --source-db=<name> --to-env=<env>`
 *
 * Dev workbench loader. Reads from a 1.0 MySQL database (read-only), writes
 * into the current 2.0 database's env-scoped tables. Imports:
 *   - PEOFT_CONFIG rows → peoft_config (section.key transform, mock/demo overrides)
 *   - PEOFT_STRIPE_EVENTS rows → peoft_webhook_events (source='legacy_1.0')
 *   - MAX(PEOFT_COUNTERS.id)+1 → peoft_counters singleton for 'order'
 *   - PEOFT_EMAIL_TEMPLATES rows → peoft_email_templates
 *
 * Guardrails:
 *   - Refuses to run if --to-env doesn't match PEOFT_ENV.
 *   - Non-prod imports force circle.mode='mock', activecampaign.mode='mock',
 *     szamlazz.mode='demo'; pass --allow-live-dev to disable this safety net.
 *   - Skips `stripe.webhook_secret` if the legacy value fails validation.
 *   - Skips `phpmailer_config` in non-prod (production SMTP must never hit DEV).
 *   - Opens the source DB read-only; never writes to it.
 */
final class ImportFromLegacyDbCommand
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        $sourceDb = (string) ($assoc['source-db'] ?? '');
        $toEnvRaw = (string) ($assoc['to-env'] ?? '');
        $dryRun = !empty($assoc['dry-run']);
        $allowLiveDev = !empty($assoc['allow-live-dev']);

        if ($sourceDb === '') {
            \WP_CLI::error('--source-db is required.');
            return;
        }
        if ($toEnvRaw === '') {
            \WP_CLI::error('--to-env is required.');
            return;
        }

        $toEnv = Env::tryFrom($toEnvRaw);
        if ($toEnv === null) {
            \WP_CLI::error("Invalid --to-env '{$toEnvRaw}'. Expected: dev | uat | prod.");
            return;
        }

        $currentEnv = Config::env();
        if ($toEnv !== $currentEnv) {
            \WP_CLI::error("--to-env ({$toEnv->value}) does not match PEOFT_ENV ({$currentEnv->value}). Run this command from the target env's WP install.");
            return;
        }

        if (!$toEnv->isProd() && !$allowLiveDev) {
            \WP_CLI::log("Safety net: non-prod import — circle/activecampaign will be forced to mock, szamlazz to demo.");
        }

        $sourcePdo = $this->openSourcePdoReadOnly($sourceDb);

        $targetConn = $this->container->get(Connection::class);
        $wpdb = $targetConn->wpdb();

        $summary = [
            'env'           => $toEnv->value,
            'source_db'     => $sourceDb,
            'dry_run'       => $dryRun,
            'config'        => ['imported' => 0, 'skipped' => [], 'forced_modes' => []],
            'webhook_events'=> ['imported' => 0, 'skipped' => 0],
            'counters'      => ['seeded_order_value' => null],
            'email_templates' => ['imported' => 0],
        ];

        // ---- 1. Config ----
        $configRepo = $this->container->get(ConfigRepository::class);
        $summary['config'] = $this->importConfig($sourcePdo, $configRepo, $toEnv, $dryRun, $allowLiveDev);

        // ---- 2. Webhook events ----
        $summary['webhook_events'] = $this->importWebhookEvents($sourcePdo, $wpdb, $toEnv, $dryRun);

        // ---- 3. Counter seed ----
        $summary['counters'] = $this->importCounters($sourcePdo, $wpdb, $toEnv, $dryRun);

        // ---- 4. Email templates ----
        $summary['email_templates'] = $this->importEmailTemplates($sourcePdo, $wpdb, $toEnv, $dryRun);

        AuditLog::record(
            actor: 'cli',
            action: 'IMPORT_RUN',
            subjectType: 'legacy_db',
            subjectId: $sourceDb,
            after: $summary,
        );

        \WP_CLI::success('Import complete. Summary:');
        foreach ($summary as $section => $data) {
            \WP_CLI::log('  ' . $section . ': ' . (is_scalar($data) ? (string) $data : wp_json_encode($data)));
        }
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{imported:int, skipped:array<string,string>, forced_modes:array<string,string>}
     */
    private function importConfig(\PDO $source, ConfigRepository $target, Env $env, bool $dryRun, bool $allowLiveDev): array
    {
        $rows = $source->query("SELECT config_key, config_value FROM PEOFT_CONFIG ORDER BY config_key")->fetchAll();

        $imported = 0;
        $skipped = [];

        foreach ($rows as $row) {
            $legacyKey = (string) $row['config_key'];
            $value = $row['config_value'];

            // Special handling: JSON blobs
            if (LegacyConfigKeyMap::isSpecial($legacyKey)) {
                if ($legacyKey === 'phpmailer_config') {
                    if (!$env->isProd()) {
                        $skipped[$legacyKey] = 'non-prod import: production SMTP config skipped by policy';
                        continue;
                    }
                    $decoded = json_decode((string) $value, true);
                    if (!is_array($decoded)) {
                        $skipped[$legacyKey] = 'phpmailer_config is not valid JSON';
                        continue;
                    }
                    foreach ($decoded as $subKey => $subVal) {
                        $fq = 'mailer.' . $subKey;
                        if (!$dryRun) {
                            $target->set($fq, $subVal, updatedBy: 'import:from-legacy-db');
                        }
                        $imported++;
                    }
                    continue;
                }
            }

            $fq = LegacyConfigKeyMap::to2($legacyKey);
            if ($fq === null) {
                $skipped[$legacyKey] = 'no mapping (key deleted or unknown)';
                continue;
            }

            // Validate Stripe webhook secret before persisting
            if ($fq === 'stripe.webhook_secret') {
                if (!is_string($value) || !preg_match('/^whsec_[A-Za-z0-9]{20,}$/', $value)) {
                    $skipped[$legacyKey] = 'value is not a valid whsec_ secret (1.0 stores a placeholder); set manually in /wp-content/peoft-env.php';
                    continue;
                }
            }

            // Decode JSON values for catalog.prices / catalog.products
            if (is_string($value) && ($value !== '') && ($value[0] === '{' || $value[0] === '[')) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            }

            if (!$dryRun) {
                try {
                    $target->set($fq, $value, updatedBy: 'import:from-legacy-db');
                } catch (\Throwable $e) {
                    $skipped[$legacyKey] = 'set() rejected: ' . $e->getMessage();
                    continue;
                }
            }
            $imported++;
        }

        // Force mock/demo modes in non-prod (the critical safety net)
        $forced = [];
        if (!$env->isProd() && !$allowLiveDev) {
            $forced['circle.mode'] = 'mock';
            $forced['activecampaign.mode'] = 'mock';
            $forced['szamlazz.mode'] = 'demo';
            $forced['stripe.mode'] = 'live'; // Stripe uses real test mode, which is 'live' from our perspective
            $forced['mailer.mode'] = 'mock';
            foreach ($forced as $fq => $mode) {
                if (!$dryRun) {
                    $target->set($fq, $mode, updatedBy: 'import:from-legacy-db (safety override)');
                }
            }
        }

        return [
            'imported'     => $imported,
            'skipped'      => $skipped,
            'forced_modes' => $forced,
        ];
    }

    /**
     * @return array{imported:int, skipped:int}
     */
    private function importWebhookEvents(\PDO $source, \wpdb $wpdb, Env $env, bool $dryRun): array
    {
        $table = $wpdb->prefix . 'peoft_webhook_events';
        $stmt = $source->query('SELECT event_id, received_at FROM PEOFT_STRIPE_EVENTS ORDER BY received_at ASC');

        $imported = 0;
        $skipped = 0;
        $batch = [];
        $batchSize = 500;

        while ($row = $stmt->fetch()) {
            $batch[] = [
                'event_id'     => (string) $row['event_id'],
                'source'       => 'legacy_1.0',
                'env'          => $env->value,
                'received_at'  => (string) ($row['received_at'] ?? gmdate('Y-m-d H:i:s')),
                'payload_hash' => null,
            ];
            if (count($batch) >= $batchSize) {
                [$i, $s] = $this->flushEvents($wpdb, $table, $batch, $dryRun);
                $imported += $i;
                $skipped += $s;
                $batch = [];
            }
        }
        if ($batch !== []) {
            [$i, $s] = $this->flushEvents($wpdb, $table, $batch, $dryRun);
            $imported += $i;
            $skipped += $s;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @param list<array<string,mixed>> $batch
     * @return array{0:int,1:int}
     */
    private function flushEvents(\wpdb $wpdb, string $table, array $batch, bool $dryRun): array
    {
        if ($dryRun) {
            return [count($batch), 0];
        }
        $imported = 0;
        $skipped = 0;
        foreach ($batch as $row) {
            $result = $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO `{$table}` (event_id, source, env, received_at, payload_hash) VALUES (%s, %s, %s, %s, %s)",
                    $row['event_id'], $row['source'], $row['env'], $row['received_at'], $row['payload_hash']
                )
            );
            if ($result === 0) {
                $skipped++;
            } elseif ($result !== false) {
                $imported++;
            }
        }
        return [$imported, $skipped];
    }

    /**
     * @return array{seeded_order_value:int|null}
     */
    private function importCounters(\PDO $source, \wpdb $wpdb, Env $env, bool $dryRun): array
    {
        $row = $source->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM PEOFT_COUNTERS')->fetch();
        $nextValue = (int) ($row['max_id'] ?? 0) + 1;

        if ($dryRun) {
            return ['seeded_order_value' => $nextValue];
        }

        $table = $wpdb->prefix . 'peoft_counters';
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$table}` (counter_key, env, value, updated_at) VALUES ('order', %s, %d, %s)
                 ON DUPLICATE KEY UPDATE value = GREATEST(value, VALUES(value)), updated_at = VALUES(updated_at)",
                $env->value,
                $nextValue,
                $now
            )
        );
        return ['seeded_order_value' => $nextValue];
    }

    /**
     * @return array{imported:int}
     */
    private function importEmailTemplates(\PDO $source, \wpdb $wpdb, Env $env, bool $dryRun): array
    {
        $rows = $source->query('SELECT name, subject, body FROM PEOFT_EMAIL_TEMPLATES ORDER BY name')->fetchAll();
        $table = $wpdb->prefix . 'peoft_email_templates';
        $imported = 0;
        foreach ($rows as $row) {
            $slug = (string) $row['name'];
            $subject = (string) $row['subject'];
            $body = (string) $row['body'];
            $vars = $this->extractPlaceholders($subject . ' ' . $body);

            if (!$dryRun) {
                $wpdb->query(
                    $wpdb->prepare(
                        "INSERT INTO `{$table}` (env, slug, subject, body, variables_json, updated_at, updated_by)
                         VALUES (%s, %s, %s, %s, %s, %s, %s)
                         ON DUPLICATE KEY UPDATE
                            subject = VALUES(subject),
                            body = VALUES(body),
                            variables_json = VALUES(variables_json),
                            updated_at = VALUES(updated_at),
                            updated_by = VALUES(updated_by)",
                        $env->value,
                        $slug,
                        $subject,
                        $body,
                        wp_json_encode($vars),
                        gmdate('Y-m-d H:i:s'),
                        'import:from-legacy-db'
                    )
                );
            }
            $imported++;
        }
        return ['imported' => $imported];
    }

    /**
     * Extract unique {{placeholder}} names from a template string.
     * Triple-brace {{{raw}}} is also captured (interior name only).
     *
     * @return list<string>
     */
    private function extractPlaceholders(string $text): array
    {
        $out = [];
        if (preg_match_all('/\{\{\{?\s*([a-zA-Z0-9_.-]+)\s*\}?\}\}/', $text, $matches) === false) {
            return [];
        }
        foreach ($matches[1] as $name) {
            $name = trim((string) $name);
            if ($name !== '' && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        sort($out);
        return $out;
    }

    private function openSourcePdoReadOnly(string $dbName): \PDO
    {
        if (!defined('DB_HOST') || !defined('DB_USER')) {
            throw new \RuntimeException('DB_HOST/DB_USER not available.');
        }
        $host = (string) constant('DB_HOST');
        $user = (string) constant('DB_USER');
        $pass = defined('DB_PASSWORD') ? (string) constant('DB_PASSWORD') : '';

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
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $dbName)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName);

        $pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        // Start a read-only transaction so accidental writes fail.
        $pdo->query('SET SESSION TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        return $pdo;
    }
}
