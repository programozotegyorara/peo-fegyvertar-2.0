-- Append-only audit trail.
-- Every webhook received, every task state transition, every admin action,
-- every downstream API request+response is recorded here. MEDIUMTEXT columns
-- are capped to 64KB by BodyTruncator and scrubbed by Redactor before write.
--
-- request_id is a ULID correlating every row produced by the same unit of work
-- (one webhook, one worker tick run, one admin REST call).

CREATE TABLE IF NOT EXISTS `{prefix}peoft_audit_log` (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    occurred_at   DATETIME(6)     NOT NULL,
    env           VARCHAR(8)      NOT NULL,
    actor         VARCHAR(64)     NOT NULL,
    action        VARCHAR(64)     NOT NULL,
    subject_type  VARCHAR(32)     NULL,
    subject_id    VARCHAR(255)    NULL,
    task_id       BIGINT UNSIGNED NULL,
    request_id    CHAR(26)        NULL,
    before_json   MEDIUMTEXT      NULL,
    after_json    MEDIUMTEXT      NULL,
    api_method    VARCHAR(8)      NULL,
    api_url       TEXT            NULL,
    api_status    SMALLINT        NULL,
    api_req_body  MEDIUMTEXT      NULL,
    api_res_body  MEDIUMTEXT      NULL,
    duration_ms   INT             NULL,
    error_msg     TEXT            NULL,
    PRIMARY KEY (id),
    KEY idx_occurred_at (occurred_at),
    KEY idx_task_id (task_id),
    KEY idx_action (action),
    KEY idx_request_id (request_id),
    KEY idx_subject (subject_type, subject_id)
) {charset_collate};
