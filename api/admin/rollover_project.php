<?php
/**
 * api/admin/rollover_project.php — clone a done project into a fresh mission draft.
 *
 * Brief §4: "Rollover lineage — when a project finishes (state=done)
 * the sponsor often wants to run the same campaign again next period.
 * Rollover creates a new project in state=mission with the same
 * title/scope but a fresh date window, and rolled_over_from points
 * back to the original."
 *
 * Auth: admin_session.
 *
 *   POST /api/admin/rollover_project.php
 *     Body: {
 *       "project_id":   123,           // the DONE project to clone
 *       "start_at":     "2026-09-01 00:00:00",   // required
 *       "end_at":       "2026-12-01 00:00:00",   // required
 *       "title_suffix": " — Q4 2026"   // optional, default ""
 *     }
 *
 *   Returns:
 *     201 { ok, new_project_id, rolled_over_from, state: "mission" }
 *     409 when source project is NOT in state=done
 *
 * The new project starts back at `mission` — the safeguarding gate
 * + tier-threshold gate both re-apply naturally for the next run.
 * Sponsor + admin go through the same approval pathway.
 *
 * Fields copied:
 *   title (+ optional suffix), description, scale, sponsor_id,
 *   success_measure, involves_minors_or_vulnerable, location_*,
 *   verification_status (carried over — once verified always
 *   verified for the same sponsor + same content).
 *
 * Fields NOT copied:
 *   state (always reset to mission), start_at + end_at (caller
 *   supplies fresh window), tier_override_* (clean slate; admin
 *   re-decides per run), created_at + updated_at (defaults fire).
 *
 * Asks and pledges are NOT cloned. The new project starts with no
 * asks; sponsor re-authors them (typical case — asks vary per
 * campaign cycle even when the project shell is the same).
 */

declare(strict_types=1);

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth.php';

header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    impacts_json(405, ['ok' => false, 'error' => 'POST required']);
}

$pdo   = impacts_db();
$admin = impacts_admin_require($pdo);

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    impacts_json(400, ['ok' => false, 'error' => 'invalid JSON body']);
}

$projectId   = isset($body['project_id'])   ? (int) $body['project_id'] : 0;
$startAt     = trim((string) ($body['start_at'] ?? ''));
$endAt       = trim((string) ($body['end_at']   ?? ''));
$titleSuffix = isset($body['title_suffix']) ? (string) $body['title_suffix'] : '';

if ($projectId <= 0) impacts_json(400, ['ok' => false, 'error' => 'project_id required']);
if ($startAt === '') impacts_json(400, ['ok' => false, 'error' => 'start_at required']);
if ($endAt === '')   impacts_json(400, ['ok' => false, 'error' => 'end_at required']);

/* Defensive datetime validation. */
foreach (['start_at' => $startAt, 'end_at' => $endAt] as $name => $val) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$/', $val)) {
        impacts_json(400, ['ok' => false, 'error' => "$name must be YYYY-MM-DD or YYYY-MM-DD HH:MM[:SS]"]);
    }
}

if (strcmp($startAt, $endAt) >= 0) {
    impacts_json(400, ['ok' => false, 'error' => 'end_at must be after start_at']);
}

try {
    $src = $pdo->prepare("
        SELECT `title`, `description`, `scale`, `sponsor_id`,
               `success_measure`, `involves_minors_or_vulnerable`,
               `verification_status`,
               `location_mode`, `latitude`, `longitude`,
               `location_label`, `location_precision`,
               `state`
        FROM `impact_project`
        WHERE `id` = ?
        LIMIT 1
    ");
    $src->execute([$projectId]);
    $r = $src->fetch(PDO::FETCH_ASSOC);
    if (!$r) impacts_json(404, ['ok' => false, 'error' => 'project not found']);

    if ($r['state'] !== 'done') {
        impacts_json(409, [
            'ok'    => false,
            'error' => "source project is in state '" . $r['state'] . "' — rollover requires state=done",
            'from_state' => $r['state'],
        ]);
    }

    $newTitle = (string) $r['title'] . $titleSuffix;

    $ins = $pdo->prepare("
        INSERT INTO `impact_project`
            (`title`, `description`, `scale`, `sponsor_id`,
             `success_measure`, `involves_minors_or_vulnerable`,
             `verification_status`,
             `location_mode`, `latitude`, `longitude`,
             `location_label`, `location_precision`,
             `state`, `start_at`, `end_at`, `rolled_over_from`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'mission', ?, ?, ?)
    ");
    $ins->execute([
        $newTitle,
        $r['description'],
        $r['scale'],
        $r['sponsor_id'],
        $r['success_measure'],
        (int) $r['involves_minors_or_vulnerable'],
        $r['verification_status'],
        $r['location_mode'],
        $r['latitude'],
        $r['longitude'],
        $r['location_label'],
        $r['location_precision'],
        $startAt,
        $endAt,
        $projectId,
    ]);
    $newId = (int) $pdo->lastInsertId();

    impacts_json(201, [
        'ok'                => true,
        'new_project_id'    => $newId,
        'rolled_over_from'  => $projectId,
        'state'             => 'mission',
        'created_by'        => (int) $admin['id'],
        'start_at'          => $startAt,
        'end_at'            => $endAt,
        'title'             => $newTitle,
    ]);
} catch (Throwable $e) {
    impacts_safe_error($e, 'rollover failed');
}
