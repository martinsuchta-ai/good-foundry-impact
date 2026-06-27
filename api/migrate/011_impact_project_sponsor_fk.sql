-- 011_impact_project_sponsor_fk.sql — convert sponsor_id from
-- placeholder VARCHAR to a real FK against the sponsor table.
--
-- Migration 005 created impact_project.sponsor_id as VARCHAR(64)
-- NULL with the explicit comment that it was a placeholder until
-- the sponsor table arrived in Phase 1b. Now that sponsor exists
-- (migration 007), tighten the column to INT UNSIGNED + FK.
--
-- No data migration step needed: Phase 0 + 1a shipped without any
-- production sponsor data (admin seeding is via SQL only, and no
-- test rows exist yet). If any rows DID exist with VARCHAR sponsor
-- IDs, this migration would need a UPDATE … = NULL pass first.

ALTER TABLE `impact_project`
    DROP KEY `ix_impact_project_sponsor_id`;

ALTER TABLE `impact_project`
    MODIFY COLUMN `sponsor_id` INT UNSIGNED NULL;

ALTER TABLE `impact_project`
    ADD KEY `ix_impact_project_sponsor_id` (`sponsor_id`),
    ADD CONSTRAINT `fk_impact_project_sponsor` FOREIGN KEY (`sponsor_id`)
        REFERENCES `sponsor` (`id`) ON DELETE SET NULL;
