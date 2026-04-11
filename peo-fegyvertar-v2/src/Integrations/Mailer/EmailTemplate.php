<?php

declare(strict_types=1);

namespace Peoft\Integrations\Mailer;

defined('ABSPATH') || exit;

/**
 * Immutable in-memory representation of one row from peoft_email_templates.
 *
 * `declared` is the list of placeholders the template claims to need, built at
 * import time by scanning the body for `{{name}}` / `{{{name}}}`. The admin
 * template editor (§9.7) re-derives it on save. TemplateRenderer uses it for
 * strict validation.
 */
final class EmailTemplate
{
    /**
     * @param list<string> $declared
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $subject,
        public readonly string $body,
        public readonly array $declared,
    ) {}
}
