-- DWH: daily subscription snapshot for point-in-time KPI calculations.
-- ~14 day retention per 1.0 observed behavior.

CREATE TABLE IF NOT EXISTS `{prefix}peoft_dwh_subscription_snapshots` (
    snapshot_date        DATE         NOT NULL,
    subscription_id      VARCHAR(255) NOT NULL,
    customer_id          VARCHAR(255) NULL,
    status               VARCHAR(64)  NULL,
    current_period_start DATETIME     NULL,
    current_period_end   DATETIME     NULL,
    cancel_at            DATETIME     NULL,
    canceled_at          DATETIME     NULL,
    created              DATETIME     NULL,
    currency             VARCHAR(16)  NULL,
    amount               DECIMAL(10,2) NULL,
    interval_unit        VARCHAR(16)  NULL,
    PRIMARY KEY (snapshot_date, subscription_id)
) {charset_collate};
