<?php
/**
 * api/admin/transition_project.php — admin-driven state transition.
 *
 * Use cases:
 *   - mission → planning : ONLY path (no auto). Admin approves the
 *                          draft, project enters the time-driven lifecycle.
 *   - planning → execution: manual override (rare — bypasses the
 *                           auto scheduler when admin wants to fire
 *                           the start window early)
 *   - execution → done :    manual override (sponsor-close-early —
 *                           rare; usually the cron handles end_at)
 *
 * Admin auth required.
 *
 *   POST /api/admin/transition_project.php
 *     Body: {
 *       "project_id":  123,
 *       "to_state":    "planning" | "execution" | "done",
 *       "reason":      "...",          // optional, max 500 chars
 *       "type":        "manual_admin" | "sponsor_close_early"   // optional, default manual_admin
 *     }
 *
 *   Returns:
 *     200 { "ok": true, "from_state": "...", "to_state": "...", "transitioned_at": "..." }
 *     400 on bad payload
 *     401 when admin auth missing
 *     409 when project's current state doesn't allow that transition
 */

declare(strict_types=1);

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../impacts_state_engine.php';
require_once __DIR__ . '/../impacts_tier_thresholds.php';
require_once __DIR__ . '/auth.php';

header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    impacts_json(405, ['ok' => false, 'error' => 'POST required']);
}

$pdo = impacts_db();
$admin = impacts_admin_require($pdo);

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    impacts_json(400, ['ok' => false, 'error' => 'invalid JSON body']);
}

$projectId = isset($body['project_id']) ? (int) $body['project_id'] : 0;
$toState   = isset($body['to_state'])   ? (string) $body['to_state']   : '';
$reason    = isset($body['reason'])     ? (string) $body['reason']     : '';
$type      = isset($body['type'])       ? (string) $body['type']       : 'manual_admin';

if ($projectId <= 0)      impacts_json(400, ['ok' => false, 'error' => 'project_id required']);
$validTo = ['planning', 'execution', 'done'];
if (!in_array($toState, $validTo, true)) {
    impacts_json(400, ['ok' => false, 'error' => 'to_state must be one of: ' . implode(', ', $validTo)]);
}

try {
    /* Look up current state. We use the engine's expectedFrom guard
       in impacts_transition() so the actual flip is concurrency-safe;
       this lookup is for surfacing the from_state in the response
       and confirming the project exists. */
    $stmt = $pdo->prepare("
        SELECT `id`, `state`, `involves_minors_or_vulnerable`, `verification_status`,
               `start_at`, `end_at`
        FROM `impact_project`
        WHERE `id` = ?
        LIMIT 1
    ");
    $stmt->execute([$projectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) impacts_json(404, ['ok' => false, 'error' => 'project not found']);

    $fromState = (string) $row['state'];

    /* Validate the transition pair before touching state. */
    $allowedPair = [
        'mission|planning'   => true,
        'planning|execution' => true,
        'execution|done'     => true,
    ];
    if (!isset($allowedPair[$fromState . '|' . $toState])) {
        impacts_json(409, [
            'ok'         => false,
            'error'      => "project is in state '$fromState' — cannot transition to '$toState'",
            'from_state' => $fromState,
        ]);
    }

    /* Safeguarding gate — brief §8. A project flagged as involving
       minors/vulnerable can't enter planning or execution until
       admin-verified. Admin override is allowed via verification_status,
       not via the transition endpoint; surfacing the block here as
       a clear error guides admin to the verification flow. */
    if ($fromState === 'mission' && $toState === 'planning'
        && (int) $row['involves_minors_or_vulnerable'] === 1
        && (string) $row['verification_status'] !== 'verified') {
        impacts_json(409, [
            'ok'    => false,
            'error' => 'project involves minors or vulnerable people — verification_status must be verified before it can enter planning',
            'from_state' => $fromState,
            'verification_status' => $row['verification_status'],
        ]);
    }

    /* Tier-mandated go-live gate — brief §4/§5. Manual planning ->
       execution must clear the tier minimums OR carry an explicit
       tier_override_reason set by the admin via set_tier_override.php.
       Same evaluation the auto-scheduler uses so admin + scheduler
       agree at every tick. */
    if ($fromState === 'planning' && $toState === 'execution') {
        $eval = impacts_evaluate_thresholds($pdo, $projectId);
        if (!$eval['met'] && !$eval['override']) {
            impacts_json(409, [
                'ok'         => false,
                'error'      => 'tier thresholds not met — set tier_override_reason on the project to bypass',
                'from_state' => $fromState,
                'tier'       => $eval['tier'],
                'thresholds' => $eval['thresholds'],
                'progress'   => $eval['progress'],
                'shortfall'  => $eval['shortfall'],
                'hint'       => 'POST /api/admin/set_tier_override.php with {project_id, reason} to bypass',
            ]);
        }
    }

    impacts_transition($pdo, $projectId, $fromState, $toState, [
        'transition_type' => $type === 'sponsor_close_early' ? 'sponsor_close_early' : 'manual_admin',
        'reason'          => $reason !== '' ? $reason : null,
        'triggered_by'    => (int) $admin['id'],
    ]);

    impacts_json(200, [
        'ok'              => true,
        'project_id'      => $projectId,
        'from_state'      => $fromState,
        'to_state'        => $toState,
        'transitioned_at' => gmdate('Y-m-d H:i:s'),
        'triggered_by'    => $admin['id'],
    ]);
} catch (RuntimeException $rex) {
    /* impacts_transition throws RuntimeException on concurrency race
       (row already transitioned by another caller). Return 409 so the
       client can re-fetch + retry. */
    impacts_json(409, ['ok' => false, 'error' => $rex->getMessage()]);
} catch (Throwable $e) {
    impacts_safe_error($e, 'transition failed');
}
