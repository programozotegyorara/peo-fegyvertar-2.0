-- DWH: Számlázz invoice cache. Populated by SzamlazzExtractor.
-- Schema matches 1.0's PEOFT_DWH_SZAMLAZZ_INVOICES including raw_payload.

CREATE TABLE IF NOT EXISTS `{prefix}peoft_dwh_szamlazz_invoices` (
    id                       BIGINT        NOT NULL AUTO_INCREMENT,
    szamlazz_invoice_number  VARCHAR(64)   NOT NULL,
    invoice_type             VARCHAR(32)   NULL,
    invoice_status           VARCHAR(32)   NULL,
    order_number             VARCHAR(128)  NULL,
    payment_method           VARCHAR(64)   NULL,
    issue_date               DATE          NULL,
    fulfillment_date         DATE          NULL,
    payment_due_date         DATE          NULL,
    paid_at                  DATETIME      NULL,
    currency                 VARCHAR(16)   NULL,
    net_total                DECIMAL(12,2) NULL,
    vat_total                DECIMAL(12,2) NULL,
    gross_total              DECIMAL(12,2) NULL,
    buyer_name               VARCHAR(255)  NULL,
    buyer_email              VARCHAR(255)  NULL,
    buyer_tax_number         VARCHAR(64)   NULL,
    is_storno                TINYINT(1)    NOT NULL DEFAULT 0,
    corrected_invoice_number VARCHAR(64)   NULL,
    raw_payload              LONGTEXT      NULL,
    imported_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_szamlazz_num (szamlazz_invoice_number),
    KEY idx_order_number (order_number),
    KEY idx_issue_date (issue_date),
    KEY idx_fulfillment_date (fulfillment_date),
    KEY idx_payment_due_date (payment_due_date),
    KEY idx_buyer_name (buyer_name),
    KEY idx_buyer_email (buyer_email)
) {charset_collate};
