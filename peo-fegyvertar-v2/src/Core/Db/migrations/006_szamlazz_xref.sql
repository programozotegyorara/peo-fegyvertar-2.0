-- Orchestrator-owned idempotency oracle for Számlázz writes.
-- Independent of the nightly DWH Számlázz cache so retries within a day
-- still have a hard guarantee that we never issue the same invoice twice.
--
-- `ref_kind = 'invoice'` → `stripe_ref` is a Stripe invoice id.
-- `ref_kind = 'storno'`  → `stripe_ref` is a Stripe charge id, and
--                          `linked_original` holds the original Számlázz
--                          document number being reversed.

CREATE TABLE IF NOT EXISTS `{prefix}peoft_szamlazz_xref` (
    env                      VARCHAR(8)   NOT NULL,
    stripe_ref               VARCHAR(255) NOT NULL,
    ref_kind                 ENUM('invoice','storno') NOT NULL,
    szamlazz_document_number VARCHAR(64)  NOT NULL,
    linked_original          VARCHAR(64)  NULL,
    created_at               DATETIME     NOT NULL,
    PRIMARY KEY (env, stripe_ref, ref_kind),
    KEY idx_szamlazz_doc (szamlazz_document_number)
) {charset_collate};
