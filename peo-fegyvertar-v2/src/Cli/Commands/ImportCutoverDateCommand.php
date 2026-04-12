<?php

declare(strict_types=1);

namespace Peoft\Cli\Commands;

use Peoft\Audit\AuditLog;
use Peoft\Core\Config\Config;
use Peoft\Core\Config\ConfigRepository;
use Peoft\Core\Container;

defined('ABSPATH') || exit;

/**
 * `wp peoft import:cutover-date --date=YYYY-MM-DD`
 *
 * Sets `system.cutover_date` in peoft_config for the current env. This date
 * marks the boundary between "Legacy (pre-cutover)" and "2.0-managed" data
 * in every admin view.
 *
 * Every admin page that shows historical data (Reconciliation, Audit Viewer
 * filtered by stripe_ref lookups, DWH KPI page) compares `created_at`
 * against this date and renders a gray "Legacy" badge for older records.
 *
 * Once set, a permanent banner appears on every admin page showing the
 * cutover date for the env.
 */
final class ImportCutoverDateCommand
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
        $date = (string) ($assoc['date'] ?? '');
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            \WP_CLI::error('--date=YYYY-MM-DD is required. Example: --date=2026-04-15');
            return;
        }

        $ts = strtotime($date);
        if ($ts === false || $ts < strtotime('2020-01-01')) {
            \WP_CLI::error("Date '{$date}' is invalid or too far in the past.");
            return;
        }

        $repo = $this->container->get(ConfigRepository::class);
        $before = $repo->get('system.cutover_date');
        $repo->set('system.cutover_date', $date, updatedBy: 'cli:import:cutover-date');

        AuditLog::record(
            actor: 'cli',
            action: 'CONFIG_CHANGED',
            subjectType: 'config',
            subjectId: 'system.cutover_date',
            before: ['value' => $before],
            after:  ['value' => $date, 'env' => Config::env()->value],
        );

        \WP_CLI::success("Cutover date set to {$date} for env " . Config::env()->value . '.');
        if ($before !== null) {
            \WP_CLI::log("  Previous value: {$before}");
        }
        \WP_CLI::log('  Admin pages will show "Legacy (pre-cutover)" for records before this date.');
    }
}
