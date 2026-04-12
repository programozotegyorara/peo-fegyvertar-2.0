<?php

declare(strict_types=1);

namespace Peoft\Admin;

use Peoft\Core\Container;
use Peoft\Core\Env;

defined('ABSPATH') || exit;

/**
 * Base class for every PEO Fegyvertár admin page.
 *
 * Handles the cross-cutting concerns each page would otherwise duplicate:
 *   - capability check (manage_options)
 *   - env banner rendering
 *   - standard page header + wrapper div
 *   - CSS injection (once per request)
 *
 * Subclasses implement `slug()`, `title()`, `renderBody()`, and optionally
 * `menuTitle()` / `menuPosition()`.
 */
abstract class AdminPage
{
    public function __construct(
        protected readonly Container $container,
        protected readonly Env $env,
    ) {}

    abstract public static function slug(): string;
    abstract public function title(): string;
    abstract protected function renderBody(): void;

    public function menuTitle(): string
    {
        return $this->title();
    }

    /**
     * WP `add_submenu_page` callback. Called on every request that hits
     * this admin URL.
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'peo-fegyvertar-v2'));
        }
        echo '<div class="wrap peoft-admin-wrap">';
        EnvBanner::render($this->env);
        echo '<h1>' . esc_html($this->title()) . '</h1>';
        self::renderStyles();
        $this->renderBody();
        echo '</div>';
    }

    /**
     * Issue a one-shot nonce for the given action name. Used by forms and
     * inline JS to authorize subsequent REST calls.
     */
    protected function nonce(string $action): string
    {
        return wp_create_nonce('peoft_' . $action);
    }

    /**
     * Generate a cleanly-escaped admin URL for another peoft submenu page.
     */
    protected function adminUrl(string $slug, array $args = []): string
    {
        $args['page'] = $slug;
        return esc_url(add_query_arg($args, admin_url('admin.php')));
    }

    private static bool $stylesRendered = false;

    private static function renderStyles(): void
    {
        if (self::$stylesRendered) {
            return;
        }
        self::$stylesRendered = true;
        echo <<<CSS
<style>
.peoft-admin-wrap { max-width: 1400px; }
.peoft-admin-wrap table.peoft-list {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    margin-top: 12px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
.peoft-admin-wrap table.peoft-list th,
.peoft-admin-wrap table.peoft-list td {
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: top;
    font-size: 13px;
}
.peoft-admin-wrap table.peoft-list th {
    background: #f9fafb;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #d1d5db;
}
.peoft-admin-wrap table.peoft-list tr:hover td { background: #f9fafb; }
.peoft-admin-wrap .peoft-filters {
    background: #f9fafb;
    padding: 12px 16px;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    margin-bottom: 8px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}
.peoft-admin-wrap .peoft-filters label { font-size: 13px; color: #374151; }
.peoft-admin-wrap .peoft-filters input[type="text"],
.peoft-admin-wrap .peoft-filters select {
    padding: 4px 8px;
    font-size: 13px;
}
.peoft-admin-wrap .peoft-status {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}
.peoft-admin-wrap .peoft-status-pending { background: #fef3c7; color: #92400e; }
.peoft-admin-wrap .peoft-status-running { background: #dbeafe; color: #1e40af; }
.peoft-admin-wrap .peoft-status-done    { background: #d1fae5; color: #065f46; }
.peoft-admin-wrap .peoft-status-failed  { background: #fee2e2; color: #991b1b; }
.peoft-admin-wrap .peoft-status-dead    { background: #7f1d1d; color: #fff; }
.peoft-admin-wrap .peoft-row-actions button,
.peoft-admin-wrap .peoft-row-actions .button-link {
    background: none;
    border: none;
    color: #2563eb;
    cursor: pointer;
    font-size: 12px;
    padding: 2px 4px;
    margin-right: 6px;
    text-decoration: underline;
}
.peoft-admin-wrap .peoft-row-actions button:hover { color: #1d4ed8; }
.peoft-admin-wrap details pre {
    background: #0f172a;
    color: #e2e8f0;
    padding: 12px;
    border-radius: 4px;
    font-size: 12px;
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-word;
}
.peoft-admin-wrap .peoft-meta { color: #6b7280; font-size: 12px; }
.peoft-admin-wrap .peoft-empty {
    background: #fff;
    padding: 40px;
    text-align: center;
    color: #6b7280;
    border: 1px dashed #d1d5db;
    border-radius: 4px;
}
</style>
CSS;
    }
}
