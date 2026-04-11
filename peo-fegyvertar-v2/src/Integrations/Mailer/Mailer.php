<?php

declare(strict_types=1);

namespace Peoft\Integrations\Mailer;

defined('ABSPATH') || exit;

/**
 * Outbound transactional email.
 *
 * Kernel binds one of two implementations based on `mailer.mode` in the
 * current env's config:
 *   - 'live' → MailerLive (real SMTP via PHPMailer)
 *   - 'mock' → MailerMock (stubbed, writes an API_CALL audit row with mock=true)
 *
 * Handlers never check the mode themselves — they just call Mailer::send.
 *
 * Idempotency note: NOT guaranteed. A worker crash between SMTP success and
 * the Dispatcher's markDone will cause a duplicate send on retry. This is
 * documented and accepted (see plan §6, Mailer section).
 */
interface Mailer
{
    /**
     * @param array<string,mixed> $vars
     */
    public function send(string $to, string $templateSlug, array $vars, ?string $replyTo = null): void;
}
