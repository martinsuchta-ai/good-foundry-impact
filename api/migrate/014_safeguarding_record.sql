-- 014_safeguarding_record.sql — audit trail for §8 verification decisions.
--
-- Brief §8 hard rule: a project flagged involves_minors_or_vulnerable=1
-- CANNOT enter planning or execution until verification_status='verified'.
-- The state engine (planning -> execution scan) and the admin transition
-- endpoint both enforce the gate.
--
-- This migration adds the AUDIT LAYER — every flip of verification_status
-- writes a row here recording who decided, what evidence they reviewed,
-- and any notes. Regulators / auditors / sponsors can prove the chain of
-- review without trawling git history.
--
-- Also backfills any existing rows where involves_minors_or_vulnerable=1
-- AND verification_status='not_required' (which is the wrong default for
-- a vulnerable project — it slipped through if the admin set the flag
-- without explicitly choosing pending). After backfill, those projects
-- correctly sit as 'pending' awaiting admin review.

CREATE TABLE IF NOT EXISTS `safeguarding_record` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `impact_project_id`   INT UNSIGNED NOT NULL,
    `prior_status`        ENUM('not_required', 'pending', 'verified', 'rejected') NOT NULL,
    `new_status`          ENUM('not_required', 'pending', 'verified', 'rejected') NOT NULL,
    `reviewer_admin_id`   INT UNSIGNED NULL,
    `documents_reviewed`  TEXT NULL,
    `notes`               TEXT NULL,
    `decided_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_safe_project` (`impact_project_id`),
    KEY `ix_safe_reviewer` (`reviewer_admin_id`),
    KEY `ix_safe_decided` (`decided_at`),
    CONSTRAINT `fk_safe_project` FOREIGN KEY (`impact_project_id`)
        REFERENCES `impact_project` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_safe_reviewer` FOREIGN KEY (`reviewer_admin_id`)
        REFERENCES `admin_user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: vulnerable projects with verification_status='not_required'
-- get bumped to 'pending' so they surface in the admin review queue.
-- The state engine then naturally holds them in planning past start_at
-- until an admin reviews + verifies.
UPDATE `impact_project`
SET `verification_status` = 'pending'
WHERE `involves_minors_or_vulnerable` = 1
  AND `verification_status` = 'not_required';
