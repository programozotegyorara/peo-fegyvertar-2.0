<?php

declare(strict_types=1);

namespace Peoft\Cli;

use Peoft\Cli\Commands\AuditPruneCommand;
use Peoft\Cli\Commands\DevFireWebhookCommand;
use Peoft\Cli\Commands\DwhRebuildCommand;
use Peoft\Cli\Commands\ImportCutoverDateCommand;
use Peoft\Cli\Commands\ImportFromLegacyDbCommand;
use Peoft\Cli\Commands\MigrateCommand;
use Peoft\Cli\Commands\ValidateImportCommand;
use Peoft\Cli\Commands\WorkerRunOnceCommand;
use Peoft\Cli\Commands\WorkerTickCommand;
use Peoft\Core\Container;

defined('ABSPATH') || exit;

/**
 * Registers all wp-cli commands under the `peoft` namespace.
 * Only called from Kernel::boot() when WP_CLI is defined.
 */
final class CliBootstrap
{
    public static function register(Container $container): void
    {
        if (!class_exists(\WP_CLI::class)) {
            return;
        }

        \WP_CLI::add_command('peoft audit:prune', static function (array $args, array $assoc) use ($container): void {
            (new AuditPruneCommand($container))->__invoke($args, $assoc);
        }, [
            'shortdesc' => 'Prune peoft_audit_log rows older than N days, optionally archiving first.',
            'synopsis'  => [
                ['type' => 'assoc', 'name' => 'older-than-days', 'description' => 'Age threshold in days (default: 365).', 'optional' => true],
                ['type' => 'assoc', 'name' => 'archive-path',    'description' => 'Write gzipped NDJSON to this directory before delete.', 'optional' => true],
                ['type' => 'flag',  'name' => 'dry-run',         'description' => 'Show the count of rows that would be pruned, without deleting.', 'optional' => true],
            ],
        ]);

        \WP_CLI::add_command('peoft migrate', static function (array $args, array $assoc) use ($container): void {
            (new MigrateCommand($container))->__invoke($args, $assoc);
        }, [
            'shortdesc' => 'Apply any pending schema migrations for the plugin.',
        ]);

        \WP_CLI::add_command('peoft import:from-legacy-db', static function (array $args, array $assoc) use ($container): void {
            (new ImportFromLegacyDbCommand($container))->__invoke($args, $assoc);
        }, [
            'shortdesc' => 'Import 1.0 legacy data into the 2.0 tables. Supports granular --what= selection.',
            'synopsis'  => [
                ['type' => 'assoc', 'name' => 'source-db',      'description' => 'Name of the source MySQL database to read from (read-only).', 'optional' => false],
                ['type' => 'assoc', 'name' => 'to-env',         'description' => 'Target env name (dev|uat|prod). Must match PEOFT_ENV.', 'optional' => false],
                ['type' => 'assoc', 'name' => 'what',           'description' => 'What to import: all | config | events | counters | templates (default: all).', 'optional' => true],
                ['type' => 'flag',  'name' => 'dry-run',        'description' => 'Show what would be written without touching the database.', 'optional' => true],
                ['type' => 'flag',  'name' => 'allow-live-dev', 'description' => 'DANGEROUS: skip the mode-override safety net that forces circle/ac/szamlazz to mock/demo in non-prod.', 'optional' => true],
            ],
        ]);

        \WP_CLI::add_command('peoft import:cutover-date', static function (array $args, array $assoc) use ($container): void {
            (new ImportCutoverDateCommand($container))->__invoke($args, $assoc);
        }, [
            'shortdesc' => 'Set the cutover date that separates "Legacy" from "2.0-managed" data in admin views.',
            'synopsis'  => [
                ['type' => 'assoc', 'name' => 'date', 'description' => 'Cutover date in YYYY-MM-DD format.', 'optional' => false],
            ],
        ]);

        \WP_CLI::add_command('peoft import:validate', static function (array $args, array $assoc) use ($container): void {
            (new ValidateImportCommand($container))->__invoke($args, $assoc);
        }, [
            'shortdesc' => 'Post-import sanity check: row counts, critical config keys, counter, templates.',
        ]);

        \WP_CLI::add_command('peoft worker:tick', static function (array $args, array $assoc) use ($container): void {
            (new WorkerTickCommand($container))->__invoke($args, $assoc);
        }, [
            'shortdesc' => 'Drain pending tasks: claim a batch, run each through its handler, apply state transitions.',
            'synopsis'  => [
                ['type' => 'assoc', 'name' => 'batch',       'description' => 'Max tasks to claim this tick (1-500, default 20).', 'optional' => true],
                ['type' => 'assoc', 'name' => 'max-runtime', 'description' => 'Max runtime per tick in seconds (5-600, default 55).', 'optional' => true],
            ],
        ]);

        \WP_CLI::add_command('peoft worker:run-task', static function (array $args, array $assoc) use ($container): void {
            (new WorkerRunOnceCommand($container))->__invoke($args, $assoc);
        }, [
            'shortdesc' => 'Run a single task by id, bypassing the claim transaction.',
            'synopsis'  => [
                ['type' => 'positional', 'name' => 'task_id', 'description' => 'Task id to run.', 'optional' => false],
                ['type' => 'flag', 'name' => 'reset', 'description' => 'Reset a terminal (done/dead) task to pending before running.', 'optional' => true],
            ],
        ]);

        \WP_CLI::add_command('peoft dev:fire-webhook', static function (array $args, array $assoc) use ($container): void {
            (new DevFireWebhookCommand($container))->__invoke($args, $assoc);
        }, [
            'shortdesc' => 'DEV-only: sign a fixture JSON with the configured whsec and POST it to the local WebhookController.',
            'synopsis'  => [
                ['type' => 'assoc', 'name' => 'fixture',       'description' => 'Fixture slug (file name without .json) under tests/Fixtures/stripe/.', 'optional' => false],
                ['type' => 'assoc', 'name' => 'target-url',    'description' => 'Override destination URL (defaults to the local WP install). Must be localhost or this install. (`--url` is reserved by WP-CLI.)', 'optional' => true],
                ['type' => 'flag',  'name' => 'bad-signature', 'description' => 'Sign with a junk secret to exercise the WEBHOOK_SIG_FAILED path.', 'optional' => true],
            ],
        ]);

        \WP_CLI::add_command('peoft dwh:rebuild', static function (array $args, array $assoc) use ($container): void {
            (new DwhRebuildCommand($container))->__invoke($args, $assoc);
        }, [
            'shortdesc' => 'Run the full DWH rebuild: Stripe + Számlázz + Circle extract → snapshots → daily KPIs.',
        ]);
    }
}
