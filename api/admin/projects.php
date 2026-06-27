<?php
/**
 * api/admin/projects.php — admin CRUD for impact_project.
 *
 * Phase 1b — admins can list + create + update projects through the
 * admin UI instead of raw SQL. The brief §4 state machine still
 * applies — new projects land in state='mission' and require
 * an explicit transition through /api/admin/transition_project.php
 * to enter planning / execution.
 *
 * Auth: admin_session cookie. impacts_admin_require enforces.
 *
 * Routes:
 *   GET  ?action=list                       — list every project (paginated)
 *     Optional: &state=mission|planning|...
 *     Optional: &sponsor_id=N
 *     Returns: { ok, projects: [...] }
 *
 *   GET  ?action=get&project_id=N           — single project + sponsor + ask counts
 *
 *   POST ?action=create                     — create a new project (state='mission')
 *     Body: { title, description, scale, sponsor_id?, success_measure?,
 *             start_at?, end_at?, involves_minors_or_vulnerable?,
 *             location_mode?, latitude?, longitude?, location_label?,
 *             location_precision? }
 *
 *   POST ?action=update&project_id=N        — patch any of the above fields
 *     Body: any subset of the create fields. State is NOT mutable here
 *           — use transition_project.php for state changes.
 *
 *   POST ?action=delete&project_id=N        — hard delete. Cascades to
 *     contribution_ask + contribution_pledge + project_transition_log
 *     via the FK ON DELETE CASCADE. Use sparingly — most projects
 *     should be "rolled over" or left in done state.
 */

declare(strict_types=1);

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../impacts_tier_thresholds.php';
require_once __DIR__ . '/auth.php';

header('Cache-Control: no-store');

$pdo   = impacts_db();
$admin = impacts_admin_require($pdo);
$action = trim((string) ($_GET['action'] ?? ''));
if ($action === '') impacts_json(400, ['ok' => false, 'error' => 'action required']);

try {
    switch ($action) {
        case 'list':   _projects_list($pdo); break;
        case 'get':    _projects_get($pdo); break;
        case 'create': _projects_create($pdo, $admin); break;
        case 'update': _projects_update($pdo, $admin); break;
        case 'delete': _projects_delete($pdo, $admin); break;
        default:
            impacts_json(400, ['ok' => false, 'error' => 'unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    impacts_safe_error($e, 'admin/projects failed');
}

/* ─── helpers ─────────────────────────────────────────────── */

/* Whitelist of columns admins can write through this endpoint.
   State + verification_status are MISSING by design — those flow
   through the dedicated transition + safeguarding endpoints. */
function _projects_writable_fields(): array
{
    return [
        'title', 'description', 'scale', 'sponsor_id', 'success_measure',
        'start_at', 'end_at', 'involves_minors_or_vulnerable',
        'location_mode', 'latitude', 'longitude', 'location_label', 'location_precision',
        'rolled_over_from'
    ];
}

function _projects_clean_payload(array $body): array
{
    $allowed = _projects_writable_fields();
    $out = [];
    foreach ($allowed as $f) {
        if (!array_key_exists($f, $body)) continue;
        $v = $body[$f];
        /* Tier values pinned to brief §3 / §4 enums. */
        if ($f === 'scale' && !in_array($v, ['micro', 'mid', 'macro', 'borderless'], true)) {
            throw new InvalidArgumentException('scale must be micro|mid|macro|borderless');
        }
        if ($f === 'location_mode' && !in_array($v, ['point', 'area', 'none'], true)) {
            throw new InvalidArgumentException('location_mode must be point|area|none');
        }
        if ($f === 'location_precision' && !in_array($v, ['exact', 'suburb', 'region', 'country'], true)) {
            throw new InvalidArgumentException('location_precision must be exact|suburb|region|country');
        }
        if ($f === 'involves_minors_or_vulnerable') $v = $v ? 1 : 0;
        if (($f === 'latitude' || $f === 'longitude') && $v !== null && $v !== '') {
            $v = (float) $v;
        }
        if (($f === 'sponsor_id' || $f === 'rolled_over_from') && $v !== null && $v !== '') {
            $v = (int) $v;
        }
        $out[$f] = $v;
    }
    return $out;
}

/* ─── handlers ────────────────────────────────────────────── */

function _projects_list(PDO $pdo): void
{
    $where  = '1=1';
    $params = [];
    $state = trim((string) ($_GET['state'] ?? ''));
    if (in_array($state, ['mission', 'planning', 'execution', 'done'], true)) {
        $where .= ' AND p.`state` = ?';
        $params[] = $state;
    }
    $sponsorId = (int) ($_GET['sponsor_id'] ?? 0);
    if ($sponsorId > 0) {
        $where .= ' AND p.`sponsor_id` = ?';
        $params[] = $sponsorId;
    }

    $sql = "
        SELECT p.*, s.`display_name` AS sponsor_name
        FROM `impact_project` p
        LEFT JOIN `sponsor` s ON s.`id` = p.`sponsor_id`
        WHERE $where
        ORDER BY p.`created_at` DESC
        LIMIT 200
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    impacts_json(200, ['ok' => true, 'projects' => $rows, 'count' => count($rows)]);
}

function _projects_get(PDO $pdo): void
{
    $pid = (int) ($_GET['project_id'] ?? 0);
    if ($pid <= 0) impacts_json(400, ['ok' => false, 'error' => 'project_id required']);

    $stmt = $pdo->prepare("
        SELECT p.*, s.`display_name` AS sponsor_name, s.`email` AS sponsor_email
        FROM `impact_project` p
        LEFT JOIN `sponsor` s ON s.`id` = p.`sponsor_id`
        WHERE p.`id` = ?
        LIMIT 1
    ");
    $stmt->execute([$pid]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$project) impacts_json(404, ['ok' => false, 'error' => 'project not found']);

    /* Counts for the detail surface. */
    $askStmt = $pdo->prepare("SELECT COUNT(*) FROM `contribution_ask` WHERE `impact_project_id` = ?");
    $askStmt->execute([$pid]);
    $askCount = (int) $askStmt->fetchColumn();

    $pledgeStmt = $pdo->prepare("
        SELECT COUNT(*) FROM `contribution_pledge` cp
        JOIN `contribution_ask` ca ON ca.`id` = cp.`contribution_ask_id`
        WHERE ca.`impact_project_id` = ?
    ");
    $pledgeStmt->execute([$pid]);
    $pledgeCount = (int) $pledgeStmt->fetchColumn();

    /* Tier-gate evaluation — admin sees the full eval (including the
       tier_override_reason on the project row). Useful when reviewing
       a project stuck in planning past its start_at. */
    $thresholdsEval = impacts_evaluate_thresholds($pdo, $pid);

    impacts_json(200, [
        'ok'           => true,
        'project'      => $project,
        'ask_count'    => $askCount,
        'pledge_count' => $pledgeCount,
        'thresholds'   => $thresholdsEval,
    ]);
}

function _projects_create(PDO $pdo, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) impacts_json(400, ['ok' => false, 'error' => 'invalid JSON body']);

    $title = trim((string) ($body['title'] ?? ''));
    if ($title === '') impacts_json(400, ['ok' => false, 'error' => 'title required']);

    $payload = _projects_clean_payload($body);
    /* Enforce defaults that the DB doesn't carry (e.g. scale defaults
       to micro, location_mode to none — both already DB defaults). */
    if (!isset($payload['title'])) $payload['title'] = $title;

    $cols = array_keys($payload);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO `impact_project` (`" . implode('`, `', $cols) . "`) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($payload));
    $newId = (int) $pdo->lastInsertId();

    impacts_json(201, [
        'ok'         => true,
        'project_id' => $newId,
        'created_by' => $admin['id'],
        'state'      => 'mission',
    ]);
}

function _projects_update(PDO $pdo, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $pid = (int) ($_GET['project_id'] ?? 0);
    if ($pid <= 0) impacts_json(400, ['ok' => false, 'error' => 'project_id required']);

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) impacts_json(400, ['ok' => false, 'error' => 'invalid JSON body']);

    $payload = _projects_clean_payload($body);
    if (!$payload) impacts_json(400, ['ok' => false, 'error' => 'no writable fields supplied']);

    $sets = [];
    $vals = [];
    foreach ($payload as $f => $v) {
        $sets[] = "`$f` = ?";
        $vals[] = $v;
    }
    $vals[] = $pid;
    $sql = "UPDATE `impact_project` SET " . implode(', ', $sets) . " WHERE `id` = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($vals);

    if ($stmt->rowCount() === 0) {
        /* Could be: project missing, OR fields supplied matched existing
           values exactly. Distinguish with a SELECT. */
        $check = $pdo->prepare("SELECT 1 FROM `impact_project` WHERE `id` = ? LIMIT 1");
        $check->execute([$pid]);
        if (!$check->fetchColumn()) impacts_json(404, ['ok' => false, 'error' => 'project not found']);
    }

    impacts_json(200, ['ok' => true, 'project_id' => $pid, 'updated_by' => $admin['id']]);
}

function _projects_delete(PDO $pdo, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $pid = (int) ($_GET['project_id'] ?? 0);
    if ($pid <= 0) impacts_json(400, ['ok' => false, 'error' => 'project_id required']);

    $stmt = $pdo->prepare("DELETE FROM `impact_project` WHERE `id` = ?");
    $stmt->execute([$pid]);
    if ($stmt->rowCount() === 0) impacts_json(404, ['ok' => false, 'error' => 'project not found']);

    impacts_json(200, ['ok' => true, 'project_id' => $pid, 'deleted_by' => $admin['id']]);
}
