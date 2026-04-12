-- DWH: full refresh of Stripe subscriptions. TRUNCATE+INSERT on each rebuild.

CREATE TABLE IF NOT EXISTS `{prefix}peoft_dwh_subscription_data` (
    subscription_id      VARCHAR(255) NOT NULL,
    customer_id          VARCHAR(255) NULL,
    status               VARCHAR(64)  NULL,
    current_period_start DATETIME     NULL,
    current_period_end   DATETIME     NULL,
    cancel_at            DATETIME     NULL,
    canceled_at          DATETIME     NULL,
    ended_at             DATETIME     NULL,
    created              DATETIME     NULL,
    currency             VARCHAR(16)  NULL,
    amount               DECIMAL(10,2) NULL,
    interval_unit        VARCHAR(16)  NULL,
    raw_json             LONGTEXT     NULL,
    imported_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (subscription_id),
    KEY idx_customer (customer_id),
    KEY idx_status (status)
) {charset_collate};
