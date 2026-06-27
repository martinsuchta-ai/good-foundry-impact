-- 012_click_event.sql — outbound click attribution.
--
-- Brief §7: "Logged redirect endpoint — for money-lane outbound
-- links and for CTA click attribution."
--
-- When a supporter clicks through to a money-lane external rail
-- (GoFundMe, ACNC, etc.), or clicks any consumer-side CTA pointed
-- at /api/v1/go.php, we record the click here. This is the
-- attribution layer for Phase 1h impact reporting (which consumer
-- drove which click vs which pledge).
--
-- Privacy (CLAUDE.md §8): no raw IPs, no cookies. anonymised_user_id
-- is SHA-256(ip + session_secret + daily salt) — same helper the
-- pledge endpoint uses, so a supporter pledging + clicking through
-- on the same day groups to the same hash for reporting.
--
-- supporter_id is nullable — anonymous clicks don't get a
-- supporter row created (unlike pledges which always create one).
-- redirect_to is snapshotted at click time in case the ask's URL
-- later changes.

CREATE TABLE IF NOT EXISTS `click_event` (
    `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `contribution_ask_id`  INT UNSIGNED NOT NULL,
    `consumer_id`          INT UNSIGNED NULL,
    `supporter_id`         INT UNSIGNED NULL,
    `anonymised_user_id`   CHAR(64)     NOT NULL,
    `redirect_to`          VARCHAR(500) NOT NULL,
    `ua_excerpt`           VARCHAR(500) NULL,
    `clicked_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_click_ask` (`contribution_ask_id`),
    KEY `ix_click_consumer` (`consumer_id`),
    KEY `ix_click_supporter` (`supporter_id`),
    KEY `ix_click_anon` (`anonymised_user_id`),
    KEY `ix_click_clicked_at` (`clicked_at`),
    CONSTRAINT `fk_click_ask` FOREIGN KEY (`contribution_ask_id`)
        REFERENCES `contribution_ask` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_click_consumer` FOREIGN KEY (`consumer_id`)
        REFERENCES `consumer` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_click_supporter` FOREIGN KEY (`supporter_id`)
        REFERENCES `supporter` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
