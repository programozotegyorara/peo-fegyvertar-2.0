<?php

declare(strict_types=1);

namespace Peoft\Admin\Pages;

use Peoft\Admin\AdminPage;
use Peoft\Orchestrator\Handlers\TaskRegistry;

defined('ABSPATH') || exit;

/**
 * Manual Trigger — lets operators enqueue any registered task type on demand.
 *
 * For each task type in `TaskRegistry::registeredTypes()`, renders a minimal
 * form. Admin picks the task type (single dropdown at the top), edits the
 * `stripe_ref` + `payload JSON` fields, clicks Submit. The form POSTs to
 * /admin/trigger which deduplicates via the idempotency key + writes a
 * `TASK_ENQUEUED` audit row with `actor='admin:{user_id}'`.
 *
 * Per-task_type payload hints are injected as default JSON so admins don't
 * have to remember every handler's payload shape.
 */
final class ManualTriggerPage extends AdminPage
{
    public static function slug(): string
    {
        return 'peo-fegyvertar-trigger';
    }

    public function title(): string
    {
        return 'Manual Trigger';
    }

    public function menuTitle(): string
    {
        return 'Manual Trigger';
    }

    /**
     * Hint payloads — keyed by task_type. Admins can edit these before
     * submitting. Not exhaustive; covers the common-case shapes.
     *
     * @return array<string, array<string,mixed>>
     */
    private function defaultPayloads(): array
    {
        return [
            'email.send_success_purchase' => [
                'template_slug' => 'sikeres_rendeles',
                'to' => 'admin-test@example.test',
                'stripe_customer_id' => 'cus_admin_test',
                'vars' => [
                    'customer_email' => 'admin-test@example.test',
                    'order_id' => 'in_admin_test_001',
                    'total_amount' => '5 000 HUF',
                    'email_subject' => 'Fegyvertár - Sikeres rendelés',
                    'this_year' => (string) gmdate('Y'),
                ],
            ],
            'ac.upsert_contact' => [
                'email' => 'admin-test@example.test',
                'first_name' => 'Admin',
                'last_name' => 'Test',
            ],
            'ac.tag_contact' => [
                'email' => 'admin-test@example.test',
                'tag' => 'FT: ACTIVE',
            ],
            'ac.untag_contact' => [
                'email' => 'admin-test@example.test',
                'tag' => 'FT: DELETED',
            ],
            'circle.enroll_member' => [
                'email' => 'admin-test@example.test',
                'name' => 'Admin Test',
                'access_group_id' => '53741',
                'skip_invitation' => false,
            ],
            'circle.revoke_member' => [
                'email' => 'admin-test@example.test',
                'access_group_id' => '53741',
            ],
            'szamlazz.issue_invoice' => [
                'stripe_invoice_id' => 'in_admin_test_001',
                'currency' => 'HUF',
                'gross_amount_minor' => 500000,
                'buyer' => [
                    'name' => 'Admin Test',
                    'email' => 'admin-test@example.test',
                    'country' => 'HU',
                    'postal_code' => '1051',
                    'city' => 'Budapest',
                    'address_line_1' => 'Teszt utca 1.',
                ],
                'item' => [
                    'name' => 'Online oktatás',
                    'period' => 'Havi',
                ],
            ],
            'szamlazz.issue_storno' => [
                'stripe_charge_id' => 'ch_admin_test_001',
                'stripe_invoice_id' => 'in_admin_test_001',
            ],
            'stripe.update_customer_vat' => [
                'customer_id' => 'cus_admin_test',
                'tax_id_value' => 'HU12345678',
                'tax_id_type' => 'hu_tin',
            ],
            'trial.convert_to_subscription' => [
                'checkout_session_id' => 'cs_admin_test_001',
                'target_price_id' => 'price_admin_test',
            ],
        ];
    }

    protected function renderBody(): void
    {
        /** @var TaskRegistry $registry */
        $registry = $this->container->get(TaskRegistry::class);
        $types = $registry->registeredTypes();
        sort($types);
        $defaults = $this->defaultPayloads();

        echo '<p class="peoft-meta">Every form below posts to <code>/wp-json/peo-fegyvertar/v2/admin/trigger</code> with the current user as <code>admin:{id}</code>. The idempotency key is deterministic per (task_type, env, stripe_ref, timestamp), so re-submitting within a second dedupes.</p>';

        echo '<div class="peoft-filters" style="margin-bottom:20px;">';
        echo '<label>Task type: <select id="peoft-task-type-picker">';
        echo '<option value="">— pick a task type —</option>';
        foreach ($types as $type) {
            echo '<option value="' . esc_attr($type) . '">' . esc_html($type) . '</option>';
        }
        echo '</select></label>';
        echo '<span class="peoft-meta">' . count($types) . ' registered handlers</span>';
        echo '</div>';

        echo '<form id="peoft-trigger-form" style="background:#fff;border:1px solid #e5e7eb;padding:20px;border-radius:4px;display:none;">';
        echo '<input type="hidden" name="task_type" id="peoft-task-type-hidden">';
        echo '<p><label><strong>Stripe ref</strong> <span class="peoft-meta">(invoice id / charge id / subscription id / customer id / session id — used as dedup discriminator)</span></label><br>';
        echo '<input type="text" name="stripe_ref" id="peoft-stripe-ref" style="width:100%;padding:6px;font-size:13px;"></p>';
        echo '<p><label><strong>Payload</strong> <span class="peoft-meta">(JSON — the handler receives this as <code>task.payload</code>)</span></label><br>';
        echo '<textarea name="payload" id="peoft-payload" rows="14" style="width:100%;font-family:SF Mono,Consolas,monospace;font-size:12px;"></textarea></p>';
        echo '<p><button type="submit" class="button button-primary">Enqueue Task</button></p>';
        echo '<div id="peoft-trigger-result"></div>';
        echo '</form>';

        $this->renderInlineScript($defaults);
    }

    /**
     * @param array<string, array<string,mixed>> $defaults
     */
    private function renderInlineScript(array $defaults): void
    {
        $nonce = wp_create_nonce('wp_rest');
        $triggerUrl = esc_url_raw(rest_url('peo-fegyvertar/v2/admin/trigger'));
        $nonceJs = json_encode($nonce);
        $urlJs = json_encode($triggerUrl);
        $defaultsJs = wp_json_encode($defaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo <<<HTML
<script>
(function(){
    const nonce = {$nonceJs};
    const url = {$urlJs};
    const defaults = {$defaultsJs};
    const picker = document.getElementById('peoft-task-type-picker');
    const form = document.getElementById('peoft-trigger-form');
    const hidden = document.getElementById('peoft-task-type-hidden');
    const payloadEl = document.getElementById('peoft-payload');
    const refEl = document.getElementById('peoft-stripe-ref');
    const result = document.getElementById('peoft-trigger-result');

    picker.addEventListener('change', () => {
        const t = picker.value;
        if (!t) {
            form.style.display = 'none';
            return;
        }
        hidden.value = t;
        refEl.value = '';
        const d = defaults[t] || {};
        payloadEl.value = JSON.stringify(d, null, 2);
        result.textContent = '';
        form.style.display = 'block';
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        result.textContent = '';
        let payload;
        try {
            payload = JSON.parse(payloadEl.value);
        } catch (err) {
            showResult('Invalid JSON in payload: ' + err.message, true);
            return;
        }
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    task_type: hidden.value,
                    stripe_ref: refEl.value,
                    payload: payload,
                }),
            });
            const body = await res.json();
            if (!res.ok) {
                showResult('Enqueue failed: ' + (body.error || res.status) +
                           (body.registered ? '. Registered types: ' + body.registered.join(', ') : ''), true);
                return;
            }
            showResult('Enqueued. Task id(s): ' + (body.ids || []).join(', ') +
                       ' (inserted=' + body.inserted + ', deduped=' + body.skipped + ')', false);
        } catch (err) {
            showResult('Network error: ' + err.message, true);
        }
    });

    function showResult(text, isError) {
        result.textContent = '';
        const box = document.createElement('div');
        box.setAttribute('style',
            'padding:10px;border-radius:4px;margin-top:10px;' +
            (isError
                ? 'background:#fee2e2;color:#991b1b;'
                : 'background:#d1fae5;color:#065f46;')
        );
        box.textContent = text;
        result.appendChild(box);
    }
})();
</script>
HTML;
    }
}
