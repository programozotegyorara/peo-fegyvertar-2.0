<?php

declare(strict_types=1);

namespace Peoft\Integrations\Circle;

use Peoft\Integrations\ApiClient;
use Peoft\Integrations\ApiHttpException;

defined('ABSPATH') || exit;

/**
 * Real Circle v2 client. Used when `circle.mode === 'live'`.
 *
 * Endpoints:
 *   GET    /api/admin/v2/community_members?search=<email>          — memberByEmail
 *   POST   /api/admin/v2/community_members                          — createMember
 *   POST   /api/admin/v2/access_groups/{id}/community_members       — addToAccessGroup
 *   DELETE /api/admin/v2/access_groups/{id}/community_members?email — removeFromAccessGroup
 *
 * Auth: `Authorization: Bearer <api_key>` header, scrubbed from audit by
 * Redactor before the API_CALL row is written.
 *
 * All four methods are safe to retry:
 *   - memberByEmail is read-only
 *   - createMember swallows "already exists" (422) as success
 *   - addToAccessGroup is naturally idempotent (Circle returns 200 on dupe)
 *   - removeFromAccessGroup swallows 404 (already absent)
 */
final class CircleClientLive extends ApiClient implements CircleClient
{
    private const BASE = 'https://app.circle.so/api/admin/v2';

    public function __construct(
        private readonly string $apiKey,
    ) {}

    public function memberByEmail(string $email): ?CircleMember
    {
        $url = self::BASE . '/community_members?search=' . rawurlencode($email);
        try {
            $response = $this->request('GET', $url, $this->authHeaders());
        } catch (ApiHttpException $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }
        $json = $response->json();
        $rows = $json['community_members'] ?? $json['records'] ?? $json ?? [];
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strcasecmp((string) ($row['email'] ?? ''), $email) === 0) {
                return new CircleMember(
                    id:    (string) ($row['id'] ?? ''),
                    email: (string) $row['email'],
                    name:  isset($row['name']) ? (string) $row['name'] : null,
                );
            }
        }
        return null;
    }

    public function createMember(string $email, string $name, bool $skipInvitation = false): CircleMember
    {
        $body = json_encode([
            'email'                 => $email,
            'name'                  => $name !== '' ? $name : 'Viking',
            'skip_invitation_email' => $skipInvitation,
        ], JSON_UNESCAPED_UNICODE) ?: '{}';

        try {
            $response = $this->request(
                method: 'POST',
                url: self::BASE . '/community_members',
                headers: $this->authHeaders(),
                body: $body,
            );
        } catch (ApiHttpException $e) {
            // "Already exists" — Circle uses 422 for this case. Swallow
            // and fall through to fetch the existing member.
            if ($e->status === 422 || $e->status === 409) {
                $existing = $this->memberByEmail($email);
                if ($existing !== null) {
                    return $existing;
                }
            }
            throw $e;
        }

        $json = $response->json();
        $row = $json['community_member'] ?? $json ?? null;
        if (!is_array($row) || !isset($row['id'])) {
            // Some Circle responses wrap the member in `record` or omit the
            // wrapper entirely. Fall back to a follow-up search if we can't
            // parse a direct id.
            $fallback = $this->memberByEmail($email);
            if ($fallback !== null) {
                return $fallback;
            }
            throw new \RuntimeException(
                'Circle createMember response did not include a parseable member id: ' . substr($response->body, 0, 400)
            );
        }

        return new CircleMember(
            id:    (string) $row['id'],
            email: (string) ($row['email'] ?? $email),
            name:  isset($row['name']) ? (string) $row['name'] : $name,
        );
    }

    public function addToAccessGroup(string $email, string $accessGroupId): void
    {
        $body = json_encode(['email' => $email], JSON_UNESCAPED_UNICODE) ?: '{}';
        try {
            $this->request(
                method: 'POST',
                url: self::BASE . '/access_groups/' . rawurlencode($accessGroupId) . '/community_members',
                headers: $this->authHeaders(),
                body: $body,
            );
        } catch (ApiHttpException $e) {
            // 200, 201 are success (handled by ApiClient). For "already a
            // member" Circle may return 422 depending on the exact flow;
            // treat as success.
            if ($e->status === 422) {
                return;
            }
            throw $e;
        }
    }

    public function removeFromAccessGroup(string $email, string $accessGroupId): void
    {
        $url = self::BASE . '/access_groups/' . rawurlencode($accessGroupId)
             . '/community_members?' . http_build_query(['email' => $email]);
        try {
            $this->request('DELETE', $url, $this->authHeaders());
        } catch (ApiHttpException $e) {
            // Already absent → idempotent success.
            if ($e->status === 404) {
                return;
            }
            throw $e;
        }
    }

    /**
     * @return array<string,string>
     */
    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'Expect'        => '',
        ];
    }
}
