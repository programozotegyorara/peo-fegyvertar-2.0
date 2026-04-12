<?php

declare(strict_types=1);

namespace Peoft\Admin;

use Peoft\Core\Env;

defined('ABSPATH') || exit;

/**
 * Renders a sticky header banner identifying the current environment.
 *
 * Per plan §12:
 *   - DEV:  blue  "DEV ENVIRONMENT — using test credentials"
 *   - UAT:  orange "UAT ENVIRONMENT — using test credentials"
 *   - PROD: red   "PROD ENVIRONMENT" (always visible, no dismiss-after-first-login)
 *
 * The banner is rendered at the top of every admin page in the peo-fegyvertar
 * menu, so operators always know which environment's data they're touching
 * before they click anything.
 */
final class EnvBanner
{
    public static function render(Env $env): void
    {
        [$color, $label, $detail] = match ($env) {
            Env::Dev  => ['#2563eb', 'DEV',  'LOCAL — test credentials, mock downstreams. Safe to break.'],
            Env::Uat  => ['#ea580c', 'UAT',  'Staging — test credentials, real downstream accounts where provisioned.'],
            Env::Prod => ['#dc2626', 'PROD', 'LIVE — changes affect real customers, real Stripe charges, real Hungarian tax invoices.'],
        };

        $labelEsc = esc_html($label);
        $detailEsc = esc_html($detail);

        echo <<<HTML
<div class="peoft-env-banner" style="
    background: {$color};
    color: #fff;
    padding: 10px 16px;
    margin: 0 -20px 18px -20px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 14px;
    line-height: 1.5;
    border-bottom: 2px solid rgba(0,0,0,0.15);
">
    <strong style="font-size: 16px; margin-right: 10px;">{$labelEsc}</strong>
    <span>{$detailEsc}</span>
</div>
HTML;
    }
}
