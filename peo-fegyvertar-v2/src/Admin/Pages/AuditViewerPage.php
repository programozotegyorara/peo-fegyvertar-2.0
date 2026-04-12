<?php

declare(strict_types=1);

namespace Peoft\Admin\Pages;

use Peoft\Admin\AdminPage;
use Peoft\Core\Db\Connection;

defined('ABSPATH') || exit;

/**
 * Audit Log Viewer — read-only browse over `peoft_audit_log`.
 *
 * Filters: action, actor, task_id, request_id, date range, free-text search
 * on subject_id/api_url. Row expand shows pretty-printed before/after JSON
 * and API request/response bodies.
 *
 * The "Show related" feature (filter by request_id) lets operators see every
 * audit row produced by a single unit of work — one webhook's entire fan-out,
 * or one manual trigger's complete chain of downstream calls.
 */
final class AuditViewerPage extends AdminPage
{
    public static function slug(): string
    {
        return 'peo-fegyvertar-audit';
    }

    public function title(): string
    {
        return 'Tevékenységnapló';
    }

    public function menuTitle(): string
    {
        return 'Tevékenységnapló';
    }

    protected function renderBody(): void
    {
        /** @var Connection $db */
        $db = $this->container->get(Connection::class);
        $wpdb = $db->wpdb();
        $table = $db->table('peoft_audit_log');

        $action    = isset($_GET['action_filter']) ? sanitize_text_field(wp_unslash((string) $_GET['action_filter'])) : '';
        $actor     = isset($_GET['actor']) ? sanitize_text_field(wp_unslash((string) $_GET['actor'])) : '';
        $taskId    = isset($_GET['task_id']) && $_GET['task_id'] !== '' ? (int) $_GET['task_id'] : 0;
        $requestId = isset($_GET['request_id']) ? sanitize_text_field(wp_unslash((string) $_GET['request_id'])) : '';
        $search    = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
        $limit = 200;

        $conditions = ['1=1'];
        $params = [];
        if ($action !== '') {
            $conditions[] = 'action = %s';
            $params[] = $action;
        }
        if ($actor !== '') {
            $conditions[] = 'actor LIKE %s';
            $params[] = $wpdb->esc_like($actor) . '%';
        }
        if ($taskId > 0) {
            $conditions[] = 'task_id = %d';
            $params[] = $taskId;
        }
        if ($requestId !== '') {
            $conditions[] = 'request_id = %s';
            $params[] = $requestId;
        }
        if ($search !== '') {
            $conditions[] = '(subject_id LIKE %s OR api_url LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = implode(' AND ', $conditions);

        $sql = "SELECT id, occurred_at, env, actor, action, subject_type, subject_id, task_id, request_id,
                       before_json, after_json, api_method, api_url, api_status, api_req_body, api_res_body,
                       duration_ms, error_msg
                  FROM `{$table}`
                 WHERE {$whereSql}
              ORDER BY id DESC
                 LIMIT {$limit}";
        $rows = $params === []
            ? $wpdb->get_results($sql, ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        $rows = $rows ?: [];

        $this->renderFilters($action, $actor, $taskId, $requestId, $search, $wpdb, $table);

        if ($rows === []) {
            echo '<div class="peoft-empty">Nincs találat a megadott szűrőkkel.</div>';
            return;
        }

        $this->renderTable($rows);
    }

    private function renderFilters(string $action, string $actor, int $taskId, string $requestId, string $search, \wpdb $wpdb, string $table): void
    {
        $distinctActions = $wpdb->get_col("SELECT DISTINCT action FROM `{$table}` ORDER BY action ASC") ?: [];

        echo '<form method="get" class="peoft-filters">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::slug()) . '">';

        echo '<label>Művelet: <select name="action_filter">';
        echo '<option value="">(mind)</option>';
        foreach ($distinctActions as $a) {
            $a = (string) $a;
            $sel = $a === $action ? ' selected' : '';
            echo '<option value="' . esc_attr($a) . '"' . $sel . '>' . esc_html($a) . '</option>';
        }
        echo '</select></label>';

        echo '<label>Szereplő: <input type="text" name="actor" value="' . esc_attr($actor) . '" placeholder="stripe / worker / admin:42" size="18"></label>';
        echo '<label>Feladat #: <input type="number" name="task_id" value="' . ($taskId > 0 ? (int) $taskId : '') . '" size="6"></label>';
        echo '<label>Kérés id: <input type="text" name="request_id" value="' . esc_attr($requestId) . '" placeholder="ULID" size="22"></label>';
        echo '<label>Keresés: <input type="text" name="q" value="' . esc_attr($search) . '" placeholder="subject_id / api_url" size="24"></label>';
        echo '<button type="submit" class="button">Szűrés</button>';
        echo ' <a class="button-link" href="' . $this->adminUrl(self::slug()) . '">Alaphelyzet</a>';
        echo '</form>';
    }

    private function renderTable(array $rows): void
    {
        echo '<table class="peoft-list"><thead><tr>';
        echo '<th>#</th><th>Időpont (UTC)</th><th>Szereplő</th><th>Művelet</th><th>Tárgy</th><th>API</th><th>Kérés id</th><th></th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $time = (string) $row['occurred_at'];
            $actor = esc_html((string) $row['actor']);
            $action = esc_html((string) $row['action']);
            $subject = trim(((string) ($row['subject_type'] ?? '')) . ' ' . ((string) ($row['subject_id'] ?? '')));
            $apiMethod = (string) ($row['api_method'] ?? '');
            $apiUrl = (string) ($row['api_url'] ?? '');
            $apiStatus = $row['api_status'] !== null ? (int) $row['api_status'] : null;
            $apiDisplay = '';
            if ($apiMethod !== '' || $apiUrl !== '') {
                $apiDisplay = $apiMethod . ' ' . self::truncate($apiUrl, 55);
                if ($apiStatus !== null) {
                    $apiDisplay .= ' (' . $apiStatus . ')';
                }
            }
            $requestId = (string) ($row['request_id'] ?? '');
            $requestIdShort = $requestId !== '' ? substr($requestId, 0, 12) . '…' : '';

            echo '<tr>';
            echo '<td>' . $id . '</td>';
            echo '<td class="peoft-meta">' . esc_html($time) . '</td>';
            echo '<td><code style="font-size:11px;">' . $actor . '</code></td>';
            echo '<td><code style="font-size:11px;">' . $action . '</code></td>';
            echo '<td>' . esc_html(self::truncate($subject, 50)) . '</td>';
            echo '<td class="peoft-meta">' . esc_html($apiDisplay) . '</td>';
            echo '<td class="peoft-meta">';
            if ($requestId !== '') {
                $relatedUrl = $this->adminUrl(self::slug(), ['request_id' => $requestId]);
                echo '<a href="' . $relatedUrl . '" title="' . esc_attr($requestId) . '">' . esc_html($requestIdShort) . '</a>';
            }
            echo '</td>';
            echo '<td><details><summary style="cursor:pointer;font-size:11px;color:#2563eb;">Részletek</summary>';
            echo self::renderDetails($row);
            echo '</details></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderDetails(array $row): string
    {
        $parts = [];
        foreach (['before_json', 'after_json', 'api_req_body', 'api_res_body', 'error_msg'] as $field) {
            $value = $row[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $pretty = self::prettyPrint((string) $value);
            $parts[] = '<strong>' . esc_html($field) . '</strong>:<pre>' . esc_html($pretty) . '</pre>';
        }
        return implode('', $parts);
    }

    private static function prettyPrint(string $value): string
    {
        $trim = ltrim($value);
        if ($trim === '' || ($trim[0] !== '{' && $trim[0] !== '[')) {
            return $value;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return $value;
        }
        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? $value : $encoded;
    }

    private static function truncate(string $s, int $max): string
    {
        if ($s === '' || strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max) . '…';
    }
}
