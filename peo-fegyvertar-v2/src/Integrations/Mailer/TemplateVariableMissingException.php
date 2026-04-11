<?php

declare(strict_types=1);

namespace Peoft\Integrations\Mailer;

use Peoft\Orchestrator\Worker\PoisonException;

defined('ABSPATH') || exit;

/**
 * Thrown by TemplateRenderer when a declared placeholder has no corresponding
 * value in the caller's $vars map.
 *
 * Modelled as a PoisonException (subclass) so the Dispatcher marks the task
 * dead immediately: a handler that fails to produce a required variable is
 * a logic bug, not a transient failure, and retrying won't help.
 */
final class TemplateVariableMissingException extends PoisonException
{
    /**
     * @param list<string> $missing
     */
    public function __construct(
        public readonly string $templateSlug,
        public readonly array $missing,
    ) {
        parent::__construct(sprintf(
            "Template '%s' requires variables not provided by handler: %s",
            $templateSlug,
            implode(', ', $missing)
        ));
    }
}
