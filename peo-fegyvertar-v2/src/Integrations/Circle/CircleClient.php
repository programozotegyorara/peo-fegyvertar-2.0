<?php

declare(strict_types=1);

namespace Peoft\Integrations\Circle;

defined('ABSPATH') || exit;

/**
 * Circle client — interface only.
 *
 * Kernel binds one of:
 *   - `mock` → CircleClientMock (stubs, audit logs with mock=true)
 *   - `live` → CircleClientLive (real HTTP via ApiClient)
 *
 * Natural idempotency: Circle's access-group add returns 200 for already-member
 * and the access-group delete returns 404 for already-absent. Handlers rely on
 * these semantics instead of a pre-check, which means they don't need an extra
 * "hasMember" round-trip before every write.
 *
 * v1 Circle client (1.0's circle-integration.php) is deleted per plan §14.
 */
interface CircleClient
{
    /**
     * Look up a community member by email. Returns null if the member does
     * not exist in the community.
     *
     * Used by Phase D reconciliation page; not called by the enroll/revoke
     * handlers (they rely on natural idempotency instead).
     */
    public function memberByEmail(string $email): ?CircleMember;

    /**
     * Create-or-get a community member. Circle returns 201 for new, 422
     * (or similar) for "already exists". Implementations treat both as success
     * and return the resulting CircleMember.
     */
    public function createMember(string $email, string $name, bool $skipInvitation = false): CircleMember;

    /**
     * Add an existing community member to an access group. Idempotent at Circle:
     * a duplicate add returns 200 OK.
     */
    public function addToAccessGroup(string $email, string $accessGroupId): void;

    /**
     * Remove a member from an access group. Idempotent: already-absent returns
     * 404 which implementations MUST swallow.
     */
    public function removeFromAccessGroup(string $email, string $accessGroupId): void;
}
