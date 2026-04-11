-- Webhook event dedupe ledger.
-- One row per Stripe event (or other future source) we've ever seen.
-- Ensures we never process the same event twice.

CREATE TABLE IF NOT EXISTS `{prefix}peoft_webhook_events` (
    event_id     VARCHAR(255) NOT NULL,
    source       VARCHAR(32)  NOT NULL DEFAULT 'stripe',
    env          VARCHAR(8)   NOT NULL,
    received_at  DATETIME     NOT NULL,
    payload_hash CHAR(40)     NULL,
    PRIMARY KEY (event_id),
    KEY idx_received_at (received_at),
    KEY idx_source_env (source, env)
) {charset_collate};
