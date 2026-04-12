<?php

declare(strict_types=1);

namespace Peoft\Cli\Commands;

use Peoft\Core\Container;
use Peoft\Dwh\DwhRunner;

defined('ABSPATH') || exit;

/**
 * `wp peoft dwh:rebuild`
 *
 * Run the full DWH rebuild: Stripe extract → Számlázz extract →
 * Circle extract → daily snapshots → daily KPIs.
 *
 * Intended to be invoked once per night from system cron:
 *   15 2 * * * /usr/bin/flock -n /tmp/peoft-dwh-{env}.lock -c \
 *     'cd /var/www/html && wp peoft dwh:rebuild >> /var/log/peoft/dwh.log 2>&1'
 *
 * Separate from the orchestrator worker — uses its own flock file and
 * never touches peoft_tasks / peoft_webhook_events.
 *
 * Exit code is always 0 so a partial extractor failure doesn't kill the
 * cron loop. Check the DWH Status admin page or peoft_dwh_runs for
 * per-step outcomes.
 */
final class DwhRebuildCommand
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
        \WP_CLI::log('DWH rebuild starting…');

        $runner = $this->container->get(DwhRunner::class);
        $result = $runner->run();

        $runId = $result['run_id'];
        $stats = $result['stats'];
        $error = $result['error'];

        \WP_CLI::log("run_id={$runId}");

        foreach ($stats as $section => $data) {
            $summary = is_array($data) ? wp_json_encode($data) : (string) $data;
            \WP_CLI::log("  {$section}: {$summary}");
        }

        if ($error !== null) {
            \WP_CLI::warning("DWH rebuild completed with errors: {$error}");
        } else {
            \WP_CLI::success('DWH rebuild completed successfully.');
        }
    }
}
