<?php

declare(strict_types=1);

namespace Peoft\Integrations\Stripe;

use Stripe\Event as StripeEvent;
use Stripe\Webhook as StripeWebhookSdk;

defined('ABSPATH') || exit;

/**
 * Thin wrapper around \Stripe\Webhook::constructEvent.
 *
 * Isolates the Stripe SDK from the WebhookController and makes the
 * verification path easy to swap in tests.
 *
 * Always throws on failure — callers should catch \Throwable and respond 400.
 */
final class StripeWebhookVerifier
{
    /** Stripe's default 5-minute tolerance on the signature timestamp. */
    public const DEFAULT_TOLERANCE = 300;

    public function verify(string $rawBody, string $signatureHeader, string $secret): StripeEvent
    {
        if ($secret === '') {
            throw new \RuntimeException('Stripe webhook secret is empty — cannot verify signature.');
        }
        return StripeWebhookSdk::constructEvent($rawBody, $signatureHeader, $secret, self::DEFAULT_TOLERANCE);
    }
}
