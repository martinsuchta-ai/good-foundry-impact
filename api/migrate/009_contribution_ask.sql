-- 009_contribution_ask.sql — what a project asks for in each lane.
--
-- Per brief §5: a project opens one or more "asks" per lane. Each
-- ask carries a label + target + (money only) an external regulated
-- destination URL.
--
-- HARD RULES enforced at the column level:
--   - lane is constrained to the 3 brief values.
--   - external_destination_url is ONLY for lane='money' (route-only
--     per §6 — GMI does NOT custody funds). Application code should
--     reject inserts where it's populated on effort/energy lanes.
--
-- target_quantity is decimal so $ amounts + fractional hours both
-- fit. target_unit is the display string ('hours', 'people', 'AUD',
-- 'items').

CREATE TABLE IF NOT EXISTS `contribution_ask` (
    `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `impact_project_id`         INT UNSIGNED NOT NULL,
    `lane`                      ENUM('effort', 'energy', 'money') NOT NULL,
    `label`                     VARCHAR(200) NOT NULL,
    `description`               TEXT         NULL,
    `target_quantity`           DECIMAL(10,2) NULL,
    `target_unit`               VARCHAR(40)  NULL,
    `external_destination_url`  VARCHAR(500) NULL,
    `is_active`                 TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_ask_project` (`impact_project_id`),
    KEY `ix_ask_lane` (`lane`),
    KEY `ix_ask_active` (`is_active`),
    CONSTRAINT `fk_ask_project` FOREIGN KEY (`impact_project_id`)
        REFERENCES `impact_project` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
