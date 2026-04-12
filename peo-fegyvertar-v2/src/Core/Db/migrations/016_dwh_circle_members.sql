-- DWH: Circle community members cache. Populated by CircleExtractor.

CREATE TABLE IF NOT EXISTS `{prefix}peoft_dwh_circle_members` (
    circle_member_id       VARCHAR(64)  NOT NULL,
    circle_user_id         VARCHAR(64)  NULL,
    public_uid             VARCHAR(64)  NULL,
    community_id           VARCHAR(64)  NULL,
    space_group_id         VARCHAR(64)  NULL,
    email                  VARCHAR(255) NULL,
    name                   VARCHAR(255) NULL,
    first_name             VARCHAR(255) NULL,
    last_name              VARCHAR(255) NULL,
    active                 TINYINT(1)   NULL,
    accepted_invitation_at DATETIME     NULL,
    last_seen_at           DATETIME     NULL,
    topics_count           INT          NULL DEFAULT 0,
    posts_count            INT          NULL DEFAULT 0,
    comments_count         INT          NULL DEFAULT 0,
    member_tags            TEXT         NULL,
    raw_payload            LONGTEXT     NULL,
    imported_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (circle_member_id),
    KEY idx_email (email)
) {charset_collate};
