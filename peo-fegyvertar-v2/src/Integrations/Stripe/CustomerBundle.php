<?php

declare(strict_types=1);

namespace Peoft\Integrations\Stripe;

defined('ABSPATH') || exit;

/**
 * Everything about a Stripe customer the plugin typically wants in one fetch.
 *
 * Returned by CustomerContextLoader::fetchCustomerBundle and used to enrich
 * task handlers (SendTransactionalEmailHandler in particular fills ~10 of
 * the 21 sikeres_rendeles template variables from this bundle).
 *
 * Every field is nullable or has an empty default because Stripe data is
 * best-effort — new customers may not have a saved address, trial users
 * may not have a default payment method yet, etc.
 */
final class CustomerBundle
{
    public function __construct(
        public readonly string $customerId,
        public readonly string $email,
        public readonly string $name,
        public readonly StripeAddress $address,
        public readonly ?StripeCardDetails $defaultCard,
        public readonly ?string $defaultTaxId,       // if customer has a tax_id on file
        public readonly ?string $customerPortalUrl,  // Stripe Billing Portal link if available
        public readonly ?string $activeSubscriptionId,
        public readonly ?string $activePriceId,
    ) {}

    public static function minimal(string $customerId, string $email): self
    {
        return new self(
            customerId:            $customerId,
            email:                 $email,
            name:                  '',
            address:               StripeAddress::empty(),
            defaultCard:           null,
            defaultTaxId:          null,
            customerPortalUrl:     null,
            activeSubscriptionId:  null,
            activePriceId:         null,
        );
    }
}
