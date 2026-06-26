<?php
/**
 * impacts_state_engine.php — the SAS state machine for impact_project.
 *
 * Brief §4 defines the lifecycle:
 *
 *     mission   → planning   → execution   → done
 *
 *     mission → planning :   GATED (manual). Sponsor must pass
 *                            moderation/verification for project tier
 *                            (§8 safeguarding for minors-involved projects).
 *                            Phase 1c will wire this; Phase 1a leaves the
 *                            gate as `impacts_transition_to_planning` —
 *                            admin-only, no auto path.
 *     planning → execution : AUTO at start_at. Tier-mandated minimums
 *                            must be met (or sponsor override). The
 *                            scheduler fires this transition.
 *     execution → done :     AUTO at end_at. Sponsor can also close
 *                            early (sponsor_close_early transition type).
 *
 * Reconciliation: the SQL queries below match on `<= NOW()` not `= NOW()`,
 * so a project whose start_at was an hour ago still gets transitioned
 * by the next scheduler run. We never silently skip a missed window.
 * Every flip writes to `project_transition_log` with `scheduled_for`
 * set to the start_at/end_at the transition was due against — that's
 * how reconciliation knows what was actually late.
 *
 * Brief §8 hard gate (Phase 1a stub — full Phase 1c):
 *   When involves_minors_or_vulnerable=1 AND verification_status != 'verified',
 *   the project CANNOT enter planning OR execution. The auto-scheduler's
 *   planning→execution query EXCLUDES these projects (verification gate)
 *   so they sit in planning past their start_at, surfacing as
 *   "blocked on verification" in admin dashboards.
 *
 * Usage:
 *   require_once __DIR__ . '/db.php';
 *   require_once __DIR__ . '/impacts_state_engine.php';
 *   $report = impacts_run_scheduler(impacts_db());
 *   // $report = ['planning_to_execution' => [id, id, ...],
 *   //            'execution_to_done' => [id, id, ...],
 *   //            'blocked_by_verification' => [id, id, ...]]
 */

declare(strict_types=1);

require_once __DIR__ . '/impacts_bootstrap.php';

/**
 * Run the scheduler once. Idempotent; safe to call from cron + from
 * a one-shot reconciliation call.
 *
 * @return array{
 *   ok: bool,
 *   ran_at: string,
 *   planning_to_execution: list<int>,
 *   execution_to_done: list<int>,
 *   blocked_by_verification: list<int>,
 *   errors: list<array{project_id:int, error:string}>
 * }
 */
function impacts_run_scheduler(PDO $pdo): array
{
    $ranAt = gmdate('Y-m-d H:i:s');
    $report = [
        'ok'                       => true,
        'ran_at'                   => $ranAt,
        'planning_to_execution'    => [],
        'execution_to_done'        => [],
        'blocked_by_verification'  => [],
        'errors'                   => [],
    ];

    /* ── planning → execution ──────────────────────────────────
       Find every project in `planning` whose start_at is at or
       before now AND that doesn't have a pending verification
       gate. The brief §8 gate is enforced HERE: a project
       flagged involves_minors_or_vulnerable AND not yet verified
       stays in planning past its start_at.

       We capture the blocked ones in a separate list so admin
       dashboards can surface them as "stuck on verification —
       admin needs to clear the safeguarding check". */
    try {
        $blockedStmt = $pdo->prepare("
            SELECT `id`
            FROM `impact_project`
            WHERE `state` = 'planning'
              AND `start_at` IS NOT NULL
              AND `start_at` <= ?
              AND `involves_minors_or_vulnerable` = 1
              AND `verification_status` != 'verified'
        ");
        $blockedStmt->execute([$ranAt]);
        while ($row = $blockedStmt->fetch(PDO::FETCH_ASSOC)) {
            $report['blocked_by_verification'][] = (int) $row['id'];
        }
    } catch (Throwable $e) {
        $report['errors'][] = ['project_id' => 0, 'error' => 'blocked-scan: ' . $e->getMessage()];
    }

    try {
        $duePlanStmt = $pdo->prepare("
            SELECT `id`, `start_at`
            FROM `impact_project`
            WHERE `state` = 'planning'
              AND `start_at` IS NOT NULL
              AND `start_at` <= ?
              AND NOT (
                  `involves_minors_or_vulnerable` = 1
                  AND `verification_status` != 'verified'
              )
        ");
        $duePlanStmt->execute([$ranAt]);
        while ($row = $duePlanStmt->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int) $row['id'];
            try {
                impacts_transition($pdo, $pid, 'planning', 'execution', [
                    'transition_type' => 'auto_scheduler',
                    'scheduled_for'   => (string) $row['start_at'],
                    'reason'          => 'auto flip at start_at',
                ]);
                $report['planning_to_execution'][] = $pid;
            } catch (Throwable $e) {
                $report['errors'][] = ['project_id' => $pid, 'error' => 'p->e: ' . $e->getMessage()];
            }
        }
    } catch (Throwable $e) {
        $report['errors'][] = ['project_id' => 0, 'error' => 'planning-scan: ' . $e->getMessage()];
    }

    /* ── execution → done ──────────────────────────────────────
       Find every project in `execution` whose end_at is at or
       before now. No verification gate here — once execution
       has started, the sponsor's window is up regardless of any
       new safeguarding status changes. */
    try {
        $dueDoneStmt = $pdo->prepare("
            SELECT `id`, `end_at`
            FROM `impact_project`
            WHERE `state` = 'execution'
              AND `end_at` IS NOT NULL
              AND `end_at` <= ?
        ");
        $dueDoneStmt->execute([$ranAt]);
        while ($row = $dueDoneStmt->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int) $row['id'];
            try {
                impacts_transition($pdo, $pid, 'execution', 'done', [
                    'transition_type' => 'auto_scheduler',
                    'scheduled_for'   => (string) $row['end_at'],
                    'reason'          => 'auto flip at end_at',
                ]);
                $report['execution_to_done'][] = $pid;
            } catch (Throwable $e) {
                $report['errors'][] = ['project_id' => $pid, 'error' => 'e->d: ' . $e->getMessage()];
            }
        }
    } catch (Throwable $e) {
        $report['errors'][] = ['project_id' => 0, 'error' => 'execution-scan: ' . $e->getMessage()];
    }

    if (!empty($report['errors'])) $report['ok'] = false;
    return $report;
}

/**
 * Generic transition. Updates impact_project.state AND writes the
 * audit row to project_transition_log in a single transaction. Throws
 * on:
 *   - project not found
 *   - current state doesn't match $expectedFrom (concurrency safety —
 *     two scheduler runs racing to transition the same project will
 *     both pass the SELECT but only one will pass this guard)
 *   - bad target state (not in the allowed enum)
 *
 * @param PDO    $pdo
 * @param int    $projectId
 * @param string $expectedFrom  Current state we believe the project is in
 * @param string $toState       Target state
 * @param array  $opts          {transition_type, reason, triggered_by, scheduled_for}
 */
function impacts_transition(
    PDO $pdo,
    int $projectId,
    string $expectedFrom,
    string $toState,
    array $opts = []
): void {
    $valid = ['mission', 'planning', 'execution', 'done'];
    if (!in_array($expectedFrom, $valid, true)) {
        throw new InvalidArgumentException('impacts_transition: bad expectedFrom: ' . $expectedFrom);
    }
    if (!in_array($toState, $valid, true)) {
        throw new InvalidArgumentException('impacts_transition: bad toState: ' . $toState);
    }

    /* Validate the transition is one we recognise. mission→planning is
       admin-gated (no auto path). planning→execution + execution→done
       are auto + manual. Any other pair is rejected. */
    $allowed = [
        'mission|planning'    => true,
        'planning|execution'  => true,
        'execution|done'      => true,
    ];
    $pair = $expectedFrom . '|' . $toState;
    if (!isset($allowed[$pair])) {
        throw new InvalidArgumentException('impacts_transition: disallowed transition: ' . $pair);
    }

    $type = (string) ($opts['transition_type'] ?? 'auto_scheduler');
    $validType = ['auto_scheduler', 'manual_admin', 'sponsor_close_early', 'reconciliation'];
    if (!in_array($type, $validType, true)) {
        throw new InvalidArgumentException('impacts_transition: bad transition_type: ' . $type);
    }

    $reason         = isset($opts['reason']) ? mb_substr((string) $opts['reason'], 0, 500) : null;
    $triggeredBy    = isset($opts['triggered_by']) && $opts['triggered_by'] !== null
                        ? (int) $opts['triggered_by'] : null;
    $scheduledFor   = isset($opts['scheduled_for']) ? (string) $opts['scheduled_for'] : null;

    $pdo->beginTransaction();
    try {
        /* Conditional UPDATE — only flip if the row is still in
           expectedFrom. rowCount() tells us whether the guard caught
           a concurrency race. */
        $upd = $pdo->prepare("
            UPDATE `impact_project`
            SET `state` = ?
            WHERE `id` = ? AND `state` = ?
        ");
        $upd->execute([$toState, $projectId, $expectedFrom]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            throw new RuntimeException("project $projectId not in state $expectedFrom (concurrency or missing)");
        }

        /* Audit row. */
        $log = $pdo->prepare("
            INSERT INTO `project_transition_log`
                (`project_id`, `from_state`, `to_state`, `transitioned_at`,
                 `transition_type`, `reason`, `triggered_by`, `scheduled_for`)
            VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?)
        ");
        $log->execute([
            $projectId, $expectedFrom, $toState,
            $type, $reason, $triggeredBy, $scheduledFor,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
