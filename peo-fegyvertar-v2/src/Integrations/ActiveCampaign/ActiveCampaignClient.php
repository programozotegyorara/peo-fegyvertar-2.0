<?php

declare(strict_types=1);

namespace Peoft\Integrations\ActiveCampaign;

defined('ABSPATH') || exit;

/**
 * ActiveCampaign client — interface only.
 *
 * Kernel binds one of:
 *   - `mock` → ActiveCampaignClientMock (stubs, audit logs with mock=true)
 *   - `live` → ActiveCampaignClientLive (real HTTP via ApiClient)
 *
 * Handlers receive a `ActiveCampaignClient` and never know which implementation
 * they got. The mode decision lives entirely in Kernel.
 *
 * All methods are expected to be idempotent: findContactByEmail is read-only;
 * upsertContact POSTs to /contact/sync which AC treats as upsert-by-email;
 * tagContact / untagContact check hasTag first and no-op if already in the
 * desired state.
 */
interface ActiveCampaignClient
{
    /**
     * @return AcContact|null null if not found
     */
    public function findContactByEmail(string $email): ?AcContact;

    /**
     * Create-or-update by email. Returns the resulting contact.
     */
    public function upsertContact(string $email, ?string $firstName = null, ?string $lastName = null): AcContact;

    /**
     * Assign a tag to a contact by name. Idempotent — no-op if already tagged.
     * Uses TagResolver to translate name → id internally.
     */
    public function tagContact(string $email, string $tagName): void;

    /**
     * Remove a tag from a contact by name. Idempotent — no-op if not tagged.
     */
    public function untagContact(string $email, string $tagName): void;

    /**
     * Returns true if the contact exists and currently has $tagName.
     */
    public function hasTag(string $email, string $tagName): bool;
}
