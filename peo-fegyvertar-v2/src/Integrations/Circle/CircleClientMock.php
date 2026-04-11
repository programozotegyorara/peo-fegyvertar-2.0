<?php

declare(strict_types=1);

namespace Peoft\Integrations\Circle;

use Peoft\Audit\ApiCallRecord;
use Peoft\Audit\AuditLog;

defined('ABSPATH') || exit;

/**
 * Mock Circle client. Used when `circle.mode === 'mock'`.
 *
 * Does NOT touch Circle. Every call is recorded as an API_CALL audit row
 * with `url: 'circle://mock/...'` and `mock: true` in the request body so
 * operators can see the intended side-effect without hitting the real API.
 *
 * Deterministic return values so repeated calls for the same email behave
 * like a naturally-idempotent real API.
 *
 * In DEV this is the default wiring because ImportFromLegacyDbCommand
 * force-sets `circle.mode=mock` as a safety net — prevents accidentally
 * enrolling test fixtures into the production Circle community.
 */
final class CircleClientMock implements CircleClient
{
    public function memberByEmail(string $email): ?CircleMember
    {
        $this->auditCall('GET', '/community_members', 200, ['mock' => true, 'op' => 'memberByEmail', 'email' => $email]);
        return new CircleMember(
            id:    'mock_' . substr(md5($email), 0, 12),
            email: $email,
            name:  null,
        );
    }

    public function createMember(string $email, string $name, bool $skipInvitation = false): CircleMember
    {
        $this->auditCall('POST', '/community_members', 201, [
            'mock' => true, 'op' => 'createMember',
            'email' => $email, 'name' => $name, 'skip_invitation_email' => $skipInvitation,
        ]);
        return new CircleMember(
            id:    'mock_' . substr(md5($email), 0, 12),
            email: $email,
            name:  $name,
        );
    }

    public function addToAccessGroup(string $email, string $accessGroupId): void
    {
        $this->auditCall('POST', "/access_groups/{$accessGroupId}/community_members", 201, [
            'mock' => true, 'op' => 'addToAccessGroup',
            'email' => $email, 'access_group_id' => $accessGroupId,
        ]);
    }

    public function removeFromAccessGroup(string $email, string $accessGroupId): void
    {
        $this->auditCall('DELETE', "/access_groups/{$accessGroupId}/community_members", 204, [
            'mock' => true, 'op' => 'removeFromAccessGroup',
            'email' => $email, 'access_group_id' => $accessGroupId,
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function auditCall(string $method, string $path, int $status, array $payload): void
    {
        AuditLog::record(
            actor: 'worker',
            action: 'API_CALL',
            subjectType: 'circle',
            subjectId: $payload['email'] ?? null,
            api: new ApiCallRecord(
                method: $method,
                url: 'circle://mock' . $path,
                status: $status,
                reqBody: json_encode($payload, JSON_UNESCAPED_UNICODE) ?: null,
                resBody: null,
                durationMs: 0,
            ),
        );
    }
}
