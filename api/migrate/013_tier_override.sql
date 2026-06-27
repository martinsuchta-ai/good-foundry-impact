-- 013_tier_override.sql — admin override for tier-mandated go-live thresholds.
--
-- Brief §4 / §5: planning -> execution is GATED by tier minimums (different
-- supporter / pledge counts per scale: micro / mid / macro / borderless).
-- A sponsor with a special case (corporate launch with a backed cohort, a
-- private placement, etc.) can request an admin override. When override is
-- set, the threshold gate is bypassed for THIS project — the safeguarding
-- gate (§8) still applies.
--
-- The tier minimums themselves are CODE constants in
-- api/impacts_tier_thresholds.php — they don't need to be in SQL because
-- they're policy not data, and we want every code path to compute them
-- the same way at every layer (state engine, admin UI, public progress
-- meter). Only the per-project override lives here.

ALTER TABLE `impact_project`
    ADD COLUMN `tier_override_reason` TEXT NULL AFTER `verification_status`,
    ADD COLUMN `tier_override_by`     INT UNSIGNED NULL AFTER `tier_override_reason`,
    ADD COLUMN `tier_override_at`     DATETIME NULL AFTER `tier_override_by`;
