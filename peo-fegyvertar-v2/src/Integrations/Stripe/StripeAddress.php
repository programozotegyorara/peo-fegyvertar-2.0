<?php

declare(strict_types=1);

namespace Peoft\Integrations\Stripe;

defined('ABSPATH') || exit;

/**
 * Flattened address DTO built from Stripe's nested `address` objects
 * (customer.address, invoice.customer_address, etc.). All fields optional
 * because Stripe data is best-effort.
 */
final class StripeAddress
{
    public function __construct(
        public readonly string $country = '',
        public readonly string $postalCode = '',
        public readonly string $city = '',
        public readonly string $line1 = '',
        public readonly string $line2 = '',
        public readonly string $state = '',
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->country === ''
            && $this->postalCode === ''
            && $this->city === ''
            && $this->line1 === '';
    }
}
