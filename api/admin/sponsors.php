<?php
/**
 * api/admin/sponsors.php — admin CRUD for the sponsor table.
 *
 * Phase 1b — without this endpoint admins have to INSERT sponsor
 * rows via SQL before they can pick one in the project create
 * form. Mirrors the same shape as projects.php (list/get/create/
 * update/delete actions).
 *
 * Auth: admin_session cookie via impacts_admin_require.
 *
 * Routes:
 *   GET  ?action=list                       — list every sponsor + project count
 *   GET  ?action=get&sponsor_id=N           — single sponsor + project count
 *   POST ?action=create                     — body: { display_name, email?, org_name?, verified?, notes? }
 *   POST ?action=update&sponsor_id=N        — body: any subset of writable fields
 *   POST ?action=delete&sponsor_id=N        — hard delete. impact_project.sponsor_id is ON DELETE SET NULL
 *                                              so projects survive; they're just unassigned.
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
        case 'list':   _sponsors_list($pdo); break;
        case 'get':    _sponsors_get($pdo); break;
        case 'create': _sponsors_create($pdo, $admin); break;
        case 'update': _sponsors_update($pdo, $admin); break;
        case 'delete': _sponsors_delete($pdo, $admin); break;
        default:
            impacts_json(400, ['ok' => false, 'error' => 'unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    impacts_safe_error($e, 'admin/sponsors failed');
}

function _sponsors_writable(): array
{
    return ['display_name', 'email', 'org_name', 'verified', 'notes'];
}

function _sponsors_clean(array $body): array
{
    $out = [];
    foreach (_sponsors_writable() as $f) {
        if (!array_key_exists($f, $body)) continue;
        $v = $body[$f];
        if ($f === 'verified') $v = $v ? 1 : 0;
        if (is_string($v)) $v = trim($v);
        if ($v === '') $v = null;
        $out[$f] = $v;
    }
    return $out;
}

function _sponsors_list(PDO $pdo): void
{
    $sql = "
        SELECT s.*,
               (SELECT COUNT(*) FROM `impact_project` p WHERE p.`sponsor_id` = s.`id`) AS project_count
        FROM `sponsor` s
        ORDER BY s.`display_name` ASC
        LIMIT 500
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    impacts_json(200, ['ok' => true, 'sponsors' => $rows, 'count' => count($rows)]);
}

function _sponsors_get(PDO $pdo): void
{
    $sid = (int) ($_GET['sponsor_id'] ?? 0);
    if ($sid <= 0) impacts_json(400, ['ok' => false, 'error' => 'sponsor_id required']);
    $stmt = $pdo->prepare("SELECT * FROM `sponsor` WHERE `id` = ? LIMIT 1");
    $stmt->execute([$sid]);
    $sponsor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sponsor) impacts_json(404, ['ok' => false, 'error' => 'sponsor not found']);

    $pc = $pdo->prepare("SELECT COUNT(*) FROM `impact_project` WHERE `sponsor_id` = ?");
    $pc->execute([$sid]);
    impacts_json(200, [
        'ok'            => true,
        'sponsor'       => $sponsor,
        'project_count' => (int) $pc->fetchColumn(),
    ]);
}

function _sponsors_create(PDO $pdo, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) impacts_json(400, ['ok' => false, 'error' => 'invalid JSON body']);

    $name = trim((string) ($body['display_name'] ?? ''));
    if ($name === '') impacts_json(400, ['ok' => false, 'error' => 'display_name required']);

    $payload = _sponsors_clean($body);
    $payload['display_name'] = $name;

    $cols = array_keys($payload);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO `sponsor` (`" . implode('`, `', $cols) . "`) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($payload));
    impacts_json(201, [
        'ok'         => true,
        'sponsor_id' => (int) $pdo->lastInsertId(),
        'created_by' => $admin['id'],
    ]);
}

function _sponsors_update(PDO $pdo, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $sid = (int) ($_GET['sponsor_id'] ?? 0);
    if ($sid <= 0) impacts_json(400, ['ok' => false, 'error' => 'sponsor_id required']);

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) impacts_json(400, ['ok' => false, 'error' => 'invalid JSON body']);

    $payload = _sponsors_clean($body);
    if (!$payload) impacts_json(400, ['ok' => false, 'error' => 'no writable fields supplied']);

    $sets = []; $vals = [];
    foreach ($payload as $f => $v) {
        $sets[] = "`$f` = ?";
        $vals[] = $v;
    }
    $vals[] = $sid;
    $sql = "UPDATE `sponsor` SET " . implode(', ', $sets) . " WHERE `id` = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($vals);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare("SELECT 1 FROM `sponsor` WHERE `id` = ? LIMIT 1");
        $check->execute([$sid]);
        if (!$check->fetchColumn()) impacts_json(404, ['ok' => false, 'error' => 'sponsor not found']);
    }
    impacts_json(200, ['ok' => true, 'sponsor_id' => $sid, 'updated_by' => $admin['id']]);
}

function _sponsors_delete(PDO $pdo, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $sid = (int) ($_GET['sponsor_id'] ?? 0);
    if ($sid <= 0) impacts_json(400, ['ok' => false, 'error' => 'sponsor_id required']);

    /* ON DELETE SET NULL on impact_project.sponsor_id means projects
       survive — they just lose their sponsor reference. The drawer
       should warn the admin if project_count > 0 before calling
       this. */
    $stmt = $pdo->prepare("DELETE FROM `sponsor` WHERE `id` = ?");
    $stmt->execute([$sid]);
    if ($stmt->rowCount() === 0) impacts_json(404, ['ok' => false, 'error' => 'sponsor not found']);
    impacts_json(200, ['ok' => true, 'sponsor_id' => $sid, 'deleted_by' => $admin['id']]);
}
