<?php

declare(strict_types=1);

namespace Peoft\Integrations\ActiveCampaign;

use Peoft\Audit\ApiCallRecord;
use Peoft\Audit\AuditLog;

defined('ABSPATH') || exit;

/**
 * Mock AC client. Used when `activecampaign.mode === 'mock'`.
 *
 * Does NOT touch the real ActiveCampaign API. Every call is recorded as an
 * API_CALL audit row with `url: 'ac://mock'` and `mock: true` in the request
 * body so operators can see what *would* have been sent. Contact IDs and
 * tag checks return deterministic stub values so downstream code (handlers)
 * gets consistent behavior between mock and live.
 *
 * In DEV, this is the default wiring because `ImportFromLegacyDbCommand`
 * force-sets `activecampaign.mode=mock` as a safety net.
 */
final class ActiveCampaignClientMock implements ActiveCampaignClient
{
    public function findContactByEmail(string $email): ?AcContact
    {
        $this->auditCall('GET', '/contacts?email=' . $email, 200, null, ['mock' => true, 'op' => 'findContactByEmail', 'email' => $email]);
        // Always return a stub contact so downstream code treats the email
        // as "known". Deterministic so repeated calls are idempotent.
        return new AcContact(
            id:        'mock_' . substr(md5($email), 0, 12),
            email:     $email,
            firstName: null,
            lastName:  null,
        );
    }

    public function upsertContact(string $email, ?string $firstName = null, ?string $lastName = null): AcContact
    {
        $this->auditCall('POST', '/contact/sync', 201, null, [
            'mock' => true, 'op' => 'upsertContact',
            'email' => $email, 'firstName' => $firstName, 'lastName' => $lastName,
        ]);
        return new AcContact(
            id:        'mock_' . substr(md5($email), 0, 12),
            email:     $email,
            firstName: $firstName,
            lastName:  $lastName,
        );
    }

    public function tagContact(string $email, string $tagName): void
    {
        $this->auditCall('POST', '/contactTags', 201, null, [
            'mock' => true, 'op' => 'tagContact',
            'email' => $email, 'tag' => $tagName,
        ]);
    }

    public function untagContact(string $email, string $tagName): void
    {
        $this->auditCall('DELETE', '/contactTags', 200, null, [
            'mock' => true, 'op' => 'untagContact',
            'email' => $email, 'tag' => $tagName,
        ]);
    }

    public function hasTag(string $email, string $tagName): bool
    {
        $this->auditCall('GET', '/contacts/{id}/contactTags', 200, null, [
            'mock' => true, 'op' => 'hasTag',
            'email' => $email, 'tag' => $tagName,
        ]);
        // In mock mode we claim the contact does NOT have the tag so that
        // tagContact proceeds to the real API_CALL audit row. This is the
        // useful default for DEV smoke-testing the fan-out.
        return false;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function auditCall(string $method, string $path, int $status, ?string $resBody, array $payload): void
    {
        AuditLog::record(
            actor: 'worker',
            action: 'API_CALL',
            subjectType: 'activecampaign',
            subjectId: $payload['email'] ?? null,
            api: new ApiCallRecord(
                method: $method,
                url: 'ac://mock' . $path,
                status: $status,
                reqBody: json_encode($payload, JSON_UNESCAPED_UNICODE) ?: null,
                resBody: $resBody,
                durationMs: 0,
            ),
        );
    }
}
