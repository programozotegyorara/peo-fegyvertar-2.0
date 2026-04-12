<?php

declare(strict_types=1);

namespace Peoft\Admin\Pages;

use Peoft\Admin\AdminPage;
use Peoft\Core\Config\ConfigSchema;
use Peoft\Core\Db\Connection;

defined('ABSPATH') || exit;

/**
 * Config Editor — list/edit rows in `peoft_config` for the current env.
 *
 * Secret rows (ConfigSchema::SECRET_KEYS) render as `••••` with a
 * "Reveal" button that prompts for the admin's WP password before
 * returning the clear-text value from the server. Reveal is audited;
 * the value never lives in the DOM longer than the modal display.
 *
 * Non-secret rows can be edited inline — a small inline edit form POSTs
 * to /admin/config/save. Saves are rejected if the key is a host/url
 * field and the new value isn't on the hardcoded allowlist (SSRF guard
 * in ConfigRepository::set).
 *
 * `/wp-content/peoft-env.php` overrides are surfaced as "file-override"
 * badges on affected rows; those rows are read-only because the file
 * wins at load time.
 */
final class ConfigEditorPage extends AdminPage
{
    public static function slug(): string
    {
        return 'peo-fegyvertar-config';
    }

    public function title(): string
    {
        return 'Config Editor';
    }

    public function menuTitle(): string
    {
        return 'Config Editor';
    }

    protected function renderBody(): void
    {
        /** @var Connection $db */
        $db = $this->container->get(Connection::class);
        $wpdb = $db->wpdb();
        $table = $db->table('peoft_config');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT env, config_key, config_value, is_secret, updated_at, updated_by
                   FROM `{$table}`
                  WHERE env = %s
                  ORDER BY config_key ASC",
                $this->env->value
            ),
            ARRAY_A
        ) ?: [];

        $envFileOverrides = $this->detectEnvFileOverrides();

        echo '<p class="peoft-meta">Env: <code>' . esc_html($this->env->value) . '</code> &middot; ' . count($rows) . ' config keys stored in <code>peoft_config</code>';
        if ($envFileOverrides !== []) {
            echo ' &middot; <strong>' . count($envFileOverrides) . '</strong> key(s) overridden by <code>/wp-content/peoft-env.php</code>';
        }
        echo '</p>';

        if ($rows === []) {
            echo '<div class="peoft-empty">No config keys yet. Run <code>wp peoft import:from-legacy-db</code> to seed from 1.0.</div>';
            return;
        }

        echo '<table class="peoft-list"><thead><tr>';
        echo '<th>Key</th><th>Value</th><th>Updated</th><th>By</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $key = (string) $row['config_key'];
            $value = (string) ($row['config_value'] ?? '');
            $isSecret = (int) $row['is_secret'] === 1 || ConfigSchema::isSecret($key);
            $isOverridden = in_array($key, $envFileOverrides, true);
            $updatedAt = (string) ($row['updated_at'] ?? '');
            $updatedBy = (string) ($row['updated_by'] ?? '');

            echo '<tr data-config-key="' . esc_attr($key) . '">';
            echo '<td><code>' . esc_html($key) . '</code>';
            if ($isSecret) {
                echo ' <span class="peoft-status peoft-status-dead" style="background:#991b1b;">SECRET</span>';
            }
            if ($isOverridden) {
                echo ' <span class="peoft-status peoft-status-running">FILE OVERRIDE</span>';
            }
            echo '</td>';

            echo '<td class="peoft-config-value">';
            if ($isSecret) {
                echo '<code>••••••••</code>';
            } else {
                echo '<code>' . esc_html(self::truncate($value, 120)) . '</code>';
            }
            echo '</td>';

            echo '<td class="peoft-meta">' . esc_html($updatedAt) . '</td>';
            echo '<td class="peoft-meta">' . esc_html($updatedBy) . '</td>';

            echo '<td class="peoft-row-actions" style="white-space:nowrap;">';
            if ($isOverridden) {
                echo '<span class="peoft-meta">(file-managed)</span>';
            } elseif ($isSecret) {
                echo '<button type="button" class="peoft-config-reveal" data-key="' . esc_attr($key) . '">Reveal</button>';
            } else {
                echo '<button type="button" class="peoft-config-edit" data-key="' . esc_attr($key) . '" data-value="' . esc_attr($value) . '">Edit</button>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        $this->renderInlineScript();
    }

    /**
     * @return list<string>
     */
    private function detectEnvFileOverrides(): array
    {
        $path = WP_CONTENT_DIR . '/peoft-env.php';
        if (!is_file($path)) {
            return [];
        }
        $data = @include $path;
        if (!is_array($data)) {
            return [];
        }
        $envBlock = $data[$this->env->value] ?? null;
        if (!is_array($envBlock)) {
            return [];
        }
        $keys = [];
        foreach ($envBlock as $section => $kv) {
            if (!is_array($kv)) {
                continue;
            }
            foreach (array_keys($kv) as $k) {
                $keys[] = $section . '.' . $k;
            }
        }
        return $keys;
    }

    private function renderInlineScript(): void
    {
        $nonce = wp_create_nonce('wp_rest');
        $saveUrl = esc_url_raw(rest_url('peo-fegyvertar/v2/admin/config/save'));
        $revealUrl = esc_url_raw(rest_url('peo-fegyvertar/v2/admin/config/reveal'));
        $nonceJs = json_encode($nonce);
        $saveUrlJs = json_encode($saveUrl);
        $revealUrlJs = json_encode($revealUrl);
        // All DOM-building below uses createElement + textContent. We never
        // set innerHTML on anything that could contain user data — the
        // revealed secret especially goes through textContent.
        echo <<<HTML
<script>
(function(){
    const nonce = {$nonceJs};
    const saveUrl = {$saveUrlJs};
    const revealUrl = {$revealUrlJs};

    document.querySelectorAll('.peoft-config-edit').forEach(btn => {
        btn.addEventListener('click', async () => {
            const key = btn.dataset.key;
            const current = btn.dataset.value || '';
            const updated = prompt('New value for ' + key + ':', current);
            if (updated === null || updated === current) return;
            btn.disabled = true;
            try {
                const res = await fetch(saveUrl, {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ config_key: key, config_value: updated }),
                });
                const body = await res.json();
                if (!res.ok) {
                    alert('Save failed: ' + (body.detail || body.error || res.status));
                    btn.disabled = false;
                    return;
                }
                location.reload();
            } catch (e) {
                alert('Network error: ' + e.message);
                btn.disabled = false;
            }
        });
    });

    document.querySelectorAll('.peoft-config-reveal').forEach(btn => {
        btn.addEventListener('click', async () => {
            const key = btn.dataset.key;
            const pwd = prompt('Re-enter your WordPress password to reveal ' + key + ':');
            if (!pwd) return;
            btn.disabled = true;
            btn.textContent = '…';
            try {
                const res = await fetch(revealUrl, {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ config_key: key, password: pwd }),
                });
                const body = await res.json();
                if (!res.ok) {
                    alert('Reveal failed: ' + (body.error || res.status));
                    btn.disabled = false;
                    btn.textContent = 'Reveal';
                    return;
                }
                showRevealModal(key, body.config_value || '');
                btn.disabled = false;
                btn.textContent = 'Reveal';
            } catch (e) {
                alert('Network error: ' + e.message);
                btn.disabled = false;
                btn.textContent = 'Reveal';
            }
        });
    });

    // DOM-only modal: every text node is set via textContent, never innerHTML,
    // so a revealed secret containing <script> or "> is rendered as literal
    // characters and never parsed as HTML.
    function showRevealModal(key, value) {
        const modal = document.createElement('div');
        modal.setAttribute('style',
            'position:fixed;top:20%;left:50%;transform:translateX(-50%);' +
            'background:#fff;border:2px solid #dc2626;padding:20px;z-index:10000;' +
            'box-shadow:0 4px 20px rgba(0,0,0,0.3);max-width:600px;word-break:break-all;'
        );

        const heading = document.createElement('h3');
        heading.setAttribute('style', 'margin:0 0 10px;color:#dc2626;');
        heading.textContent = 'Revealed: ' + key;
        modal.appendChild(heading);

        const pre = document.createElement('pre');
        pre.setAttribute('style',
            'background:#fef3c7;padding:10px;border-radius:4px;' +
            'font-size:13px;white-space:pre-wrap;'
        );
        pre.textContent = value;
        modal.appendChild(pre);

        const note = document.createElement('p');
        note.className = 'peoft-meta';
        note.setAttribute('style', 'margin:8px 0 0;color:#6b7280;');
        note.textContent = 'This window will auto-close in 30s. The reveal was audited.';
        modal.appendChild(note);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.setAttribute('style', 'margin-top:10px;');
        closeBtn.textContent = 'Close';
        closeBtn.addEventListener('click', () => modal.remove());
        modal.appendChild(closeBtn);

        document.body.appendChild(modal);
        setTimeout(() => modal.remove(), 30000);
    }
})();
</script>
HTML;
    }

    private static function truncate(string $s, int $max): string
    {
        if ($s === '' || strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max) . '…';
    }
}
