-- DWH: Stripe refunds cache.

CREATE TABLE IF NOT EXISTS `{prefix}peoft_dwh_refunds_data` (
    refund_id       VARCHAR(255)  NOT NULL,
    charge_id       VARCHAR(255)  NULL,
    payment_intent  VARCHAR(255)  NULL,
    invoice_id      VARCHAR(255)  NULL,
    customer_id     VARCHAR(255)  NULL,
    amount          DECIMAL(10,2) NULL,
    currency        VARCHAR(16)   NULL,
    status          VARCHAR(64)   NULL,
    reason          VARCHAR(64)   NULL,
    created         DATETIME      NULL,
    raw_json        LONGTEXT      NULL,
    imported_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (refund_id),
    KEY idx_invoice_id (invoice_id),
    KEY idx_customer_id (customer_id)
) {charset_collate};
