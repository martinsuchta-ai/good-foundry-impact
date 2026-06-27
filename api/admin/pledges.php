<?php
/**
 * api/admin/pledges.php — admin lifecycle management for pledges.
 *
 * Public supporters create pledges (status='pledged') via
 * /api/v1/pledge.php. Admins use THIS endpoint to:
 *   - list pledges (per project, per ask, by status)
 *   - confirm a pledge (admin accepts it)
 *   - mark a pledge fulfilled (admin verifies completion)
 *   - withdraw a pledge (admin or supporter request)
 *
 * Money lane pledges stay at status='pledged' forever per brief §6
 * — GMI never confirms a transfer (we don't custody funds). The
 * pledge record is just an attribution of intent for reporting.
 *
 * Routes:
 *   GET  ?action=list&project_id=N           — list pledges across the project's asks
 *     Optional: &ask_id=N (narrow to one ask)
 *     Optional: &status=pledged|confirmed|fulfilled|withdrawn
 *   GET  ?action=get&pledge_id=N
 *   POST ?action=confirm&pledge_id=N         — pledged -> confirmed
 *   POST ?action=fulfil&pledge_id=N          — confirmed -> fulfilled
 *   POST ?action=withdraw&pledge_id=N        — any -> withdrawn
 */

declare(strict_types=1);

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth.php';

header('Cache-Control: no-store');

$pdo   = impacts_db();
$admin = impacts_admin_require($pdo);
$action = trim((string) ($_GET['action'] ?? ''));
if ($action === '') impacts_json(400, ['ok' => false, 'error' => 'action required']);

try {
    switch ($action) {
        case 'list':     _pledges_list($pdo); break;
        case 'get':      _pledges_get($pdo); break;
        case 'confirm':  _pledges_transition($pdo, $admin, 'pledged',   'confirmed',  'confirmed_at'); break;
        case 'fulfil':   _pledges_transition($pdo, $admin, 'confirmed', 'fulfilled',  'fulfilled_at'); break;
        case 'withdraw': _pledges_transition($pdo, $admin, null,        'withdrawn',  'withdrawn_at'); break;
        default:
            impacts_json(400, ['ok' => false, 'error' => 'unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    impacts_safe_error($e, 'admin/pledges failed');
}

function _pledges_list(PDO $pdo): void
{
    $pid = (int) ($_GET['project_id'] ?? 0);
    if ($pid <= 0) impacts_json(400, ['ok' => false, 'error' => 'project_id required']);

    $where  = 'a.`impact_project_id` = ?';
    $params = [$pid];
    $askId = (int) ($_GET['ask_id'] ?? 0);
    if ($askId > 0) {
        $where .= ' AND p.`contribution_ask_id` = ?';
        $params[] = $askId;
    }
    $status = trim((string) ($_GET['status'] ?? ''));
    if (in_array($status, ['pledged','confirmed','fulfilled','withdrawn'], true)) {
        $where .= ' AND p.`status` = ?';
        $params[] = $status;
    }

    $stmt = $pdo->prepare("
        SELECT p.*, a.`label` AS ask_label, a.`lane` AS ask_lane,
               s.`display_name` AS supporter_name, s.`email` AS supporter_email
        FROM `contribution_pledge` p
        JOIN `contribution_ask` a   ON a.`id` = p.`contribution_ask_id`
        LEFT JOIN `supporter` s     ON s.`id` = p.`supporter_id`
        WHERE $where
        ORDER BY p.`pledged_at` DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    impacts_json(200, ['ok' => true, 'pledges' => $rows, 'count' => count($rows)]);
}

function _pledges_get(PDO $pdo): void
{
    $id = (int) ($_GET['pledge_id'] ?? 0);
    if ($id <= 0) impacts_json(400, ['ok' => false, 'error' => 'pledge_id required']);
    $stmt = $pdo->prepare("
        SELECT p.*, a.`label` AS ask_label, a.`lane` AS ask_lane,
               s.`display_name` AS supporter_name, s.`email` AS supporter_email
        FROM `contribution_pledge` p
        JOIN `contribution_ask` a   ON a.`id` = p.`contribution_ask_id`
        LEFT JOIN `supporter` s     ON s.`id` = p.`supporter_id`
        WHERE p.`id` = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $pledge = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pledge) impacts_json(404, ['ok' => false, 'error' => 'pledge not found']);
    impacts_json(200, ['ok' => true, 'pledge' => $pledge]);
}

/* Generic transition helper. $expectedFrom null means any current
   state may transition (used by withdraw). The UPDATE is conditional
   on the current state when $expectedFrom is set, so concurrent
   confirm vs fulfil races resolve cleanly. */
function _pledges_transition(PDO $pdo, array $admin, ?string $expectedFrom, string $toState, string $tsColumn): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $id = (int) ($_GET['pledge_id'] ?? 0);
    if ($id <= 0) impacts_json(400, ['ok' => false, 'error' => 'pledge_id required']);

    /* Money-lane guard. Brief §6 — pledges on money asks stay at
       'pledged' forever (the redirect is the only thing that
       happened; GMI never confirms a transfer). Reject confirm /
       fulfil on money lane. Withdraw stays allowed for both. */
    if (in_array($toState, ['confirmed', 'fulfilled'], true)) {
        $laneStmt = $pdo->prepare("SELECT `lane` FROM `contribution_pledge` WHERE `id` = ? LIMIT 1");
        $laneStmt->execute([$id]);
        $lane = (string) $laneStmt->fetchColumn();
        if ($lane === 'money') {
            impacts_json(409, [
                'ok'    => false,
                'error' => 'money lane pledges cannot be confirmed or fulfilled (brief §6 — route-only)',
            ]);
        }
        if ($lane === '') impacts_json(404, ['ok' => false, 'error' => 'pledge not found']);
    }

    if ($expectedFrom === null) {
        $sql = "UPDATE `contribution_pledge` SET `status` = ?, `$tsColumn` = UTC_TIMESTAMP() WHERE `id` = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$toState, $id]);
    } else {
        $sql = "UPDATE `contribution_pledge` SET `status` = ?, `$tsColumn` = UTC_TIMESTAMP() WHERE `id` = ? AND `status` = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$toState, $id, $expectedFrom]);
    }

    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare("SELECT `status` FROM `contribution_pledge` WHERE `id` = ? LIMIT 1");
        $check->execute([$id]);
        $current = $check->fetchColumn();
        if (!$current) impacts_json(404, ['ok' => false, 'error' => 'pledge not found']);
        impacts_json(409, ['ok' => false, 'error' => "pledge is in state '$current' — cannot $toState"]);
    }

    impacts_json(200, ['ok' => true, 'pledge_id' => $id, 'to_state' => $toState, 'actor' => $admin['id']]);
}
