<?php

declare(strict_types=1);

namespace Peoft\Admin\Pages;

use Peoft\Admin\AdminPage;
use Peoft\Core\Db\Connection;

defined('ABSPATH') || exit;

/**
 * Email Template Editor — list + edit view for `peoft_email_templates`.
 *
 * **List mode** (default): table of all templates for the current env with
 * slug, subject, body size, declared-var count, updated_at, and an Edit link.
 *
 * **Edit mode** (?slug=...): two-pane form with subject input + HTML body
 * textarea, declared-variables sidebar, and a Save button that POSTs to
 * /admin/templates/save. Validation happens server-side; error responses
 * include the list of undeclared `{{placeholders}}` found in the body so
 * the editor can highlight them.
 *
 * Preview / "send test" is a Phase E/later enhancement. For now the editor
 * just ships the core save + declared-var validation loop.
 */
final class EmailTemplateEditorPage extends AdminPage
{
    public static function slug(): string
    {
        return 'peo-fegyvertar-templates';
    }

    public function title(): string
    {
        return 'E-mail sablonok';
    }

    public function menuTitle(): string
    {
        return 'E-mail sablonok';
    }

    protected function renderBody(): void
    {
        $slug = isset($_GET['slug']) ? sanitize_text_field(wp_unslash((string) $_GET['slug'])) : '';
        if ($slug !== '' && preg_match('/^[a-z0-9_]+$/', $slug) === 1) {
            $this->renderEditForm($slug);
            return;
        }
        $this->renderList();
    }

    private function renderList(): void
    {
        /** @var Connection $db */
        $db = $this->container->get(Connection::class);
        $wpdb = $db->wpdb();
        $table = $db->table('peoft_email_templates');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT slug, subject, LENGTH(body) AS body_len, variables_json, updated_at, updated_by
                   FROM `{$table}`
                  WHERE env = %s
                  ORDER BY slug ASC",
                $this->env->value
            ),
            ARRAY_A
        ) ?: [];

        echo '<p class="peoft-meta">Környezet: <code>' . esc_html($this->env->value) . '</code> &middot; ' . count($rows) . ' sablon a <code>peoft_email_templates</code> táblában</p>';

        if ($rows === []) {
            echo '<div class="peoft-empty">Még nincsenek sablonok. Futtasd: <code>wp peoft import:from-legacy-db --what=all</code></div>';
            return;
        }

        echo '<table class="peoft-list"><thead><tr>';
        echo '<th>Azonosító</th><th>Tárgy</th><th>Törzs</th><th>Változók</th><th>Módosítva</th><th></th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $slug = (string) $row['slug'];
            $subject = (string) ($row['subject'] ?? '');
            $bodyLen = (int) $row['body_len'];
            $declared = json_decode((string) ($row['variables_json'] ?? '[]'), true) ?: [];
            $varCount = is_array($declared) ? count($declared) : 0;
            $updatedAt = (string) ($row['updated_at'] ?? '');
            $updatedBy = (string) ($row['updated_by'] ?? '');
            $editUrl = $this->adminUrl(self::slug(), ['slug' => $slug]);

            echo '<tr>';
            echo '<td><code>' . esc_html($slug) . '</code></td>';
            echo '<td>' . esc_html(self::truncate($subject, 60)) . '</td>';
            echo '<td class="peoft-meta">' . number_format($bodyLen) . ' karakter</td>';
            echo '<td class="peoft-meta">' . $varCount . ' deklarált</td>';
            echo '<td class="peoft-meta">' . esc_html($updatedAt) . '<br>' . esc_html($updatedBy) . '</td>';
            echo '<td><a class="button" href="' . $editUrl . '">Szerkesztés</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function renderEditForm(string $slug): void
    {
        /** @var Connection $db */
        $db = $this->container->get(Connection::class);
        $wpdb = $db->wpdb();
        $table = $db->table('peoft_email_templates');

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT slug, subject, body, variables_json FROM `{$table}` WHERE env=%s AND slug=%s LIMIT 1",
                $this->env->value,
                $slug
            ),
            ARRAY_A
        );
        if ($row === null) {
            echo '<div class="peoft-empty">Template <code>' . esc_html($slug) . '</code> not found in env <code>' . esc_html($this->env->value) . '</code>.</div>';
            echo '<p><a class="button" href="' . $this->adminUrl(self::slug()) . '">← Vissza a listához</a></p>';
            return;
        }

        $subject = (string) $row['subject'];
        $body = (string) $row['body'];
        $declared = json_decode((string) ($row['variables_json'] ?? '[]'), true) ?: [];
        if (!is_array($declared)) {
            $declared = [];
        }
        $declaredJson = wp_json_encode(array_values(array_filter($declared, 'is_string')));

        echo '<p><a href="' . $this->adminUrl(self::slug()) . '">← Vissza a listához</a></p>';
        echo '<h2>Szerkesztés: <code>' . esc_html($slug) . '</code></h2>';
        echo '<div style="display:grid;grid-template-columns:1fr 300px;gap:20px;">';

        // Main edit form (left column)
        echo '<div>';
        echo '<form id="peoft-template-form">';
        echo '<input type="hidden" name="slug" value="' . esc_attr($slug) . '">';
        echo '<p><label><strong>Tárgy</strong></label><br>';
        echo '<input type="text" name="subject" value="' . esc_attr($subject) . '" style="width:100%;padding:6px;font-size:14px;"></p>';
        echo '<p><label><strong>HTML törzs</strong></label><br>';
        echo '<textarea name="body" rows="20" style="width:100%;font-family:SF Mono,Consolas,monospace;font-size:12px;">' . esc_textarea($body) . '</textarea></p>';
        echo '<p><label><strong>Deklarált változók (JSON tömb)</strong></label><br>';
        echo '<textarea name="variables_json" rows="3" style="width:100%;font-family:SF Mono,Consolas,monospace;font-size:12px;">' . esc_textarea($declaredJson) . '</textarea>';
        echo '<br><span class="peoft-meta">Tartalmaznia kell minden <code>{{placeholder}}</code> a tárgyban vagy törzsben használt helyőrzőt. A szerver elutasítja az eltéréseket.</span></p>';
        echo '<p><button type="submit" class="button button-primary">Sablon mentése</button> ';
        echo '<a class="button" href="' . $this->adminUrl(self::slug()) . '">Mégse</a></p>';
        echo '<div id="peoft-template-result" style="margin-top:10px;"></div>';
        echo '</form>';
        echo '</div>';

        // Declared-variables sidebar (right column)
        echo '<div>';
        echo '<h3 style="margin-top:0;">Deklarált változók</h3>';
        echo '<p class="peoft-meta">Ezeknek a helyőrzőknek szerepelniük kell a törzsben:</p>';
        echo '<ul id="peoft-declared-list" style="font-family:SF Mono,Consolas,monospace;font-size:12px;line-height:1.8;">';
        foreach ($declared as $varName) {
            if (!is_string($varName)) {
                continue;
            }
            echo '<li><code>{{' . esc_html($varName) . '}}</code></li>';
        }
        echo '</ul>';
        echo '</div>';

        echo '</div>'; // grid end

        $this->renderInlineScript();
    }

    private function renderInlineScript(): void
    {
        $nonce = wp_create_nonce('wp_rest');
        $saveUrl = esc_url_raw(rest_url('peo-fegyvertar/v2/admin/templates/save'));
        $nonceJs = json_encode($nonce);
        $saveUrlJs = json_encode($saveUrl);
        echo <<<HTML
<script>
(function(){
    const form = document.getElementById('peoft-template-form');
    if (!form) return;
    const result = document.getElementById('peoft-template-result');
    const nonce = {$nonceJs};
    const saveUrl = {$saveUrlJs};

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        result.textContent = '';
        const fd = new FormData(form);
        const payload = {
            slug: fd.get('slug'),
            subject: fd.get('subject'),
            body: fd.get('body'),
            variables_json: fd.get('variables_json'),
        };
        try {
            const res = await fetch(saveUrl, {
                method: 'POST',
                headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const body = await res.json();
            result.textContent = '';
            const msg = document.createElement('div');
            if (res.ok) {
                msg.setAttribute('style', 'padding:10px;background:#d1fae5;color:#065f46;border-radius:4px;');
                msg.textContent = 'Mentve. Deklarált ' + (body.declared_count || 0) + ' változó.';
            } else {
                msg.setAttribute('style', 'padding:10px;background:#fee2e2;color:#991b1b;border-radius:4px;');
                let t = 'Mentés sikertelen: ' + (body.error || res.status);
                if (Array.isArray(body.missing) && body.missing.length > 0) {
                    t += '. A törzsben használt, de nem deklarált helyőrzők: ' + body.missing.join(', ');
                }
                msg.textContent = t;
            }
            result.appendChild(msg);
        } catch (e) {
            const msg = document.createElement('div');
            msg.setAttribute('style', 'padding:10px;background:#fee2e2;color:#991b1b;border-radius:4px;');
            msg.textContent = 'Hálózati hiba: ' + e.message;
            result.appendChild(msg);
        }
    });
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
