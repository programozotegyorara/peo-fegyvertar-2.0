<?php

declare(strict_types=1);

namespace Peoft\Admin\Rest;

use Peoft\Audit\AuditLog;
use Peoft\Core\Config\Config;
use Peoft\Core\Config\ConfigRepository;
use Peoft\Core\Config\ConfigSchema;
use Peoft\Core\Db\Connection;

defined('ABSPATH') || exit;

/**
 * REST routes for the Config Editor page.
 *
 *   POST /admin/config/save     { env, config_key, config_value }
 *   POST /admin/config/reveal   { env, config_key, password }
 *
 * `save` writes to peoft_config via ConfigRepository::set (which enforces
 * the SSRF host allowlist for *.api_url / *.host keys and the mode enum for
 * *.mode keys). Always writes a CONFIG_CHANGED audit row with before/after
 * values redacted through the Redactor.
 *
 * `reveal` is the extra security-sensitive path for is_secret=1 rows:
 * it requires the admin to re-enter their WP password (verified via
 * wp_check_password), issues the clear-text secret in the response body
 * ONCE, and writes a CONFIG_READ audit row naming the key but not the value.
 */
final class ConfigRestRoutes extends AdminRestController
{
    public function __construct(
        private readonly ConfigRepository $repo,
        private readonly Connection $db,
    ) {}

    public function register(): void
    {
        $ns = self::NAMESPACE;
        register_rest_route($ns, '/admin/config/save', [
            'methods'             => 'POST',
            'callback'            => [$this, 'save'],
            'permission_callback' => [$this, 'permissionCallback'],
        ]);
        register_rest_route($ns, '/admin/config/reveal', [
            'methods'             => 'POST',
            'callback'            => [$this, 'reveal'],
            'permission_callback' => [$this, 'permissionCallback'],
        ]);
    }

    public function save(\WP_REST_Request $req): \WP_REST_Response
    {
        if ($err = $this->authorize($req)) {
            return $err;
        }
        $key = sanitize_text_field((string) $req->get_param('config_key'));
        $value = $req->get_param('config_value');
        if ($key === '' || !str_contains($key, '.')) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'invalid_key'], 400);
        }

        $before = $this->repo->get($key);
        try {
            $this->repo->set($key, $value, updatedBy: $this->actorString());
        } catch (\Throwable $e) {
            error_log('[peoft] config save rejected for ' . $key . ': ' . $e->getMessage());
            return new \WP_REST_Response(['ok' => false, 'error' => 'rejected'], 400);
        }

        // Mark the key secret if applicable so the audit Redactor scrubs it.
        $isSecret = ConfigSchema::isSecret($key);
        AuditLog::record(
            actor:       $this->actorString(),
            action:      'CONFIG_CHANGED',
            subjectType: 'config',
            subjectId:   $key,
            before:      $isSecret ? ['value' => '[REDACTED]'] : ['value' => $before],
            after:       $isSecret ? ['value' => '[REDACTED]'] : ['value' => $value],
        );
        return new \WP_REST_Response(['ok' => true, 'config_key' => $key], 200);
    }

    public function reveal(\WP_REST_Request $req): \WP_REST_Response
    {
        if ($err = $this->authorize($req)) {
            return $err;
        }
        $key = sanitize_text_field((string) $req->get_param('config_key'));
        $password = (string) $req->get_param('password');
        if ($key === '' || $password === '') {
            return new \WP_REST_Response(['ok' => false, 'error' => 'missing_fields'], 400);
        }
        // Only secret keys can be "revealed". Non-secret keys are visible
        // in the Config Editor table directly and don't need the password
        // re-auth flow. Prevents API callers from bypassing the UI intent.
        if (!ConfigSchema::isSecret($key)) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'not_a_secret_key'], 400);
        }

        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'no_user'], 403);
        }
        if (!wp_check_password($password, $user->user_pass, $user->ID)) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'bad_password'], 403);
        }

        $value = $this->repo->get($key);
        // Audit the reveal. NEVER log the value itself.
        AuditLog::record(
            actor:       $this->actorString(),
            action:      'CONFIG_READ',
            subjectType: 'config',
            subjectId:   $key,
            after:       ['key' => $key, 'revealed_to' => $this->actorString()],
        );
        $resp = new \WP_REST_Response([
            'ok' => true,
            'config_key' => $key,
            'config_value' => is_scalar($value) ? (string) $value : wp_json_encode($value),
        ], 200);
        // Prevent the revealed secret from lingering in browser cache on
        // shared workstations. The value should only be visible in the
        // transient modal display, not in the Network tab cache.
        $resp->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        $resp->header('Pragma', 'no-cache');
        return $resp;
    }
}
