-- 007_sponsor.sql — the creator/steward of an impact project.
--
-- Per brief §2: "Sponsor — the creator/steward of a project. Defines
-- it, runs it, reports its outcome, may roll it over."
--
-- Identity verification (brief §8) is captured here at the SPONSOR
-- level rather than per-project: a verified sponsor can spin up
-- multiple projects without re-verifying. Per-project safeguarding
-- (WWCC etc.) still lives in safeguarding_record (Phase 1c).
--
-- email is nullable because Phase 1b lets admins seed sponsor stubs
-- without a contact email. Phase 1c+ will tighten this when sponsors
-- self-sign-up.

CREATE TABLE IF NOT EXISTS `sponsor` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `display_name`   VARCHAR(160) NOT NULL,
    `email`          VARCHAR(255) NULL,
    `org_name`       VARCHAR(200) NULL,
    `verified`       TINYINT(1)   NOT NULL DEFAULT 0,
    `notes`          TEXT         NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_sponsor_email` (`email`),
    KEY `ix_sponsor_verified` (`verified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
