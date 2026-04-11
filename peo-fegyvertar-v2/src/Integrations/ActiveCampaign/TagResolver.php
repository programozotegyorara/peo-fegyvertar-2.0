<?php

declare(strict_types=1);

namespace Peoft\Integrations\ActiveCampaign;

use Peoft\Core\Env;
use Peoft\Integrations\ApiClient;
use Peoft\Integrations\ApiHttpException;

defined('ABSPATH') || exit;

/**
 * Translates an ActiveCampaign tag name into its numeric id via `GET /tags?search=<name>`.
 *
 * 1.0 kept these in a local `PEO_FT_AC_TAGS` table — 2.0 replaces that with a
 * live lookup cached in a WP transient for 1 hour. This keeps the source of
 * truth at ActiveCampaign and removes the need for a manual re-sync job
 * whenever someone adds a new tag in the AC admin.
 *
 * The cache key is scoped by env so DEV/UAT/PROD don't contaminate each
 * other's tag id caches.
 *
 * Note: this class extends ApiClient only so it can reuse ->request(). It
 * does not store an auth token itself — the caller passes the `Api-Token`
 * header in via buildAuthHeaders().
 */
final class TagResolver extends ApiClient
{
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * @param callable():array{api_url:string, api_token:string} $configFn
     *   Returns the current AC config at call time (read from Config facade).
     *   Passed as a closure so the cache survives config refreshes and so
     *   we don't bind to specific ConfigRepository instance identity.
     */
    public function __construct(
        private readonly Env $env,
        private $configFn,
    ) {}

    /**
     * Resolve a tag name to its AC numeric id. Returns null if the tag
     * does not exist in AC.
     */
    public function idFor(string $tagName): ?int
    {
        $transientKey = $this->cacheKey($tagName);
        $cached = get_transient($transientKey);
        if ($cached !== false) {
            return $cached === 'NULL' ? null : (int) $cached;
        }

        $id = $this->lookupLive($tagName);
        set_transient($transientKey, $id === null ? 'NULL' : (string) $id, self::CACHE_TTL);
        return $id;
    }

    public function invalidate(string $tagName): void
    {
        delete_transient($this->cacheKey($tagName));
    }

    private function cacheKey(string $tagName): string
    {
        return 'peoft_ac_tag_' . $this->env->value . '_' . md5($tagName);
    }

    private function lookupLive(string $tagName): ?int
    {
        $cfg = ($this->configFn)();
        $url = rtrim($cfg['api_url'], '/') . '/tags?search=' . rawurlencode($tagName) . '&limit=5';
        try {
            $response = $this->request(
                method: 'GET',
                url: $url,
                headers: [
                    'Api-Token'    => $cfg['api_token'],
                    'Content-Type' => 'application/json',
                ],
            );
        } catch (ApiHttpException $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }

        $json = $response->json();
        $candidates = $json['tags'] ?? [];
        if (!is_array($candidates)) {
            return null;
        }
        foreach ($candidates as $tag) {
            if (is_array($tag) && ($tag['tag'] ?? null) === $tagName) {
                return isset($tag['id']) ? (int) $tag['id'] : null;
            }
        }
        return null;
    }
}
