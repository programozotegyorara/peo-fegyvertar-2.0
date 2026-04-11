<?php

declare(strict_types=1);

namespace Peoft\Integrations\ActiveCampaign;

use Peoft\Integrations\ApiClient;
use Peoft\Integrations\ApiHttpException;

defined('ABSPATH') || exit;

/**
 * Real AC client. Used when `activecampaign.mode === 'live'`.
 *
 * Endpoints used:
 *   GET    {api_url}/contacts?email=URLENC(email)    — findContactByEmail
 *   POST   {api_url}/contact/sync                    — upsertContact (AC's upsert-by-email)
 *   GET    {api_url}/contacts/{id}/contactTags       — list contact's current tags
 *   POST   {api_url}/contactTags                     — tagContact (create junction row)
 *   DELETE {api_url}/contactTags/{contactTagId}      — untagContact
 *
 * Authentication: `Api-Token` header on every call. Stripped from audit by
 * the ApiClient base class.
 *
 * Per plan §6: tag/untag operations are naturally idempotent at AC (AC
 * returns 200 on already-tagged add, and we pre-check hasTag in handlers
 * anyway). Upsert uses /contact/sync which AC treats as create-or-update
 * by email.
 */
final class ActiveCampaignClientLive extends ApiClient implements ActiveCampaignClient
{
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiToken,
        private readonly TagResolver $tags,
    ) {}

    public function findContactByEmail(string $email): ?AcContact
    {
        $url = $this->base() . '/contacts?email=' . rawurlencode($email);
        try {
            $response = $this->request('GET', $url, $this->authHeaders());
        } catch (ApiHttpException $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }
        $json = $response->json();
        $rows = $json['contacts'] ?? [];
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        $row = $rows[0];
        return new AcContact(
            id:        (string) ($row['id'] ?? ''),
            email:     (string) ($row['email'] ?? $email),
            firstName: isset($row['firstName']) ? (string) $row['firstName'] : null,
            lastName:  isset($row['lastName'])  ? (string) $row['lastName']  : null,
        );
    }

    public function upsertContact(string $email, ?string $firstName = null, ?string $lastName = null): AcContact
    {
        $body = [
            'contact' => [
                'email'     => $email,
                'firstName' => $firstName ?? '',
                'lastName'  => $lastName ?? '',
            ],
        ];
        $response = $this->request(
            method: 'POST',
            url: $this->base() . '/contact/sync',
            headers: $this->authHeaders(),
            body: json_encode($body, JSON_UNESCAPED_UNICODE) ?: '{}',
        );
        $json = $response->json();
        $row = $json['contact'] ?? null;
        if (!is_array($row) || !isset($row['id'])) {
            throw new \RuntimeException('AC upsertContact response did not include contact.id: ' . substr($response->body, 0, 400));
        }
        return new AcContact(
            id:        (string) $row['id'],
            email:     (string) ($row['email'] ?? $email),
            firstName: isset($row['firstName']) ? (string) $row['firstName'] : $firstName,
            lastName:  isset($row['lastName'])  ? (string) $row['lastName']  : $lastName,
        );
    }

    public function tagContact(string $email, string $tagName): void
    {
        if ($this->hasTag($email, $tagName)) {
            return; // already tagged — natural idempotency
        }
        $contact = $this->findContactByEmail($email)
            ?? $this->upsertContact($email);

        $tagId = $this->tags->idFor($tagName);
        if ($tagId === null) {
            throw new \RuntimeException("AC tag not found by name: '{$tagName}'");
        }

        $body = [
            'contactTag' => [
                'contact' => $contact->id,
                'tag'     => (string) $tagId,
            ],
        ];
        $this->request(
            method: 'POST',
            url: $this->base() . '/contactTags',
            headers: $this->authHeaders(),
            body: json_encode($body, JSON_UNESCAPED_UNICODE) ?: '{}',
        );
    }

    public function untagContact(string $email, string $tagName): void
    {
        $contact = $this->findContactByEmail($email);
        if ($contact === null) {
            return; // no contact → already untagged
        }
        $tagId = $this->tags->idFor($tagName);
        if ($tagId === null) {
            return; // no such tag → already untagged
        }
        $contactTagId = $this->fetchContactTagJunctionId($contact->id, (string) $tagId);
        if ($contactTagId === null) {
            return; // already untagged
        }
        $this->request(
            method: 'DELETE',
            url: $this->base() . '/contactTags/' . rawurlencode($contactTagId),
            headers: $this->authHeaders(),
        );
    }

    public function hasTag(string $email, string $tagName): bool
    {
        $contact = $this->findContactByEmail($email);
        if ($contact === null) {
            return false;
        }
        $tagId = $this->tags->idFor($tagName);
        if ($tagId === null) {
            return false;
        }
        return $this->fetchContactTagJunctionId($contact->id, (string) $tagId) !== null;
    }

    /**
     * Returns the id of the `contactTags` junction row that binds $contactId
     * to $tagId, or null if no such junction exists.
     */
    private function fetchContactTagJunctionId(string $contactId, string $tagId): ?string
    {
        $url = $this->base() . '/contacts/' . rawurlencode($contactId) . '/contactTags';
        try {
            $response = $this->request('GET', $url, $this->authHeaders());
        } catch (ApiHttpException $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }
        $json = $response->json();
        $rows = $json['contactTags'] ?? [];
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['tag'], $row['id']) && (string) $row['tag'] === $tagId) {
                return (string) $row['id'];
            }
        }
        return null;
    }

    /**
     * Returns the API base URL with `/api/3` appended exactly once.
     * Handles both stored forms:
     *   `https://acct.api-us1.com`           → `https://acct.api-us1.com/api/3`
     *   `https://acct.api-us1.com/api/3/`    → `https://acct.api-us1.com/api/3`
     *   `https://acct.api-us1.com/api/3`     → `https://acct.api-us1.com/api/3`
     */
    private function base(): string
    {
        $trimmed = rtrim($this->apiUrl, '/');
        if (str_ends_with($trimmed, '/api/3')) {
            return $trimmed;
        }
        return $trimmed . '/api/3';
    }

    /**
     * @return array<string,string>
     */
    private function authHeaders(): array
    {
        return [
            'Api-Token'    => $this->apiToken,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
    }
}
