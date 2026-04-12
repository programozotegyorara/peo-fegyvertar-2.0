<?php

declare(strict_types=1);

namespace Peoft\Admin\Rest;

defined('ABSPATH') || exit;

/**
 * Shared infrastructure for every admin REST route.
 *
 * Provides:
 *   - `authorize()` — capability + nonce + rate-limit checks in one call
 *   - `rateLimited()` — transient-backed 30-req/min-per-user throttle
 *   - `actorString()` — `admin:{user_id}` for audit rows
 *
 * Every admin mutating route calls `authorize()` at the top. Failure
 * returns a `WP_REST_Response` the route should return directly; success
 * returns null.
 */
abstract class AdminRestController
{
    public const NAMESPACE = 'peo-fegyvertar/v2';
    public const CAPABILITY = 'manage_options';
    public const RATE_LIMIT_PER_MIN = 30;
    public const NONCE_ACTION = 'peoft_admin_action';

    /**
     * Run capability + nonce + rate-limit checks.
     * Returns null on success, or a WP_REST_Response with an error on failure.
     */
    protected function authorize(\WP_REST_Request $request): ?\WP_REST_Response
    {
        if (!current_user_can(self::CAPABILITY)) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'forbidden'], 403);
        }

        // REST nonce header (issued by wp_create_nonce('wp_rest'))
        $nonce = $request->get_header('x_wp_nonce');
        if ($nonce === null || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'bad_nonce'], 403);
        }

        if ($this->rateLimited()) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
        }

        return null;
    }

    /**
     * 30 requests per rolling minute per user, stored in a WP transient.
     * Returns true if the caller has exceeded the limit.
     */
    protected function rateLimited(): bool
    {
        $userId = (int) get_current_user_id();
        if ($userId === 0) {
            return true; // shouldn't reach here post-auth; treat as rate-limited, not unlimited
        }
        $key = 'peoft_rl_' . $userId;
        $count = (int) (get_transient($key) ?: 0);
        if ($count >= self::RATE_LIMIT_PER_MIN) {
            return true;
        }
        set_transient($key, $count + 1, 60);
        return false;
    }

    protected function actorString(): string
    {
        return 'admin:' . (int) get_current_user_id();
    }

    public function permissionCallback(): bool
    {
        return current_user_can(self::CAPABILITY);
    }
}
