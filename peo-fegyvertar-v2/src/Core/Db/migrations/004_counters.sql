-- Singleton counter store. Collapses 1.0's PEOFT_COUNTERS (which was a row-per-
-- allocation log of ~16k rows) into one row per (env, counter_key).
-- Seeded at `value = MAX(PEOFT_COUNTERS.id) + 1` by ImportFromLegacyDbCommand.

CREATE TABLE IF NOT EXISTS `{prefix}peoft_counters` (
    counter_key VARCHAR(64)     NOT NULL,
    env         VARCHAR(8)      NOT NULL,
    value       BIGINT UNSIGNED NOT NULL,
    updated_at  DATETIME        NOT NULL,
    PRIMARY KEY (env, counter_key)
) {charset_collate};
