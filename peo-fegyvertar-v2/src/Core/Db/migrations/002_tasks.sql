-- Fulfillment task outbox. The heart of the orchestrator.
-- One row per downstream side-effect that needs to happen as the result of
-- a Stripe event or an admin manual trigger.
--
-- Status lifecycle:
--   pending → running → done
--                    → pending (retry with backoff)
--                    → failed / dead
--
-- `idempotency_key` is a deterministic sha256; UNIQUE prevents any two
-- concurrent enqueues from both calling a downstream.

CREATE TABLE IF NOT EXISTS `{prefix}peoft_tasks` (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idempotency_key   CHAR(64)        NOT NULL,
    task_type         VARCHAR(64)     NOT NULL,
    stripe_ref        VARCHAR(255)    NULL,
    payload_json      LONGTEXT        NULL,
    status            ENUM('pending','running','done','failed','dead') NOT NULL DEFAULT 'pending',
    attempts          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_run_at       DATETIME        NOT NULL,
    last_error        TEXT            NULL,
    source_event_id   VARCHAR(255)    NULL,
    actor             VARCHAR(64)     NOT NULL DEFAULT 'stripe',
    created_at        DATETIME        NOT NULL,
    updated_at        DATETIME        NOT NULL,
    started_at        DATETIME        NULL,
    finished_at       DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idempotency_key (idempotency_key),
    KEY idx_status_next_run (status, next_run_at),
    KEY idx_task_type (task_type),
    KEY idx_stripe_ref (stripe_ref),
    KEY idx_source_event (source_event_id)
) {charset_collate};
