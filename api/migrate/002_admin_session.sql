-- 002_admin_session.sql — admin session table.
--
-- Server-side sessions. Cookie carries only the session_id (a long
-- random token). expires_at lets sweeper jobs prune stale rows.
-- last_seen_at supports sliding expiry — every authenticated request
-- pushes expires_at forward so an active admin doesn't get logged
-- out mid-session.

CREATE TABLE IF NOT EXISTS `admin_session` (
    `id`           CHAR(64)     NOT NULL,
    `admin_id`     INT UNSIGNED NOT NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`   DATETIME     NOT NULL,
    `ip_hash`      CHAR(64)     NULL,
    `ua_excerpt`   VARCHAR(500) NULL,
    PRIMARY KEY (`id`),
    KEY `ix_admin_session_admin_id` (`admin_id`),
    KEY `ix_admin_session_expires_at` (`expires_at`),
    CONSTRAINT `fk_admin_session_admin` FOREIGN KEY (`admin_id`)
        REFERENCES `admin_user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
