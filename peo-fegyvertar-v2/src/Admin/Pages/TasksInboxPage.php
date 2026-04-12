<?php

declare(strict_types=1);

namespace Peoft\Admin\Pages;

use Peoft\Admin\AdminMenu;
use Peoft\Admin\AdminPage;
use Peoft\Core\Db\Connection;

defined('ABSPATH') || exit;

/**
 * Tasks Inbox — the central operations view of the orchestrator outbox.
 *
 * Replaces 1.0's six admin-completeness / fix-transaction pages with one
 * unified list of every task the plugin has ever enqueued, filterable by
 * status, task_type, stripe_ref, and date.
 *
 * Row actions: Retry, Skip (mark done), Cancel (mark dead), Run now, View
 * audit trail (drill into AuditViewer filtered by task_id).
 *
 * The query runs directly against `wp_peoft_tasks` — no caching, no paging
 * optimization yet (basic LIMIT/OFFSET). At ~5k tasks the unoptimized
 * query is comfortably sub-100ms on InnoDB; revisit if/when we cross 50k.
 */
final class TasksInboxPage extends AdminPage
{
    public static function slug(): string
    {
        return AdminMenu::TOP_SLUG; // the top-level slug lands here
    }

    public function title(): string
    {
        return 'Tasks Inbox';
    }

    public function menuTitle(): string
    {
        return 'Tasks Inbox';
    }

    protected function renderBody(): void
    {
        /** @var Connection $db */
        $db = $this->container->get(Connection::class);
        $wpdb = $db->wpdb();
        $table = $db->table('peoft_tasks');

        $statusFilter    = isset($_GET['status']) ? sanitize_text_field(wp_unslash((string) $_GET['status'])) : '';
        $typeFilter      = isset($_GET['task_type']) ? sanitize_text_field(wp_unslash((string) $_GET['task_type'])) : '';
        $refFilter       = isset($_GET['stripe_ref']) ? sanitize_text_field(wp_unslash((string) $_GET['stripe_ref'])) : '';
        $limit = 100;

        $conditions = ['1=1'];
        $params = [];
        if ($statusFilter !== '') {
            $conditions[] = 'status = %s';
            $params[] = $statusFilter;
        }
        if ($typeFilter !== '') {
            $conditions[] = 'task_type = %s';
            $params[] = $typeFilter;
        }
        if ($refFilter !== '') {
            $conditions[] = 'stripe_ref LIKE %s';
            $params[] = '%' . $wpdb->esc_like($refFilter) . '%';
        }
        $whereSql = implode(' AND ', $conditions);

        $sql = "SELECT id, task_type, status, attempts, next_run_at, stripe_ref, last_error, actor, created_at, started_at, finished_at
                  FROM `{$table}`
                 WHERE {$whereSql}
              ORDER BY id DESC
                 LIMIT {$limit}";
        $rows = $params === []
            ? $wpdb->get_results($sql, ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        $rows = $rows ?: [];

        // Count totals by status for the filter bar summary.
        $counts = $wpdb->get_results("SELECT status, COUNT(*) AS n FROM `{$table}` GROUP BY status", ARRAY_A) ?: [];
        $countMap = [];
        foreach ($counts as $c) {
            $countMap[$c['status']] = (int) $c['n'];
        }

        $this->renderFilters($statusFilter, $typeFilter, $refFilter, $countMap);

        if ($rows === []) {
            echo '<div class="peoft-empty">No tasks match the current filters.</div>';
            return;
        }

        $this->renderTable($rows);

        // Tiny inline script for row actions. Uses fetch() + wp_rest nonce.
        $restNonce = wp_create_nonce('wp_rest');
        $restBase = esc_url_raw(rest_url('peo-fegyvertar/v2/admin/tasks'));
        echo <<<HTML
<script>
(function(){
    const restBase = {$this->jsString($restBase)};
    const nonce = {$this->jsString($restNonce)};
    document.querySelectorAll('.peoft-task-action').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.taskId;
            const op = btn.dataset.op;
            const label = btn.dataset.confirm || op;
            if (!confirm('Confirm: ' + label + ' task #' + id + '?')) return;
            btn.disabled = true;
            btn.textContent = '…';
            try {
                const res = await fetch(restBase + '/' + id + '/' + op, {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                });
                const body = await res.json();
                if (!res.ok) {
                    alert('Failed: ' + (body.error || res.status));
                    btn.disabled = false;
                    btn.textContent = op;
                    return;
                }
                location.reload();
            } catch (e) {
                alert('Network error: ' + e.message);
                btn.disabled = false;
                btn.textContent = op;
            }
        });
    });
})();
</script>
HTML;
    }

    private function renderFilters(string $status, string $type, string $ref, array $countMap): void
    {
        $done    = (int) ($countMap['done']    ?? 0);
        $pending = (int) ($countMap['pending'] ?? 0);
        $running = (int) ($countMap['running'] ?? 0);
        $dead    = (int) ($countMap['dead']    ?? 0);

        $selected = static fn (string $v, string $cur): string => $v === $cur ? ' selected' : '';

        echo '<form method="get" class="peoft-filters">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::slug()) . '">';

        echo '<label>Status: <select name="status">';
        echo '<option value="">(all)</option>';
        foreach (['pending', 'running', 'done', 'failed', 'dead'] as $opt) {
            echo '<option value="' . esc_attr($opt) . '"' . $selected($opt, $status) . '>' . esc_html($opt) . '</option>';
        }
        echo '</select></label>';

        echo '<label>Task type: <input type="text" name="task_type" value="' . esc_attr($type) . '" placeholder="e.g. szamlazz.issue_invoice" size="28"></label>';
        echo '<label>Stripe ref: <input type="text" name="stripe_ref" value="' . esc_attr($ref) . '" placeholder="in_... / ch_... / cus_..." size="28"></label>';
        echo '<button type="submit" class="button">Filter</button>';
        echo ' <a class="button-link" href="' . $this->adminUrl(self::slug()) . '">Reset</a>';

        echo '<span class="peoft-meta" style="margin-left:auto;">';
        echo 'Totals: <strong>' . $done . '</strong> done · <strong>' . $pending . '</strong> pending · <strong>' . $running . '</strong> running · <strong style="color:#991b1b;">' . $dead . '</strong> dead';
        echo '</span>';
        echo '</form>';
    }

    private function renderTable(array $rows): void
    {
        echo '<table class="peoft-list"><thead><tr>';
        echo '<th>#</th><th>Task type</th><th>Status</th><th>Stripe ref</th><th>Attempts</th><th>Next run / Finished</th><th>Last error</th><th>Actions</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $id         = (int) $row['id'];
            $type       = esc_html((string) $row['task_type']);
            $status     = (string) $row['status'];
            $statusCls  = 'peoft-status-' . esc_attr($status);
            $ref        = (string) ($row['stripe_ref'] ?? '');
            $attempts   = (int) $row['attempts'];
            $nextRun    = (string) ($row['next_run_at'] ?? '');
            $finished   = (string) ($row['finished_at'] ?? '');
            $lastError  = (string) ($row['last_error'] ?? '');
            $timeDisplay = $finished !== '' ? 'finished ' . $finished : 'next ' . $nextRun;

            echo '<tr>';
            echo '<td>' . $id . '</td>';
            echo '<td><code>' . $type . '</code></td>';
            echo '<td><span class="peoft-status ' . $statusCls . '">' . esc_html($status) . '</span></td>';
            echo '<td><code style="font-size:11px;">' . esc_html($ref) . '</code></td>';
            echo '<td>' . $attempts . '</td>';
            echo '<td class="peoft-meta">' . esc_html($timeDisplay) . '</td>';
            echo '<td class="peoft-meta" style="max-width:280px;">' . esc_html(self::truncate($lastError, 140)) . '</td>';
            echo '<td class="peoft-row-actions" style="white-space:nowrap;">';
            echo $this->actionButton($id, 'retry',   'Retry');
            echo $this->actionButton($id, 'skip',    'Skip');
            echo $this->actionButton($id, 'cancel',  'Cancel');
            echo $this->actionButton($id, 'run-now', 'Run now');
            echo ' <a class="button-link" href="' . $this->adminUrl('peo-fegyvertar-audit', ['task_id' => $id]) . '">Audit</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function actionButton(int $id, string $op, string $label): string
    {
        return '<button type="button" class="peoft-task-action" data-task-id="' . $id . '" data-op="' . esc_attr($op) . '" data-confirm="' . esc_attr($label) . '">' . esc_html($op) . '</button>';
    }

    private static function truncate(string $s, int $max): string
    {
        if ($s === '' || strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max) . '…';
    }

    private function jsString(string $s): string
    {
        return json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""';
    }
}
