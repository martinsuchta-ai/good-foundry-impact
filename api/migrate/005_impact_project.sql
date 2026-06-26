-- 005_impact_project.sql — the core entity.
--
-- Per brief §4: a project is a STATE MACHINE, not CRUD with a
-- status label. Transitions are gated; some are auto + time-driven.
-- A background scheduler MUST flip planning->execution at start_at
-- and execution->done at end_at, and reconcile on restart.
--
-- This migration creates the BASE schema sufficient for Phase 0
-- (admin can create + persist project drafts in state='mission').
-- The lifecycle scheduler, contribution_ask, contribution_pledge,
-- and safeguarding_record arrive in later migrations (Phase 1a+).
--
-- Brief §6a — location_mode + location_precision are stored here;
-- precision REDUCTION applied server-side at read time
-- (CLAUDE.md §9). Exact lat/long for involves_minors_or_vulnerable
-- projects NEVER reach the public map.

CREATE TABLE IF NOT EXISTS `impact_project` (
    `id`                              INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Sponsor reference. sponsor schema arrives in a later migration;
    -- stored as a string FK for now so this migration can land
    -- standalone for Phase 0 smoke tests.
    `sponsor_id`                      VARCHAR(64)  NULL,

    -- Brief §4 core fields
    `title`                           VARCHAR(200) NOT NULL,
    `description`                     TEXT         NULL,
    `scale`                           ENUM('micro', 'mid', 'macro', 'borderless') NOT NULL DEFAULT 'micro',
    `success_measure`                 TEXT         NULL,
    `state`                           ENUM('mission', 'planning', 'execution', 'done') NOT NULL DEFAULT 'mission',
    `start_at`                        DATETIME     NULL,
    `end_at`                          DATETIME     NULL,

    -- Brief §8 — safeguarding gate
    `involves_minors_or_vulnerable`   TINYINT(1)   NOT NULL DEFAULT 0,
    `verification_status`             ENUM('not_required', 'pending', 'verified', 'rejected') NOT NULL DEFAULT 'not_required',

    -- Brief §6a — location
    `location_mode`                   ENUM('point', 'area', 'none') NOT NULL DEFAULT 'none',
    `latitude`                        DECIMAL(9, 6) NULL,
    `longitude`                       DECIMAL(9, 6) NULL,
    `location_label`                  VARCHAR(200) NULL,
    `location_precision`              ENUM('exact', 'suburb', 'region', 'country') NOT NULL DEFAULT 'suburb',

    -- Brief §4 — rollover lineage
    `rolled_over_from`                INT UNSIGNED NULL,

    `created_at`                      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `ix_impact_project_state` (`state`),
    KEY `ix_impact_project_scale` (`scale`),
    KEY `ix_impact_project_start_at` (`start_at`),
    KEY `ix_impact_project_end_at` (`end_at`),
    KEY `ix_impact_project_sponsor_id` (`sponsor_id`),
    KEY `ix_impact_project_rolled_over_from` (`rolled_over_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
