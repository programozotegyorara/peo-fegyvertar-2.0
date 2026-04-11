-- DWH rebuild run history. Populated by DwhRunner (Phase E).
-- The DWH Status admin page reads the latest 30 rows and shows last success
-- timestamp, row counts per table from stats_json, and error text on failure.

CREATE TABLE IF NOT EXISTS `{prefix}peoft_dwh_runs` (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    started_at DATETIME        NOT NULL,
    ended_at   DATETIME        NULL,
    status     ENUM('running','done','failed') NOT NULL,
    stats_json LONGTEXT        NULL,
    error_text TEXT            NULL,
    PRIMARY KEY (id),
    KEY idx_started_at (started_at),
    KEY idx_status (status)
) {charset_collate};
