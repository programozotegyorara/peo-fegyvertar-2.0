<?php

declare(strict_types=1);

namespace Peoft\Admin\Pages;

use Peoft\Admin\AdminPage;
use Peoft\Core\Db\Connection;
use Peoft\Integrations\ActiveCampaign\ActiveCampaignClient;
use Peoft\Integrations\Circle\CircleClient;
use Peoft\Integrations\Stripe\CustomerContextLoader;

defined('ABSPATH') || exit;

/**
 * Per-customer Reconciliation — replaces 1.0's ~4,400 lines of admin repair
 * tooling with one page that fetches **live** state across Stripe + Számlázz
 * + Circle + AC + the local task queue and shows them side-by-side.
 *
 * Input: Stripe customer_id OR email.
 * Output: 4-column layout with ✓/✗ indicators per system, plus a panel
 * of the last 10 local tasks for that customer joined on stripe_ref.
 *
 * In DEV mock mode, every "live" fetch returns deterministic stub data so
 * the layout is exercised without real credentials. In UAT/PROD this is
 * the go-to operator tool for "why didn't X get Y".
 */
final class ReconciliationPage extends AdminPage
{
    public static function slug(): string
    {
        return 'peo-fegyvertar-reconcile';
    }

    public function title(): string
    {
        return 'Egyeztetés';
    }

    public function menuTitle(): string
    {
        return 'Egyeztetés';
    }

    protected function renderBody(): void
    {
        $email = isset($_GET['email']) ? sanitize_email(wp_unslash((string) $_GET['email'])) : '';
        $customerId = isset($_GET['customer_id']) ? sanitize_text_field(wp_unslash((string) $_GET['customer_id'])) : '';

        echo '<form method="get" class="peoft-filters" style="margin-bottom:20px;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::slug()) . '">';
        echo '<label>Email: <input type="email" name="email" value="' . esc_attr($email) . '" size="30"></label>';
        echo '<label>— or Customer ID: <input type="text" name="customer_id" value="' . esc_attr($customerId) . '" size="28" placeholder="cus_..."></label>';
        echo '<button type="submit" class="button button-primary">Look up</button>';
        if ($email !== '' || $customerId !== '') {
            echo ' <a class="button-link" href="' . $this->adminUrl(self::slug()) . '">Reset</a>';
        }
        echo '</form>';

        if ($email === '' && $customerId === '') {
            echo '<div class="peoft-empty">Enter an email or customer id to pull live state from all four downstreams.</div>';
            return;
        }

        $this->renderColumns($email, $customerId);
        $this->renderRecentTasks($email, $customerId);
    }

    private function renderColumns(string $email, string $customerId): void
    {
        echo '<div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:16px;margin-bottom:24px;">';

        $this->renderStripeColumn($customerId);
        $this->renderSzamlazzColumn($email, $customerId);
        $this->renderCircleColumn($email);
        $this->renderActiveCampaignColumn($email);

        echo '</div>';
    }

    private function renderStripeColumn(string $customerId): void
    {
        echo $this->columnHeader('Stripe', $customerId === '' ? 'no customer id' : $customerId);
        if ($customerId === '') {
            echo '<p class="peoft-meta">Provide a customer id to fetch the Stripe bundle.</p>';
            echo '</div>';
            return;
        }
        /** @var CustomerContextLoader $loader */
        $loader = $this->container->get(CustomerContextLoader::class);
        $bundle = $loader->loadOrNull($customerId);
        if ($bundle === null) {
            echo '<p><span class="peoft-status peoft-status-failed">NOT FOUND</span></p>';
            echo '</div>';
            return;
        }
        echo '<p><span class="peoft-status peoft-status-done">FOUND</span></p>';
        echo '<dl class="peoft-meta" style="margin:0;line-height:1.8;">';
        $this->dt('email', $bundle->email);
        $this->dt('name', $bundle->name);
        $this->dt('country', $bundle->address->country);
        $this->dt('city', $bundle->address->city);
        $this->dt('postal', $bundle->address->postalCode);
        if ($bundle->defaultCard !== null) {
            $this->dt('card', $bundle->defaultCard->displayBrand() . ' •••• ' . $bundle->defaultCard->last4 . ' ' . $bundle->defaultCard->expMonth . '/' . $bundle->defaultCard->expYear);
        }
        $this->dt('tax_id', $bundle->defaultTaxId ?? '(none)');
        $this->dt('active_sub', $bundle->activeSubscriptionId ?? '(none)');
        echo '</dl>';
        echo '</div>';
    }

    private function renderSzamlazzColumn(string $email, string $customerId): void
    {
        echo $this->columnHeader('Számlázz', 'xref lookup');
        /** @var Connection $db */
        $db = $this->container->get(Connection::class);
        $wpdb = $db->wpdb();
        $xrefTable = $db->table('peoft_szamlazz_xref');
        // For C4 we don't have a Stripe invoice id from the email alone;
        // show all xref rows matching the customer via join on tasks table.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT stripe_ref, ref_kind, szamlazz_document_number, linked_original, created_at
                   FROM `{$xrefTable}`
                  WHERE env = %s
               ORDER BY created_at DESC
                  LIMIT 10",
                $this->env->value
            ),
            ARRAY_A
        ) ?: [];
        if ($rows === []) {
            echo '<p><span class="peoft-status peoft-status-pending">NO INVOICES</span></p>';
            echo '<p class="peoft-meta">No peoft_szamlazz_xref rows yet for this env. Live Számlázz search by email will be added in a follow-up.</p>';
            echo '</div>';
            return;
        }
        echo '<p><span class="peoft-status peoft-status-done">' . count($rows) . ' RECENT</span></p>';
        echo '<ul class="peoft-meta" style="margin:0;padding-left:16px;font-size:11px;">';
        foreach ($rows as $row) {
            echo '<li><code>' . esc_html((string) $row['szamlazz_document_number']) . '</code>'
                . ' (' . esc_html((string) $row['ref_kind']) . ')'
                . '<br><span style="color:#9ca3af;">' . esc_html(substr((string) $row['stripe_ref'], 0, 30)) . '</span></li>';
        }
        echo '</ul>';
        echo '<p class="peoft-meta"><em>Note: shows recent xref rows for the env, not filtered by this customer. Live Számlázz search by buyer_email is a follow-up.</em></p>';
        echo '</div>';
    }

    private function renderCircleColumn(string $email): void
    {
        echo $this->columnHeader('Circle', $email === '' ? 'no email' : $email);
        if ($email === '') {
            echo '<p class="peoft-meta">Provide an email to look up Circle membership.</p>';
            echo '</div>';
            return;
        }
        /** @var CircleClient $circle */
        $circle = $this->container->get(CircleClient::class);
        $member = $circle->memberByEmail($email);
        if ($member === null) {
            echo '<p><span class="peoft-status peoft-status-pending">NOT A MEMBER</span></p>';
            echo '</div>';
            return;
        }
        echo '<p><span class="peoft-status peoft-status-done">MEMBER</span></p>';
        echo '<dl class="peoft-meta" style="margin:0;line-height:1.8;">';
        $this->dt('id', $member->id);
        $this->dt('email', $member->email);
        $this->dt('name', $member->name ?? '(none)');
        echo '</dl>';
        echo '<p class="peoft-meta"><em>Access-group membership check is a follow-up — requires a list endpoint the Circle SDK doesn\'t expose cleanly yet.</em></p>';
        echo '</div>';
    }

    private function renderActiveCampaignColumn(string $email): void
    {
        echo $this->columnHeader('ActiveCampaign', $email === '' ? 'no email' : $email);
        if ($email === '') {
            echo '<p class="peoft-meta">Provide an email to look up AC contact.</p>';
            echo '</div>';
            return;
        }
        /** @var ActiveCampaignClient $ac */
        $ac = $this->container->get(ActiveCampaignClient::class);
        $contact = $ac->findContactByEmail($email);
        if ($contact === null) {
            echo '<p><span class="peoft-status peoft-status-pending">NOT A CONTACT</span></p>';
            echo '</div>';
            return;
        }
        echo '<p><span class="peoft-status peoft-status-done">CONTACT</span></p>';
        echo '<dl class="peoft-meta" style="margin:0;line-height:1.8;">';
        $this->dt('id', $contact->id);
        $this->dt('email', $contact->email);
        $this->dt('name', trim(((string) $contact->firstName) . ' ' . ((string) $contact->lastName)));
        echo '</dl>';

        // Tag state for the known FT:* tags
        $hasActive  = $ac->hasTag($email, 'FT: ACTIVE');
        $hasDeleted = $ac->hasTag($email, 'FT: DELETED');
        echo '<p class="peoft-meta"><strong>Tags:</strong><br>';
        echo 'FT: ACTIVE ' . ($hasActive ? '<span style="color:#065f46;">✓</span>' : '<span style="color:#9ca3af;">✗</span>') . '<br>';
        echo 'FT: DELETED ' . ($hasDeleted ? '<span style="color:#065f46;">✓</span>' : '<span style="color:#9ca3af;">✗</span>');
        echo '</p>';
        echo '</div>';
    }

    private function renderRecentTasks(string $email, string $customerId): void
    {
        /** @var Connection $db */
        $db = $this->container->get(Connection::class);
        $wpdb = $db->wpdb();
        $table = $db->table('peoft_tasks');

        // Join on payload_json search — cheap LIKE match. Not indexed but
        // fine at our task volume (<100k rows).
        $needle = $email !== '' ? $email : $customerId;
        $likeNeedle = '%' . $wpdb->esc_like($needle) . '%';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, task_type, status, stripe_ref, created_at
                   FROM `{$table}`
                  WHERE payload_json LIKE %s OR stripe_ref LIKE %s
               ORDER BY id DESC
                  LIMIT 10",
                $likeNeedle,
                $likeNeedle
            ),
            ARRAY_A
        ) ?: [];

        echo '<h2>Last 10 tasks mentioning ' . esc_html($needle) . '</h2>';
        if ($rows === []) {
            echo '<div class="peoft-empty">No tasks matched.</div>';
            return;
        }
        echo '<table class="peoft-list"><thead><tr>';
        echo '<th>#</th><th>Type</th><th>Status</th><th>Stripe ref</th><th>Created</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            echo '<tr>';
            echo '<td>' . $id . '</td>';
            echo '<td><code>' . esc_html((string) $row['task_type']) . '</code></td>';
            echo '<td><span class="peoft-status peoft-status-' . esc_attr((string) $row['status']) . '">' . esc_html((string) $row['status']) . '</span></td>';
            echo '<td><code style="font-size:11px;">' . esc_html((string) ($row['stripe_ref'] ?? '')) . '</code></td>';
            echo '<td class="peoft-meta">' . esc_html((string) $row['created_at']) . '</td>';
            echo '<td><a class="button-link" href="' . $this->adminUrl('peo-fegyvertar-audit', ['task_id' => $id]) . '">Audit</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function columnHeader(string $service, string $key): string
    {
        return '<div style="background:#fff;border:1px solid #e5e7eb;padding:14px;border-radius:4px;">'
             . '<h3 style="margin:0 0 8px;font-size:14px;">' . esc_html($service) . '</h3>'
             . '<p class="peoft-meta" style="font-size:11px;margin:0 0 10px;">' . esc_html($key) . '</p>';
    }

    private function dt(string $label, string $value): void
    {
        echo '<div><strong>' . esc_html($label) . ':</strong> <code>' . esc_html(self::truncate($value, 40)) . '</code></div>';
    }

    private static function truncate(string $s, int $max): string
    {
        if ($s === '' || strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max) . '…';
    }
}
