-- 003_consumer.sql — sites embedding Impact (WBM is consumer #1).
--
-- Same model as the Affiliate platform's aff_consumer — a registered
-- partner site authenticates via api_key and CORS preflight is
-- limited to widget_origins (JSON array of allowed origins).
--
-- Per brief §2 + §7: every consuming site is a registered consumer
-- with its own key and allowed origins. WBM gets the first row.

CREATE TABLE IF NOT EXISTS `consumer` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`           VARCHAR(120) NOT NULL,
    `slug`           VARCHAR(60)  NOT NULL,
    `api_key`        CHAR(64)     NOT NULL,
    `widget_origins` JSON         NULL,
    `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
    `notes`          TEXT         NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_consumer_slug` (`slug`),
    UNIQUE KEY `uq_consumer_api_key` (`api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
