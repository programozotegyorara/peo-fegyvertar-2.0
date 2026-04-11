<?php

declare(strict_types=1);

namespace Peoft\Cli\Commands;

use Peoft\Core\Container;
use Peoft\Orchestrator\Worker\Worker;

defined('ABSPATH') || exit;

/**
 * `wp peoft worker:tick [--batch=20] [--max-runtime=55]`
 *
 * Drains up to `--batch` pending tasks, running each through the Dispatcher.
 * Intended to be invoked once per minute from system cron on DEV/UAT/PROD:
 *
 *   * * * * * /usr/bin/flock -n /tmp/peoft-worker-dev.lock -c \
 *     'cd /srv/www/fegyvertar2 && wp peoft worker:tick --batch=20 --max-runtime=55'
 *
 * Exit code is always 0 so a single bad task doesn't kill the cron loop;
 * inspect the audit log or the Tasks Inbox for per-task outcomes.
 */
final class WorkerTickCommand
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
        $batch = (int) ($assoc['batch'] ?? 20);
        $maxRuntime = (int) ($assoc['max-runtime'] ?? 55);

        if ($batch < 1 || $batch > 500) {
            \WP_CLI::error('--batch must be between 1 and 500.');
            return;
        }
        if ($maxRuntime < 5 || $maxRuntime > 600) {
            \WP_CLI::error('--max-runtime must be between 5 and 600 seconds.');
            return;
        }

        $worker = $this->container->get(Worker::class);
        $result = $worker->tick($batch, $maxRuntime);

        \WP_CLI::log(sprintf(
            'orphans_swept=%d claimed=%d done=%d skipped=%d retry=%d dead=%d runtime=%dms stopped_early=%s',
            $result['orphans_swept'],
            $result['claimed'],
            $result['done'],
            $result['skipped'],
            $result['retry'],
            $result['dead'],
            $result['runtime_ms'],
            $result['stopped_early'] ? 'yes' : 'no'
        ));

        if ($result['dead'] > 0) {
            \WP_CLI::warning("{$result['dead']} task(s) marked dead this tick — check the audit log.");
        }
        \WP_CLI::success('tick complete');
    }
}
