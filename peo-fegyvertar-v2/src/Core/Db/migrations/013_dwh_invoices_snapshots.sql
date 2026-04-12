-- DWH: daily invoice snapshot.

CREATE TABLE IF NOT EXISTS `{prefix}peoft_dwh_invoices_snapshots` (
    snapshot_date   DATE          NOT NULL,
    invoice_id      VARCHAR(255)  NOT NULL,
    subscription_id VARCHAR(255)  NULL,
    customer_id     VARCHAR(255)  NULL,
    status          VARCHAR(64)   NULL,
    amount_due      DECIMAL(10,2) NULL,
    amount_paid     DECIMAL(10,2) NULL,
    currency        VARCHAR(16)   NULL,
    created         DATETIME      NULL,
    paid_at         DATETIME      NULL,
    PRIMARY KEY (snapshot_date, invoice_id)
) {charset_collate};
