<?php

declare(strict_types=1);

namespace Peoft\Admin\Rest;

use Peoft\Core\Config\Config;
use Peoft\Core\Container;
use Peoft\Dwh\DwhRunner;
use Peoft\Orchestrator\Worker\Worker;

defined('ABSPATH') || exit;

/**
 * URL-alapú cron trigger végpontok.
 *
 * Hosting panelek (pl. cPanel, Plesk, magyar tárhelyek) gyakran csak
 * URL-es cront (wget) támogatnak, nem shell parancsot. Ezek a végpontok
 * lehetővé teszik a worker és a DWH rebuild futtatását URL-en keresztül.
 *
 * Védelem: `secret` query paraméter, ami a `system.cron_secret` config
 * értékkel egyezik. Nem WP nonce (mert nincs bejelentkezett user), hanem
 * egy hosszú random token amit a szerver adminisztrátor ismer.
 *
 * Végpontok:
 *   GET /wp-json/peo-fegyvertar/v2/cron/worker-tick?secret=<token>
 *   GET /wp-json/peo-fegyvertar/v2/cron/dwh-rebuild?secret=<token>
 */
final class CronTriggerRoutes
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(): void
    {
        $ns = AdminRestController::NAMESPACE;

        register_rest_route($ns, '/cron/worker-tick', [
            'methods'             => 'GET',
            'callback'            => [$this, 'workerTick'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($ns, '/cron/dwh-rebuild', [
            'methods'             => 'GET',
            'callback'            => [$this, 'dwhRebuild'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function workerTick(\WP_REST_Request $req): \WP_REST_Response
    {
        if (!$this->checkSecret($req)) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'invalid_secret'], 403);
        }

        $worker = $this->container->get(Worker::class);
        $result = $worker->tick(batchSize: 20, maxRuntimeSeconds: 55);

        return new \WP_REST_Response([
            'ok'      => true,
            'trigger' => 'cron-url',
            'result'  => $result,
        ], 200);
    }

    public function dwhRebuild(\WP_REST_Request $req): \WP_REST_Response
    {
        if (!$this->checkSecret($req)) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'invalid_secret'], 403);
        }

        $runner = $this->container->get(DwhRunner::class);
        $result = $runner->run();

        return new \WP_REST_Response([
            'ok'      => true,
            'trigger' => 'cron-url',
            'run_id'  => $result['run_id'],
            'error'   => $result['error'],
        ], 200);
    }

    private function checkSecret(\WP_REST_Request $req): bool
    {
        $provided = (string) $req->get_param('secret');
        $expected = (string) Config::get('system.cron_secret', '');
        if ($expected === '' || $provided === '') {
            return false;
        }
        return hash_equals($expected, $provided);
    }
}
