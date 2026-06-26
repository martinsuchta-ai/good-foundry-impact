-- 006_project_transition_log.sql — audit trail for impact_project
-- state transitions.
--
-- Every flip — auto-scheduler, manual admin, sponsor-close-early —
-- writes a row here. Restart reconciliation reads this log to see
-- what the scheduler did vs what it SHOULD have done, so we can
-- catch a missed transition window.
--
-- Brief §4: "a background scheduler MUST flip planning->execution
-- at start_at, flip execution->done at end_at, fire transition-driven
-- CTAs/reminders, never silently skip a transition if the worker was
-- down — on restart, reconcile any projects whose times have passed."
--
-- This table is HOW we reconcile. Without it, a missed transition
-- looks identical to a transition that fired normally.

CREATE TABLE IF NOT EXISTS `project_transition_log` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_id`       INT UNSIGNED NOT NULL,
    `from_state`       ENUM('mission', 'planning', 'execution', 'done') NOT NULL,
    `to_state`         ENUM('mission', 'planning', 'execution', 'done') NOT NULL,
    `transitioned_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `transition_type`  ENUM('auto_scheduler', 'manual_admin', 'sponsor_close_early', 'reconciliation') NOT NULL DEFAULT 'auto_scheduler',
    `reason`           VARCHAR(500) NULL,
    `triggered_by`     INT UNSIGNED NULL,    -- admin_user.id when manual; NULL when auto
    `scheduled_for`    DATETIME     NULL,    -- start_at or end_at the transition was due against — diagnostic for reconciliation
    PRIMARY KEY (`id`),
    KEY `ix_ptl_project_id` (`project_id`),
    KEY `ix_ptl_transitioned_at` (`transitioned_at`),
    KEY `ix_ptl_transition_type` (`transition_type`),
    CONSTRAINT `fk_ptl_project` FOREIGN KEY (`project_id`)
        REFERENCES `impact_project` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ptl_admin` FOREIGN KEY (`triggered_by`)
        REFERENCES `admin_user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
