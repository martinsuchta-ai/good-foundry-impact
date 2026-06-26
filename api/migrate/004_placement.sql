-- 004_placement.sql — named slots within a consumer where projects render.
--
-- Per brief §2: a Placement is a "named slot within a consumer
-- where projects/CTAs render". WBM's bank-side projects rail and
-- vault single-project CTA panel would each be a placement.
--
-- Same idea as the Affiliate platform's aff_placement, scoped to a
-- consumer. Future phases will attach impact_project rows to a
-- placement via product_placement_target (mirrored for projects).

CREATE TABLE IF NOT EXISTS `placement` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `consumer_id` INT UNSIGNED NOT NULL,
    `slug`        VARCHAR(80)  NOT NULL,
    `name`        VARCHAR(120) NOT NULL,
    `description` TEXT         NULL,
    `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_placement_consumer_slug` (`consumer_id`, `slug`),
    KEY `ix_placement_consumer_id` (`consumer_id`),
    CONSTRAINT `fk_placement_consumer` FOREIGN KEY (`consumer_id`)
        REFERENCES `consumer` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
