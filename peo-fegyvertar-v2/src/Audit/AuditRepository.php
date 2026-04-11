<?php

declare(strict_types=1);

namespace Peoft\Audit;

use Peoft\Core\Db\Connection;

defined('ABSPATH') || exit;

final class AuditRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function insert(AuditEvent $event): int
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_audit_log');

        $before = $event->before !== null ? $this->encodeAndRedact($event->before) : null;
        $after  = $event->after  !== null ? $this->encodeAndRedact($event->after)  : null;

        $data = [
            'occurred_at'  => $event->occurredAt->format('Y-m-d H:i:s.u'),
            'env'          => $event->env,
            'actor'        => $event->actor,
            'action'       => $event->action,
            'subject_type' => $event->subjectType,
            'subject_id'   => $event->subjectId,
            'task_id'      => $event->taskId,
            'request_id'   => $event->requestId,
            'before_json'  => BodyTruncator::truncate($before),
            'after_json'   => BodyTruncator::truncate($after),
            'api_method'   => $event->api?->method,
            'api_url'      => $event->api?->url,
            'api_status'   => $event->api?->status,
            'api_req_body' => BodyTruncator::truncate($event->api?->reqBody),
            'api_res_body' => BodyTruncator::truncate($event->api?->resBody),
            'duration_ms'  => $event->api?->durationMs,
            'error_msg'    => $event->error,
        ];
        $format = [
            '%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%d','%s','%s','%d','%s',
        ];

        $result = $wpdb->insert($table, $data, $format);
        if ($result === false) {
            // Audit write must never crash the caller. Surface only to error_log.
            error_log('[peoft audit] insert failed: ' . $wpdb->last_error);
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Prune rows older than N days, optionally archiving to gzipped NDJSON first.
     * Returns the number of rows deleted. `$dryRun` returns the would-delete count.
     */
    public function prune(int $olderThanDays, ?string $archivePath = null, bool $dryRun = false): int
    {
        $wpdb = $this->db->wpdb();
        $table = $this->db->table('peoft_audit_log');
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$olderThanDays} days"));

        $countSql = $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE occurred_at < %s",
            $cutoff
        );
        $count = (int) $wpdb->get_var($countSql);

        if ($dryRun || $count === 0) {
            return $count;
        }

        if ($archivePath !== null) {
            $this->archiveBatch($table, $cutoff, $archivePath);
        }

        // Chunked delete to avoid long locks
        $deleted = 0;
        do {
            $batch = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM `{$table}` WHERE occurred_at < %s LIMIT 5000",
                    $cutoff
                )
            );
            $deleted += (int) $batch;
        } while ($batch > 0);

        return $deleted;
    }

    private function encodeAndRedact(array $data): string
    {
        $count = 0;
        $redacted = Redactor::redactArray($data, $count);
        $json = json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{}' : $json;
    }

    private function archiveBatch(string $table, string $cutoff, string $archivePath): void
    {
        if (!is_dir($archivePath) && !mkdir($archivePath, 0o700, true) && !is_dir($archivePath)) {
            throw new \RuntimeException("Cannot create archive path: {$archivePath}");
        }
        $wpdb = $this->db->wpdb();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE occurred_at < %s ORDER BY occurred_at ASC",
                $cutoff
            ),
            ARRAY_A
        ) ?: [];
        if ($rows === []) {
            return;
        }
        $file = rtrim($archivePath, '/') . '/peoft-audit-before-' . gmdate('Ymd-His') . '.ndjson.gz';
        $gz = gzopen($file, 'wb9');
        if ($gz === false) {
            throw new \RuntimeException("Cannot open {$file} for write");
        }
        foreach ($rows as $row) {
            gzwrite($gz, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
        gzclose($gz);
    }
}
