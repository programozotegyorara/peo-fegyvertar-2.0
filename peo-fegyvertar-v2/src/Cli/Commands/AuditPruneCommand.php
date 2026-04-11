<?php

declare(strict_types=1);

namespace Peoft\Cli\Commands;

use Peoft\Audit\AuditRepository;
use Peoft\Core\Container;

defined('ABSPATH') || exit;

/**
 * `wp peoft audit:prune [--older-than-days=N] [--archive-path=P] [--dry-run]`
 */
final class AuditPruneCommand
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
        $olderThan = (int) ($assoc['older-than-days'] ?? 365);
        $archivePath = isset($assoc['archive-path']) ? (string) $assoc['archive-path'] : null;
        $dryRun = !empty($assoc['dry-run']);

        if ($olderThan < 1) {
            \WP_CLI::error('--older-than-days must be at least 1.');
            return;
        }

        $repo = $this->container->get(AuditRepository::class);

        if ($dryRun) {
            $count = $repo->prune($olderThan, null, dryRun: true);
            \WP_CLI::success("dry-run: {$count} row(s) older than {$olderThan} days would be deleted.");
            return;
        }

        $deleted = $repo->prune($olderThan, $archivePath, dryRun: false);
        $msg = "Deleted {$deleted} audit row(s) older than {$olderThan} days.";
        if ($archivePath !== null) {
            $msg .= " Archived to: {$archivePath}";
        }
        \WP_CLI::success($msg);
    }
}
