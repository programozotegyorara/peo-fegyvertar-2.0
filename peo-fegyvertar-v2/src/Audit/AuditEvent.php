<?php

declare(strict_types=1);

namespace Peoft\Audit;

defined('ABSPATH') || exit;

/**
 * The immutable row shape written to peoft_audit_log.
 *
 * The enumerated list of valid `action` values:
 *   WEBHOOK_RECEIVED, WEBHOOK_SIG_FAILED, WEBHOOK_DUPLICATE, WEBHOOK_ROUTE_FAILED,
 *   TASK_ENQUEUED, TASK_STARTED, TASK_SUCCEEDED,
 *   TASK_FAILED_RETRY, TASK_FAILED_DEAD,
 *   TASK_MANUAL_RETRY, TASK_MANUAL_SKIP, TASK_MANUAL_CANCEL,
 *   TASK_EDITED, TASK_VALIDATION_FAILED,
 *   API_CALL,
 *   CONFIG_CHANGED, CONFIG_READ, CONFIG_REJECTED,
 *   TEMPLATE_CHANGED, TEMPLATE_PROMOTED,
 *   PDF_DOWNLOADED, GDPR_SCRUB_RAN,
 *   IMPORT_RUN,
 *   DWH_RUN_STARTED, DWH_RUN_DONE, DWH_RUN_FAILED,
 *   PLUGIN_ACTIVATED, PLUGIN_DEACTIVATED
 */
final class AuditEvent
{
    public function __construct(
        public readonly \DateTimeImmutable $occurredAt,
        public readonly string $env,
        public readonly string $actor,
        public readonly string $action,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
        public readonly ?int $taskId = null,
        public readonly ?string $requestId = null,
        public readonly ?array $before = null,
        public readonly ?array $after = null,
        public readonly ?ApiCallRecord $api = null,
        public readonly ?string $error = null,
    ) {}
}
