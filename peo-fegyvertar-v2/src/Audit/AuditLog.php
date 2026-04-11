<?php

declare(strict_types=1);

namespace Peoft\Audit;

use Peoft\Core\Clock;
use Peoft\Core\Config\Config;

defined('ABSPATH') || exit;

/**
 * Static-facade entry point for writing to the audit log.
 *
 * Kernel::boot() calls AuditLog::bind($repository, $clock) once per request.
 * After that, any code can call AuditLog::record(...) without dependency-
 * injection plumbing.
 *
 * Writes are best-effort: if the underlying DB write fails, we log to
 * error_log() but never throw — audit must not destabilize the caller.
 */
final class AuditLog
{
    private static ?AuditRepository $repo = null;
    private static ?Clock $clock = null;
    private static ?string $requestId = null;

    public static function bind(AuditRepository $repo, Clock $clock): void
    {
        self::$repo = $repo;
        self::$clock = $clock;
        self::$requestId ??= self::makeRequestId();
    }

    public static function reset(): void
    {
        self::$repo = null;
        self::$clock = null;
        self::$requestId = null;
    }

    public static function requestId(): string
    {
        return self::$requestId ??= self::makeRequestId();
    }

    public static function setRequestId(string $id): void
    {
        self::$requestId = $id;
    }

    public static function record(
        string $actor,
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?array $before = null,
        ?array $after = null,
        ?ApiCallRecord $api = null,
        ?string $error = null,
        ?string $requestId = null,
        ?int $taskId = null,
    ): void {
        if (self::$repo === null || self::$clock === null) {
            error_log("[peoft audit] record() called before bind(): {$actor} {$action}");
            return;
        }
        $event = new AuditEvent(
            occurredAt:  self::$clock->nowUtc(),
            env:         Config::isBound() ? Config::env()->value : (defined('PEOFT_ENV') ? (string) constant('PEOFT_ENV') : 'unknown'),
            actor:       $actor,
            action:      $action,
            subjectType: $subjectType,
            subjectId:   $subjectId,
            taskId:      $taskId,
            requestId:   $requestId ?? self::requestId(),
            before:      $before,
            after:       $after,
            api:         $api,
            error:       $error,
        );
        try {
            self::$repo->insert($event);
        } catch (\Throwable $e) {
            error_log("[peoft audit] insert threw: " . $e->getMessage());
        }
    }

    /**
     * Generate a ULID-ish 26-char correlation id. Uses random bytes so it's
     * unique per request without adding a ULID library dependency.
     */
    private static function makeRequestId(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $bytes = random_bytes(16);
        $id = '';
        foreach (str_split($bytes) as $byte) {
            $id .= $alphabet[ord($byte) & 0x1F];
        }
        // Prefix with a short timestamp segment so IDs sort roughly by time.
        $timePart = base_convert((string) time(), 10, 32);
        return strtoupper(substr($timePart . $id, 0, 26));
    }
}
