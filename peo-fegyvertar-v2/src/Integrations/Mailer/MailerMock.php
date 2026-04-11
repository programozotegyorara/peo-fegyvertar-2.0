<?php

declare(strict_types=1);

namespace Peoft\Integrations\Mailer;

use Peoft\Audit\ApiCallRecord;
use Peoft\Audit\AuditLog;

defined('ABSPATH') || exit;

/**
 * Mock mailer. Used when `mailer.mode === 'mock'`.
 *
 * Does NOT send a real email. Writes an API_CALL audit row with method=SMTP
 * and a `mock=true` marker in the request body so operators can see what
 * *would* have been sent. The rendered subject is captured; the body is
 * recorded (truncated) so template development can inspect it via the Audit
 * Viewer.
 *
 * Template validation still runs — this is intentional. A handler that
 * forgets to provide a declared variable fails just as loudly in mock mode
 * as in live mode, so the bug surfaces in DEV before it reaches UAT/PROD.
 */
final class MailerMock implements Mailer
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
    ) {}

    public function send(string $to, string $templateSlug, array $vars, ?string $replyTo = null): void
    {
        $startedMs = (int) (microtime(true) * 1000);
        $rendered = $this->renderer->render($templateSlug, $vars);

        AuditLog::record(
            actor:  'worker',
            action: 'API_CALL',
            subjectType: 'mailer',
            subjectId:   $to,
            api: new ApiCallRecord(
                method: 'SMTP',
                url:    'smtp://mock',
                status: 250,
                reqBody: json_encode([
                    'mock'      => true,
                    'to'        => $to,
                    'reply_to'  => $replyTo,
                    'template'  => $templateSlug,
                    'subject'   => $rendered['subject'],
                    'vars'      => $vars,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
                resBody: $rendered['html'],
                durationMs: (int) (microtime(true) * 1000) - $startedMs,
            ),
        );
    }
}
