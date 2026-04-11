<?php

declare(strict_types=1);

namespace Peoft\Cli\Commands;

use Peoft\Core\Config\Config;
use Peoft\Core\Container;

defined('ABSPATH') || exit;

/**
 * `wp peoft dev:fire-webhook --fixture=<slug> [--target-url=<url>] [--bad-signature]`
 *
 * Local test harness for the webhook endpoint.
 *
 * Reads a JSON fixture from tests/Fixtures/stripe/{slug}.json, computes a
 * valid Stripe-Signature header using stripe.webhook_secret from Config, and
 * POSTs it to the WebhookController. Because Stripe webhook signing is
 * symmetric HMAC, the controller's verifier accepts the fixture as if Stripe
 * itself had sent it — no real Stripe, no tunnel, no CLI.
 *
 * Refuses to run outside DEV env by default (safety net: prevents accidentally
 * replaying a fixture against a UAT/PROD install).
 *
 * --bad-signature: re-signs the payload with a junk secret to exercise the
 * WEBHOOK_SIG_FAILED path.
 */
final class DevFireWebhookCommand
{
    private const FIXTURE_DIR = PEOFT_V2_PLUGIN_DIR . '/tests/Fixtures/stripe';

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        if (!Config::env()->isDev()) {
            \WP_CLI::error('dev:fire-webhook only runs in DEV env. Current: ' . Config::env()->value);
            return;
        }

        if (empty($assoc['fixture'])) {
            \WP_CLI::error('--fixture=<slug> is required. Available fixtures: ' . implode(', ', $this->listFixtures()));
            return;
        }

        // Sanitize against path traversal. basename() strips any directory
        // components, so `../../wp-config` becomes `wp-config`. Also reject
        // anything that doesn't match a tight allowlist of file-safe chars.
        $slug = basename((string) $assoc['fixture']);
        if ($slug === '' || preg_match('/^[a-zA-Z0-9._-]+$/', $slug) !== 1) {
            \WP_CLI::error("Invalid fixture slug. Expected: [a-zA-Z0-9._-]+");
            return;
        }
        $fixturePath = self::FIXTURE_DIR . '/' . $slug . '.json';
        if (!is_file($fixturePath)) {
            \WP_CLI::error("Fixture not found: {$fixturePath}");
            return;
        }

        $payload = (string) file_get_contents($fixturePath);
        if ($payload === '' || json_decode($payload, true) === null) {
            \WP_CLI::error("Fixture is empty or invalid JSON: {$fixturePath}");
            return;
        }

        $secret = (string) (Config::for('stripe')->get('webhook_secret') ?? '');
        if ($secret === '') {
            \WP_CLI::error('stripe.webhook_secret not configured; cannot sign fixture.');
            return;
        }

        $signingSecret = !empty($assoc['bad-signature'])
            ? 'whsec_0000000000000000000000000000000000000000'
            : $secret;

        $timestamp = time();
        $signedPayload = $timestamp . '.' . $payload;
        $signature = hash_hmac('sha256', $signedPayload, $signingSecret);
        $sigHeader = "t={$timestamp},v1={$signature}";

        // Use the ?rest_route=... query-string form instead of the pretty
        // /wp-json/... path. The pretty form requires working mod_rewrite
        // rules (.htaccess), which XAMPP's local install doesn't have by
        // default. The query form works regardless of permalink structure.
        $defaultUrl = home_url('/') . '?rest_route=/peo-fegyvertar/v2/stripe-webhook';
        // NOTE: cannot use `--url` — that's a reserved WP-CLI global flag that
        // selects the target site in a multisite install. We use `--target-url`
        // instead so it reaches our command.
        $url = (string) ($assoc['target-url'] ?? $defaultUrl);

        // SSRF guard: only allow localhost, 127.0.0.1, or the WP install's
        // own host. Even though this command is DEV-only, a developer
        // copy-pasting a malicious `--url` shouldn't be able to exfiltrate
        // a signed payload to an arbitrary host.
        $parsedUrl = parse_url($url);
        $urlHost = strtolower($parsedUrl['host'] ?? '');
        $siteHost = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
        $allowedHosts = array_unique(array_filter(['localhost', '127.0.0.1', '::1', $siteHost]));
        if (!in_array($urlHost, $allowedHosts, true)) {
            \WP_CLI::error(sprintf(
                "--url host '%s' is not on the local allowlist (%s). Refusing to send signed payload.",
                $urlHost,
                implode(', ', $allowedHosts)
            ));
            return;
        }
        \WP_CLI::log("POST {$url}");
        \WP_CLI::log("fixture: {$slug} ({" . strlen($payload) . "} bytes)");
        if (!empty($assoc['bad-signature'])) {
            \WP_CLI::log('signing with JUNK secret (expect 400 + WEBHOOK_SIG_FAILED)');
        }

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type'     => 'application/json',
                'Stripe-Signature' => $sigHeader,
            ],
            'body'    => $payload,
            'timeout' => 10,
            'sslverify' => false, // localhost is http; flag is harmless for http
        ]);

        if (is_wp_error($response)) {
            \WP_CLI::error('wp_remote_post failed: ' . $response->get_error_message());
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        \WP_CLI::log("response: HTTP {$code}");
        \WP_CLI::log("body: {$body}");

        if ($code >= 200 && $code < 300) {
            \WP_CLI::success('fire-webhook ok');
        } elseif (!empty($assoc['bad-signature']) && $code === 400) {
            \WP_CLI::success('expected 400 received (sig-fail path verified)');
        } else {
            \WP_CLI::warning('non-2xx response');
        }
    }

    /**
     * @return list<string>
     */
    private function listFixtures(): array
    {
        $files = glob(self::FIXTURE_DIR . '/*.json') ?: [];
        return array_map(static fn (string $p): string => basename($p, '.json'), $files);
    }
}
