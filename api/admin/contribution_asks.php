<?php
/**
 * api/admin/contribution_asks.php — admin CRUD for contribution_ask rows.
 *
 * Asks belong to a project (FK impact_project_id) and live in one
 * of three lanes (effort / energy / money). Per brief §6 HARD
 * RULE: external_destination_url is ONLY valid for money lane.
 *
 * Lifecycle for the ask itself is just is_active (true = visible
 * to consumers, false = hidden without delete). Per-pledge
 * lifecycle (pledged → confirmed → fulfilled → withdrawn) is
 * managed via api/admin/pledges.php (separate endpoint, separate
 * UI surface).
 *
 * Routes:
 *   GET  ?action=list&project_id=N           — list asks for a project
 *   GET  ?action=get&ask_id=N                — single ask
 *   POST ?action=create                      — body: { project_id, lane, label, description?,
 *                                                      target_quantity?, target_unit?,
 *                                                      external_destination_url?, is_active? }
 *   POST ?action=update&ask_id=N             — body: subset
 *   POST ?action=delete&ask_id=N             — cascades pledges (FK ON DELETE CASCADE)
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
        case 'list':   _asks_list($pdo); break;
        case 'get':    _asks_get($pdo); break;
        case 'create': _asks_create($pdo, $admin); break;
        case 'update': _asks_update($pdo, $admin); break;
        case 'delete': _asks_delete($pdo, $admin); break;
        default:
            impacts_json(400, ['ok' => false, 'error' => 'unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    impacts_safe_error($e, 'admin/asks failed');
}

function _asks_writable(): array
{
    return ['impact_project_id', 'lane', 'label', 'description',
            'target_quantity', 'target_unit', 'external_destination_url', 'is_active'];
}

function _asks_clean(array $body): array
{
    $out = [];
    foreach (_asks_writable() as $f) {
        if (!array_key_exists($f, $body)) continue;
        $v = $body[$f];
        if ($f === 'is_active') $v = $v ? 1 : 0;
        if ($f === 'impact_project_id') $v = (int) $v;
        if ($f === 'target_quantity' && $v !== '' && $v !== null) $v = (float) $v;
        if ($f === 'lane' && !in_array($v, ['effort', 'energy', 'money'], true)) {
            throw new InvalidArgumentException('lane must be effort|energy|money');
        }
        if (is_string($v)) $v = trim($v);
        if ($v === '') $v = null;
        $out[$f] = $v;
    }
    /* Brief §6 HARD RULE: external_destination_url is money-lane only. */
    if (isset($out['lane']) && $out['lane'] !== 'money' && !empty($out['external_destination_url'])) {
        throw new InvalidArgumentException('external_destination_url is only valid on money lane (brief §6)');
    }
    return $out;
}

function _asks_list(PDO $pdo): void
{
    $pid = (int) ($_GET['project_id'] ?? 0);
    if ($pid <= 0) impacts_json(400, ['ok' => false, 'error' => 'project_id required']);

    $stmt = $pdo->prepare("
        SELECT a.*,
               (SELECT COUNT(*) FROM `contribution_pledge` p WHERE p.`contribution_ask_id` = a.`id`) AS pledge_count,
               (SELECT COUNT(*) FROM `contribution_pledge` p WHERE p.`contribution_ask_id` = a.`id` AND p.`status` = 'fulfilled') AS fulfilled_count
        FROM `contribution_ask` a
        WHERE a.`impact_project_id` = ?
        ORDER BY a.`lane`, a.`created_at`
    ");
    $stmt->execute([$pid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    impacts_json(200, ['ok' => true, 'asks' => $rows, 'count' => count($rows)]);
}

function _asks_get(PDO $pdo): void
{
    $aid = (int) ($_GET['ask_id'] ?? 0);
    if ($aid <= 0) impacts_json(400, ['ok' => false, 'error' => 'ask_id required']);
    $stmt = $pdo->prepare("SELECT * FROM `contribution_ask` WHERE `id` = ? LIMIT 1");
    $stmt->execute([$aid]);
    $ask = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ask) impacts_json(404, ['ok' => false, 'error' => 'ask not found']);
    impacts_json(200, ['ok' => true, 'ask' => $ask]);
}

function _asks_create(PDO $pdo, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) impacts_json(400, ['ok' => false, 'error' => 'invalid JSON body']);

    $pid   = (int) ($body['impact_project_id'] ?? 0);
    $lane  = trim((string) ($body['lane'] ?? ''));
    $label = trim((string) ($body['label'] ?? ''));
    if ($pid <= 0 || $lane === '' || $label === '') {
        impacts_json(400, ['ok' => false, 'error' => 'impact_project_id + lane + label required']);
    }

    try {
        $payload = _asks_clean($body);
    } catch (InvalidArgumentException $e) {
        impacts_json(400, ['ok' => false, 'error' => $e->getMessage()]);
    }
    $payload['impact_project_id'] = $pid;
    $payload['lane'] = $lane;
    $payload['label'] = $label;
    if (!isset($payload['is_active'])) $payload['is_active'] = 1;

    $cols = array_keys($payload);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO `contribution_ask` (`" . implode('`, `', $cols) . "`) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute(array_values($payload));
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'fk_ask_project') !== false) {
            impacts_json(409, ['ok' => false, 'error' => 'impact_project_id does not exist']);
        }
        throw $e;
    }
    impacts_json(201, ['ok' => true, 'ask_id' => (int) $pdo->lastInsertId(), 'created_by' => $admin['id']]);
}

function _asks_update(PDO $pdo, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $aid = (int) ($_GET['ask_id'] ?? 0);
    if ($aid <= 0) impacts_json(400, ['ok' => false, 'error' => 'ask_id required']);

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) impacts_json(400, ['ok' => false, 'error' => 'invalid JSON body']);

    try {
        $payload = _asks_clean($body);
    } catch (InvalidArgumentException $e) {
        impacts_json(400, ['ok' => false, 'error' => $e->getMessage()]);
    }
    if (!$payload) impacts_json(400, ['ok' => false, 'error' => 'no writable fields supplied']);

    $sets = []; $vals = [];
    foreach ($payload as $f => $v) {
        $sets[] = "`$f` = ?";
        $vals[] = $v;
    }
    $vals[] = $aid;
    $sql = "UPDATE `contribution_ask` SET " . implode(', ', $sets) . " WHERE `id` = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($vals);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare("SELECT 1 FROM `contribution_ask` WHERE `id` = ? LIMIT 1");
        $check->execute([$aid]);
        if (!$check->fetchColumn()) impacts_json(404, ['ok' => false, 'error' => 'ask not found']);
    }
    impacts_json(200, ['ok' => true, 'ask_id' => $aid, 'updated_by' => $admin['id']]);
}

function _asks_delete(PDO $pdo, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $aid = (int) ($_GET['ask_id'] ?? 0);
    if ($aid <= 0) impacts_json(400, ['ok' => false, 'error' => 'ask_id required']);
    $stmt = $pdo->prepare("DELETE FROM `contribution_ask` WHERE `id` = ?");
    $stmt->execute([$aid]);
    if ($stmt->rowCount() === 0) impacts_json(404, ['ok' => false, 'error' => 'ask not found']);
    impacts_json(200, ['ok' => true, 'ask_id' => $aid, 'deleted_by' => $admin['id']]);
}
