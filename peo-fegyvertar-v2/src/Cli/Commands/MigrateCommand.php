<?php

declare(strict_types=1);

namespace Peoft\Cli\Commands;

use Peoft\Core\Container;
use Peoft\Core\Db\Migrator;

defined('ABSPATH') || exit;

/**
 * `wp peoft migrate`
 *
 * Apply any pending schema migrations. Also runs on plugin activation, so
 * this command is mostly for development: re-running after adding a new
 * migration without reactivating the plugin.
 */
final class MigrateCommand
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
        $migrator = $this->container->get(Migrator::class);
        $applied = $migrator->appliedVersions();
        $available = $migrator->availableVersions();
        $pending = array_values(array_diff($available, $applied));

        \WP_CLI::log('Applied so far: ' . (count($applied) === 0 ? '(none)' : implode(', ', $applied)));
        \WP_CLI::log('Pending:        ' . (count($pending) === 0 ? '(none)' : implode(', ', $pending)));

        if ($pending === []) {
            \WP_CLI::success('No migrations to run.');
            return;
        }

        $justRan = $migrator->up();
        \WP_CLI::success('Applied ' . count($justRan) . ' migration(s): ' . implode(', ', $justRan));
    }
}
