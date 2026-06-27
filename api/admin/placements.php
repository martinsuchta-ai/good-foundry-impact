<?php
/**
 * api/admin/placements.php — admin CRUD for the placement table.
 *
 * Placements are named slots within a consumer where projects /
 * CTAs render. Each placement is scoped to one consumer
 * (consumer_id FK + UNIQUE per (consumer_id, slug)).
 *
 * Routes:
 *   GET  ?action=list                       — list every placement (joined to consumer name)
 *     Optional: &consumer_id=N
 *   GET  ?action=get&placement_id=N         — single placement
 *   POST ?action=create                     — body: { consumer_id, slug, name, description?, is_active? }
 *   POST ?action=update&placement_id=N      — body: subset
 *   POST ?action=delete&placement_id=N
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
        case 'list':   _plac_list($pdo); break;
        case 'get':    _plac_get($pdo); break;
        case 'create': _plac_create($pdo, $admin); break;
        case 'update': _plac_update($pdo, $admin); break;
        case 'delete': _plac_delete($pdo, $admin); break;
        default:
            impacts_json(400, ['ok' => false, 'error' => 'unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    impacts_safe_error($e, 'admin/placements failed');
}

function _plac_writable(): array
{
    return ['consumer_id', 'slug', 'name', 'description', 'is_active'];
}

function _plac_clean(array $body): array
{
    $out = [];
    foreach (_plac_writable() as $f) {
        if (!array_key_exists($f, $body)) continue;
        $v = $body[$f];
        if ($f === 'is_active') $v = $v ? 1 : 0;
        if ($f === 'consumer_id') $v = (int) $v;
        if (is_string($v)) $v = trim($v);
        $out[$f] = $v;
    }
    return $out;
}

function _plac_list(PDO $pdo): void
{
    $where = '1=1';
    $params = [];
    $cid = (int) ($_GET['consumer_id'] ?? 0);
    if ($cid > 0) {
        $where .= ' AND p.`consumer_id` = ?';
        $params[] = $cid;
    }
    $sql = "
        SELECT p.*, c.`name` AS consumer_name, c.`slug` AS consumer_slug
        FROM `placement` p
        JOIN `consumer` c ON c.`id` = p.`consumer_id`
        WHERE $where
        ORDER BY c.`name`, p.`name`
        LIMIT 500
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    impacts_json(200, ['ok' => true, 'placements' => $rows, 'count' => count($rows)]);
}

function _plac_get(PDO $pdo): void
{
    $pid = (int) ($_GET['placement_id'] ?? 0);
    if ($pid <= 0) impacts_json(400, ['ok' => false, 'error' => 'placement_id required']);
    $stmt = $pdo->prepare("
        SELECT p.*, c.`name` AS consumer_name
        FROM `placement` p
        JOIN `consumer` c ON c.`id` = p.`consumer_id`
        WHERE p.`id` = ? LIMIT 1
    ");
    $stmt->execute([$pid]);
    $placement = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$placement) impacts_json(404, ['ok' => false, 'error' => 'placement not found']);
    impacts_json(200, ['ok' => true, 'placement' => $placement]);
}

function _plac_create(PDO $pdo, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) impacts_json(400, ['ok' => false, 'error' => 'invalid JSON body']);

    $consumerId = (int) ($body['consumer_id'] ?? 0);
    $slug       = trim((string) ($body['slug'] ?? ''));
    $name       = trim((string) ($body['name'] ?? ''));
    if ($consumerId <= 0 || $slug === '' || $name === '') {
        impacts_json(400, ['ok' => false, 'error' => 'consumer_id + slug + name required']);
    }

    $payload = _plac_clean($body);
    $payload['consumer_id'] = $consumerId;
    $payload['slug'] = strtolower(preg_replace('/[^a-z0-9-]+/i', '-', $slug));
    $payload['name'] = $name;
    if (!isset($payload['is_active'])) $payload['is_active'] = 1;

    $cols = array_keys($payload);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO `placement` (`" . implode('`, `', $cols) . "`) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute(array_values($payload));
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'uq_placement_consumer_slug') !== false) {
            impacts_json(409, ['ok' => false, 'error' => 'slug already in use for that consumer']);
        }
        if (strpos($e->getMessage(), 'fk_placement_consumer') !== false) {
            impacts_json(409, ['ok' => false, 'error' => 'consumer_id does not exist']);
        }
        throw $e;
    }
    impacts_json(201, [
        'ok'           => true,
        'placement_id' => (int) $pdo->lastInsertId(),
        'created_by'   => $admin['id'],
    ]);
}

function _plac_update(PDO $pdo, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $pid = (int) ($_GET['placement_id'] ?? 0);
    if ($pid <= 0) impacts_json(400, ['ok' => false, 'error' => 'placement_id required']);

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) impacts_json(400, ['ok' => false, 'error' => 'invalid JSON body']);

    $payload = _plac_clean($body);
    if (isset($payload['slug'])) {
        $payload['slug'] = strtolower(preg_replace('/[^a-z0-9-]+/i', '-', $payload['slug']));
    }
    if (!$payload) impacts_json(400, ['ok' => false, 'error' => 'no writable fields supplied']);

    $sets = []; $vals = [];
    foreach ($payload as $f => $v) {
        $sets[] = "`$f` = ?";
        $vals[] = $v;
    }
    $vals[] = $pid;
    $sql = "UPDATE `placement` SET " . implode(', ', $sets) . " WHERE `id` = ?";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute($vals);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'uq_placement_consumer_slug') !== false) {
            impacts_json(409, ['ok' => false, 'error' => 'slug already in use for that consumer']);
        }
        throw $e;
    }
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare("SELECT 1 FROM `placement` WHERE `id` = ? LIMIT 1");
        $check->execute([$pid]);
        if (!$check->fetchColumn()) impacts_json(404, ['ok' => false, 'error' => 'placement not found']);
    }
    impacts_json(200, ['ok' => true, 'placement_id' => $pid, 'updated_by' => $admin['id']]);
}

function _plac_delete(PDO $pdo, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $pid = (int) ($_GET['placement_id'] ?? 0);
    if ($pid <= 0) impacts_json(400, ['ok' => false, 'error' => 'placement_id required']);
    $stmt = $pdo->prepare("DELETE FROM `placement` WHERE `id` = ?");
    $stmt->execute([$pid]);
    if ($stmt->rowCount() === 0) impacts_json(404, ['ok' => false, 'error' => 'placement not found']);
    impacts_json(200, ['ok' => true, 'placement_id' => $pid, 'deleted_by' => $admin['id']]);
}
