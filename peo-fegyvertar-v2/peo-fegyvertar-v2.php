<?php
/**
 * Plugin Name:       PEO Fegyvertár 2.0
 * Plugin URI:        https://fegyvertar.hu
 * Description:       Fulfillment orchestrator — harmonizes Stripe payments with Számlázz, Circle, ActiveCampaign, and transactional email via a retryable task queue with full audit trail.
 * Version:           2.0.0-alpha
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            David Asztalos
 * Text Domain:       peo-fegyvertar-v2
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (PHP_VERSION_ID < 80100) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>PEO Fegyvertár 2.0</strong> requires PHP 8.1 or newer. Current: ' . esc_html(PHP_VERSION) . '</p></div>';
    });
    return;
}

if (!defined('PEOFT_ENV')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>PEO Fegyvertár 2.0</strong> requires <code>define(\'PEOFT_ENV\', \'dev\'|\'uat\'|\'prod\');</code> in <code>wp-config.php</code>.</p></div>';
    });
    return;
}

$peoft_autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($peoft_autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>PEO Fegyvertár 2.0</strong>: Composer dependencies not installed. Run <code>composer install</code> inside the plugin directory.</p></div>';
    });
    return;
}
require_once $peoft_autoload;

define('PEOFT_V2_PLUGIN_FILE', __FILE__);
define('PEOFT_V2_PLUGIN_DIR', __DIR__);

register_activation_hook(__FILE__, [\Peoft\Core\Kernel::class, 'onActivate']);
register_deactivation_hook(__FILE__, [\Peoft\Core\Kernel::class, 'onDeactivate']);

add_action('plugins_loaded', static function (): void {
    \Peoft\Core\Kernel::boot();
}, 1);
