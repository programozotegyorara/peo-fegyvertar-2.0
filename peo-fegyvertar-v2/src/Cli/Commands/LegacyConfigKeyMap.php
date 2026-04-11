<?php

declare(strict_types=1);

namespace Peoft\Cli\Commands;

defined('ABSPATH') || exit;

/**
 * Translates 1.0 PEOFT_CONFIG row keys into 2.0 fq-key format.
 *
 * Unknown keys map to null → ImportFromLegacyDbCommand skips them.
 * The `phpmailer_config` JSON blob is handled specially (flattened into
 * mailer.*) and the `catalog.*` keys similarly may need JSON decode.
 */
final class LegacyConfigKeyMap
{
    /** @var array<string, string|null> */
    private const SIMPLE_MAP = [
        'stripe_sk'                 => 'stripe.secret_key',
        'stripe_pk'                 => 'stripe.publishable_key',
        'stripe_whsec_payment_link' => 'stripe.webhook_secret',
        'circle_v2_api_key'         => 'circle.v2_api_key',
        'circle_v2_access_group'    => 'circle.access_group_id',
        'circle_community_id'       => 'circle.community_id',
        'circle_space_id'           => 'circle.space_id',
        'circle_space_group_id'     => 'circle.space_group_id',
        'circles_api_key'           => null, // v1 Circle, dead in 2.0
        'ac_api_url'                => 'activecampaign.api_url',
        'ac_api_key'                => 'activecampaign.api_key',
        'szamlazz_api_key'          => 'szamlazz.api_key',
        'szamlazz_elotag'           => 'szamlazz.prefix',
        'error_email_recipients'    => 'notifications.error_recipients',
        'trial_price'               => 'catalog.trial_price',
        'trial_period_7days'        => 'catalog.trial_days',
        'prices'                    => 'catalog.prices',
        'products'                  => 'catalog.products',
    ];

    /**
     * Returns the 2.0 fq-key for a 1.0 config key, or null to skip.
     */
    public static function to2(string $legacyKey): ?string
    {
        return self::SIMPLE_MAP[$legacyKey] ?? null;
    }

    /**
     * Is this legacy key handled with custom logic (not the simple map)?
     * Currently only `phpmailer_config` — the JSON blob gets flattened
     * into mailer.host, mailer.port, mailer.username, etc. However, in
     * non-prod imports the SMTP config is skipped entirely to prevent
     * real emails from leaking out of DEV.
     */
    public static function isSpecial(string $legacyKey): bool
    {
        return $legacyKey === 'phpmailer_config';
    }
}
