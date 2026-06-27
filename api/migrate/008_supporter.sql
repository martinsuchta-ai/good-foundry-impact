-- 008_supporter.sql — opt-in supporter records.
--
-- Per brief §2: "Supporter — commits Effort, Energy, or Money to a
-- project, by opting in or responding to a CTA."
--
-- Privacy first (CLAUDE.md §8): no raw IPs, no cookies. Supporters
-- can be:
--   - anonymous (anonymised_user_id populated, display_name + email NULL)
--   - identified (display_name + email populated when the supporter
--     consents to a follow-up — e.g. for fulfilment coordination)
--
-- consumer_id tracks which site they joined via (WBM / future
-- consumers). NULL when seeded directly through admin.

CREATE TABLE IF NOT EXISTS `supporter` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `anonymised_user_id`  CHAR(64)     NULL,
    `display_name`        VARCHAR(160) NULL,
    `email`               VARCHAR(255) NULL,
    `consumer_id`         INT UNSIGNED NULL,
    `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at`        DATETIME     NULL,
    PRIMARY KEY (`id`),
    KEY `ix_supporter_anon` (`anonymised_user_id`),
    KEY `ix_supporter_consumer` (`consumer_id`),
    KEY `ix_supporter_email` (`email`),
    CONSTRAINT `fk_supporter_consumer` FOREIGN KEY (`consumer_id`)
        REFERENCES `consumer` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
