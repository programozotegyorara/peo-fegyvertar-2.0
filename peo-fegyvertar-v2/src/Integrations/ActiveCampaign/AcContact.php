<?php

declare(strict_types=1);

namespace Peoft\Integrations\ActiveCampaign;

defined('ABSPATH') || exit;

/**
 * Immutable view of one ActiveCampaign contact, as returned by AC's
 * /api/3/contacts endpoints.
 */
final class AcContact
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
    ) {}
}
