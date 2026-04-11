-- Env-scoped email templates. Content editable via admin UI.
-- Migrated from 1.0's PEOFT_EMAIL_TEMPLATES table (21 rows) by
-- ImportFromLegacyDbCommand. Templates use {{placeholder}} substitution.
--
-- `variables_json` declares the placeholders a template expects so the
-- editor can validate against typos at save time.

CREATE TABLE IF NOT EXISTS `{prefix}peoft_email_templates` (
    env            VARCHAR(8)   NOT NULL,
    slug           VARCHAR(128) NOT NULL,
    subject        VARCHAR(255) NOT NULL,
    body           MEDIUMTEXT   NOT NULL,
    variables_json LONGTEXT     NULL,
    updated_at     DATETIME     NOT NULL,
    updated_by     VARCHAR(64)  NULL,
    PRIMARY KEY (env, slug)
) {charset_collate};
