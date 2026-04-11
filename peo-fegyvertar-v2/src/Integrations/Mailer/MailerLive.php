<?php

declare(strict_types=1);

namespace Peoft\Integrations\Mailer;

use Peoft\Audit\ApiCallRecord;
use Peoft\Audit\AuditLog;
use PHPMailer\PHPMailer\PHPMailer;

defined('ABSPATH') || exit;

/**
 * Real PHPMailer-backed SMTP sender. Used when `mailer.mode === 'live'`.
 *
 * In DEV this ends up talking to Mailtrap (or whatever host is in the env file).
 * In UAT/PROD it talks to the real business SMTP.
 *
 * Every send writes a single API_CALL audit row with method=SMTP, the
 * destination host as url, status 250 on success or the PHPMailer error
 * code on failure, and the rendered subject as a correlating crumb.
 * The rendered body is NOT written to audit by default (storage cost +
 * potential PII volume) — only on failure for diagnosis.
 */
final class MailerLive implements Mailer
{
    public function __construct(
        private readonly SmtpConfig $smtp,
        private readonly TemplateRenderer $renderer,
    ) {}

    public function send(string $to, string $templateSlug, array $vars, ?string $replyTo = null): void
    {
        $startedMs = (int) (microtime(true) * 1000);
        $rendered = $this->renderer->render($templateSlug, $vars);

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $this->smtp->host;
        $mail->Port       = $this->smtp->port;
        $mail->SMTPAuth   = $this->smtp->username !== '';
        $mail->Username   = $this->smtp->username;
        $mail->Password   = $this->smtp->password;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'base64';
        if ($this->smtp->encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($this->smtp->encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        $mail->setFrom($this->smtp->from, $this->smtp->fromName);
        $mail->addAddress($to);
        if ($replyTo !== null && $replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }
        if ($this->smtp->bcc !== null && $this->smtp->bcc !== '') {
            $mail->addBCC($this->smtp->bcc);
        }

        $mail->Subject = $rendered['subject'];
        $mail->isHTML(true);
        $mail->Body    = $rendered['html'];
        $mail->AltBody = trim(strip_tags($rendered['html']));

        try {
            $mail->send();
            AuditLog::record(
                actor:  'worker',
                action: 'API_CALL',
                subjectType: 'mailer',
                subjectId:   $to,
                api: new ApiCallRecord(
                    method: 'SMTP',
                    url:    'smtp://' . $this->smtp->host . ':' . $this->smtp->port,
                    status: 250,
                    reqBody: json_encode([
                        'to'       => $to,
                        'subject'  => $rendered['subject'],
                        'template' => $templateSlug,
                    ], JSON_UNESCAPED_UNICODE) ?: null,
                    resBody: null,
                    durationMs: (int) (microtime(true) * 1000) - $startedMs,
                ),
            );
        } catch (\Throwable $e) {
            AuditLog::record(
                actor:  'worker',
                action: 'API_CALL',
                subjectType: 'mailer',
                subjectId:   $to,
                error: substr($e->getMessage(), 0, 500),
                api: new ApiCallRecord(
                    method: 'SMTP',
                    url:    'smtp://' . $this->smtp->host . ':' . $this->smtp->port,
                    status: 0,
                    reqBody: json_encode([
                        'to'       => $to,
                        'subject'  => $rendered['subject'],
                        'template' => $templateSlug,
                    ], JSON_UNESCAPED_UNICODE) ?: null,
                    // On failure we include the rendered body (truncated by
                    // BodyTruncator) so operators can see what would have been sent.
                    resBody: substr($rendered['html'], 0, 8192),
                    durationMs: (int) (microtime(true) * 1000) - $startedMs,
                ),
            );
            // Re-throw so Dispatcher decides retry vs dead. PHPMailer transport
            // exceptions are retryable (temporary SMTP failure).
            throw new \Peoft\Orchestrator\Worker\RetryableException(
                'PHPMailer send failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
