-- Env-scoped config store.
-- Rows live at layer 3 in the ConfigLoader precedence (below peoft-env.php,
-- above wp_options fallback). `is_secret=1` rows are masked by ConfigEditor
-- in the admin UI unless the admin re-authenticates to reveal.

CREATE TABLE IF NOT EXISTS `{prefix}peoft_config` (
    env          VARCHAR(8)   NOT NULL,
    config_key   VARCHAR(128) NOT NULL,
    config_value MEDIUMTEXT   NULL,
    is_secret    TINYINT(1)   NOT NULL DEFAULT 0,
    updated_at   DATETIME     NOT NULL,
    updated_by   VARCHAR(64)  NULL,
    PRIMARY KEY (env, config_key)
) {charset_collate};
