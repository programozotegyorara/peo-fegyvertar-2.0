<?php

declare(strict_types=1);

namespace Peoft\Integrations\Mailer;

defined('ABSPATH') || exit;

/**
 * Resolved SMTP config for the current env. Built from the `mailer` section
 * of the ConfigRepository by the Kernel wiring.
 *
 * Field mapping from 1.0's phpmailer_config JSON blob:
 *   host / port / encryption / username / password / from / from_name
 */
final class SmtpConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption,   // 'tls' | 'ssl' | ''
        public readonly string $username,
        public readonly string $password,
        public readonly string $from,
        public readonly string $fromName,
        public readonly ?string $replyTo = null,
        public readonly ?string $bcc = null,
    ) {}
}
