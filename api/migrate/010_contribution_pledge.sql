-- 010_contribution_pledge.sql — a supporter's commitment against an ask.
--
-- Per brief §5:
--   Effort + Energy lanes: pledge → confirm → fulfilled lifecycle.
--   Money lane: pledge → (redirect logged, status stays 'pledged');
--   no confirm/fulfil because GMI doesn't custody funds (§6).
--
-- supporter_id is NULLABLE for anonymous money-lane pledges where
-- the supporter chose not to identify (their click-through is logged
-- but no supporter row is created).
--
-- lane is denormalised here so reporting queries don't need to
-- always join through contribution_ask.

CREATE TABLE IF NOT EXISTS `contribution_pledge` (
    `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `contribution_ask_id`  INT UNSIGNED NOT NULL,
    `supporter_id`         INT UNSIGNED NULL,
    `lane`                 ENUM('effort', 'energy', 'money') NOT NULL,
    `quantity`             DECIMAL(10,2) NULL,
    `status`               ENUM('pledged', 'confirmed', 'fulfilled', 'withdrawn') NOT NULL DEFAULT 'pledged',
    `note`                 TEXT          NULL,
    `pledged_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `confirmed_at`         DATETIME      NULL,
    `fulfilled_at`         DATETIME      NULL,
    `withdrawn_at`         DATETIME      NULL,
    PRIMARY KEY (`id`),
    KEY `ix_pledge_ask` (`contribution_ask_id`),
    KEY `ix_pledge_supporter` (`supporter_id`),
    KEY `ix_pledge_status` (`status`),
    KEY `ix_pledge_lane` (`lane`),
    CONSTRAINT `fk_pledge_ask` FOREIGN KEY (`contribution_ask_id`)
        REFERENCES `contribution_ask` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pledge_supporter` FOREIGN KEY (`supporter_id`)
        REFERENCES `supporter` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
