<?php

declare(strict_types=1);

namespace Peoft\Integrations\Circle;

defined('ABSPATH') || exit;

/**
 * Immutable view of one Circle community member, as returned by
 * `/api/admin/v2/community_members` endpoints.
 *
 * Circle returns richer payloads than this (avatar URL, role, joined_at,
 * invitation status, etc.) but the only fields handlers and the Phase D
 * reconciliation page currently care about are id, email, and name.
 */
final class CircleMember
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly ?string $name,
    ) {}
}
