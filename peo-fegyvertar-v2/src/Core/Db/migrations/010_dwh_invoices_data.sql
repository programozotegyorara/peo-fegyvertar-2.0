-- DWH: Stripe invoices cache. Upsert on invoice_id per rebuild.

CREATE TABLE IF NOT EXISTS `{prefix}peoft_dwh_invoices_data` (
    invoice_id      VARCHAR(255)  NOT NULL,
    subscription_id VARCHAR(255)  NULL,
    customer_id     VARCHAR(255)  NULL,
    status          VARCHAR(64)   NULL,
    amount_due      DECIMAL(10,2) NULL,
    amount_paid     DECIMAL(10,2) NULL,
    currency        VARCHAR(16)   NULL,
    created         DATETIME      NULL,
    paid_at         DATETIME      NULL,
    raw_json        LONGTEXT      NULL,
    imported_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (invoice_id),
    KEY idx_sub (subscription_id),
    KEY idx_customer (customer_id),
    KEY idx_created (created)
) {charset_collate};
