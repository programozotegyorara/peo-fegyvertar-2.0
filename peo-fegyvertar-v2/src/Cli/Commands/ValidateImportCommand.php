<?php

declare(strict_types=1);

namespace Peoft\Cli\Commands;

use Peoft\Core\Config\Config;
use Peoft\Core\Container;
use Peoft\Core\Db\Connection;

defined('ABSPATH') || exit;

/**
 * `wp peoft import:validate`
 *
 * Post-import sanity check. Verifies:
 *   1. peoft_config has the expected number of keys for the current env
 *   2. Critical config keys exist (stripe.secret_key, szamlazz.api_key, etc.)
 *   3. peoft_webhook_events has legacy rows (source='legacy_1.0')
 *   4. peoft_counters has an 'order' row with value > 0
 *   5. peoft_email_templates has the expected number of templates
 *   6. system.cutover_date is set (if applicable)
 *
 * Outputs a pass/fail checklist. Exit code is 0 on all-pass, 1 on any fail.
 */
final class ValidateImportCommand
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
        $db = $this->container->get(Connection::class);
        $wpdb = $db->wpdb();
        $env = Config::env()->value;
        $failures = 0;

        \WP_CLI::log("Validating import for env={$env}...\n");

        // 1. Config key count
        $configCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$db->table('peoft_config')}` WHERE env=%s", $env
        ));
        $this->check('peoft_config rows >= 10', $configCount >= 10, "{$configCount} rows", $failures);

        // 2. Critical config keys
        $criticalKeys = [
            'stripe.secret_key', 'stripe.publishable_key',
            'szamlazz.api_key', 'szamlazz.prefix',
            'circle.v2_api_key', 'circle.access_group_id',
            'activecampaign.api_url', 'activecampaign.api_key',
            'notifications.error_recipients',
        ];
        foreach ($criticalKeys as $key) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM `{$db->table('peoft_config')}` WHERE env=%s AND config_key=%s LIMIT 1",
                $env, $key
            ));
            $this->check("config key '{$key}' exists", $exists !== null, $exists !== null ? 'present' : 'MISSING', $failures);
        }

        // 3. Legacy webhook events
        $legacyCount = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$db->table('peoft_webhook_events')}` WHERE source='legacy_1.0'"
        );
        $this->check('legacy webhook events > 0', $legacyCount > 0, "{$legacyCount} rows", $failures);

        // 4. Order counter
        $counterValue = $wpdb->get_var($wpdb->prepare(
            "SELECT value FROM `{$db->table('peoft_counters')}` WHERE env=%s AND counter_key='order' LIMIT 1",
            $env
        ));
        $this->check('order counter exists and > 0', $counterValue !== null && (int) $counterValue > 0,
            $counterValue !== null ? "value={$counterValue}" : 'MISSING', $failures);

        // 5. Email templates
        $templateCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$db->table('peoft_email_templates')}` WHERE env=%s", $env
        ));
        $this->check('email templates >= 15', $templateCount >= 15, "{$templateCount} templates", $failures);

        // 6. Cutover date (informational, not a hard fail)
        $cutoverDate = Config::get('system.cutover_date');
        if ($cutoverDate !== null) {
            \WP_CLI::log("  [INFO] cutover_date = {$cutoverDate}");
        } else {
            \WP_CLI::log("  [INFO] cutover_date not set yet — run `wp peoft import:cutover-date --date=YYYY-MM-DD` when ready");
        }

        echo "\n";
        if ($failures === 0) {
            \WP_CLI::success("All checks passed for env={$env}.");
        } else {
            \WP_CLI::error("{$failures} check(s) failed. Review the output above.");
        }
    }

    private function check(string $label, bool $pass, string $detail, int &$failures): void
    {
        if ($pass) {
            \WP_CLI::log("  [PASS] {$label} — {$detail}");
        } else {
            \WP_CLI::log("  [FAIL] {$label} — {$detail}");
            $failures++;
        }
    }
}
