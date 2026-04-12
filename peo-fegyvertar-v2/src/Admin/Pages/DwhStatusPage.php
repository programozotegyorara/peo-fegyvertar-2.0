<?php

declare(strict_types=1);

namespace Peoft\Admin\Pages;

use Peoft\Admin\AdminPage;
use Peoft\Core\Db\Connection;

defined('ABSPATH') || exit;

/**
 * DWH Status — read-only view of the `peoft_dwh_runs` table.
 *
 * Phase D ships this as a list-only page. Phase E wires up the "Trigger
 * rebuild now" and "Abort current run" action buttons once `wp peoft
 * dwh:rebuild` and the extractor classes land.
 *
 * The table is empty until Phase E populates it — the page shows a
 * friendly empty state explaining that.
 */
final class DwhStatusPage extends AdminPage
{
    public static function slug(): string
    {
        return 'peo-fegyvertar-dwh';
    }

    public function title(): string
    {
        return 'DWH Status';
    }

    public function menuTitle(): string
    {
        return 'DWH Status';
    }

    protected function renderBody(): void
    {
        /** @var Connection $db */
        $db = $this->container->get(Connection::class);
        $wpdb = $db->wpdb();
        $table = $db->table('peoft_dwh_runs');

        $rows = $wpdb->get_results(
            "SELECT id, started_at, ended_at, status, stats_json, error_text
               FROM `{$table}`
           ORDER BY id DESC
              LIMIT 30",
            ARRAY_A
        ) ?: [];

        if ($rows === []) {
            echo '<div class="peoft-empty">';
            echo '<p>No DWH runs recorded yet.</p>';
            echo '<p class="peoft-meta">Phase E implements <code>wp peoft dwh:rebuild</code> and wires it to a nightly system cron. Until then, this page is informational only.</p>';
            echo '</div>';
            return;
        }

        echo '<table class="peoft-list"><thead><tr>';
        echo '<th>#</th><th>Started</th><th>Ended</th><th>Duration</th><th>Status</th><th>Stats</th><th>Error</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $started = (string) ($row['started_at'] ?? '');
            $ended = (string) ($row['ended_at'] ?? '');
            $duration = '';
            if ($started !== '' && $ended !== '') {
                $diff = max(0, strtotime($ended) - strtotime($started));
                $duration = $diff . 's';
            }
            $status = (string) ($row['status'] ?? '');
            $statusCls = 'peoft-status-' . esc_attr(match ($status) {
                'running' => 'running',
                'done'    => 'done',
                'failed'  => 'failed',
                default   => 'pending',
            });
            $stats = (string) ($row['stats_json'] ?? '');
            $error = (string) ($row['error_text'] ?? '');

            echo '<tr>';
            echo '<td>' . $id . '</td>';
            echo '<td class="peoft-meta">' . esc_html($started) . '</td>';
            echo '<td class="peoft-meta">' . esc_html($ended) . '</td>';
            echo '<td class="peoft-meta">' . esc_html($duration) . '</td>';
            echo '<td><span class="peoft-status ' . $statusCls . '">' . esc_html($status) . '</span></td>';
            echo '<td class="peoft-meta" style="max-width:300px;">' . esc_html(self::truncate($stats, 100)) . '</td>';
            echo '<td class="peoft-meta" style="max-width:300px;color:#991b1b;">' . esc_html(self::truncate($error, 120)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<p class="peoft-meta" style="margin-top:16px;"><em>Actions (Trigger rebuild, Abort current) ship in Phase E together with the extractor/runner classes.</em></p>';
    }

    private static function truncate(string $s, int $max): string
    {
        if ($s === '' || strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max) . '…';
    }
}
