<?php
/**
 * api/admin/consumers.php — admin CRUD for the consumer table.
 *
 * Consumers are the sites embedding the Impacts widgets — WBM is
 * #1. Each gets an api_key (auto-generated, never admin-typed) +
 * a widget_origins JSON array for CORS.
 *
 * api_key handling: NEVER let the admin set the key on create or
 * update. Always server-generated via bin2hex(random_bytes(32)).
 * The Reveal endpoint (action=reveal_key) returns the full key
 * once — list view returns the first 8 chars only so the panel
 * doesn't leak it to anyone walking past the admin's screen.
 *
 * Routes:
 *   GET  ?action=list                       — list every consumer, api_key masked
 *   GET  ?action=get&consumer_id=N          — single consumer, api_key masked
 *   GET  ?action=reveal_key&consumer_id=N   — returns the full api_key
 *   POST ?action=create                     — body: { name, slug, widget_origins[], notes?, is_active? }
 *                                              api_key auto-generated on insert
 *   POST ?action=rotate_key&consumer_id=N   — generate + return a fresh api_key
 *   POST ?action=update&consumer_id=N       — body: any subset of writable fields (NOT api_key)
 *   POST ?action=delete&consumer_id=N       — placement rows cascade DELETE per FK
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
        case 'list':       _cons_list($pdo); break;
        case 'get':        _cons_get($pdo); break;
        case 'reveal_key': _cons_reveal_key($pdo); break;
        case 'create':     _cons_create($pdo, $admin); break;
        case 'rotate_key': _cons_rotate_key($pdo, $admin); break;
        case 'update':     _cons_update($pdo, $admin); break;
        case 'delete':     _cons_delete($pdo, $admin); break;
        default:
            impacts_json(400, ['ok' => false, 'error' => 'unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    impacts_safe_error($e, 'admin/consumers failed');
}

function _cons_mask_key(?string $key): string
{
    if (!$key) return '';
    /* First 8 chars + ellipsis. Enough to disambiguate visually
       without exposing usable credential material. */
    return substr($key, 0, 8) . '…';
}

function _cons_writable(): array
{
    /* api_key intentionally omitted — always server-generated. */
    return ['name', 'slug', 'widget_origins', 'is_active', 'notes'];
}

function _cons_clean(array $body): array
{
    $out = [];
    foreach (_cons_writable() as $f) {
        if (!array_key_exists($f, $body)) continue;
        $v = $body[$f];
        if ($f === 'is_active') $v = $v ? 1 : 0;
        if ($f === 'widget_origins') {
            /* Accept array, JSON string, or comma-separated list. */
            if (is_array($v)) {
                $v = json_encode(array_values(array_filter(array_map('trim', $v))));
            } elseif (is_string($v)) {
                $decoded = json_decode($v, true);
                if (is_array($decoded)) {
                    $v = json_encode(array_values(array_filter(array_map('trim', $decoded))));
                } else {
                    /* comma split */
                    $parts = array_values(array_filter(array_map('trim', explode(',', $v))));
                    $v = json_encode($parts);
                }
            }
        }
        if (is_string($v)) $v = trim($v);
        $out[$f] = $v;
    }
    return $out;
}

/* ─── handlers ────────────────────────────────────────────── */

function _cons_list(PDO $pdo): void
{
    $sql = "
        SELECT c.*,
               (SELECT COUNT(*) FROM `placement` p WHERE p.`consumer_id` = c.`id`) AS placement_count
        FROM `consumer` c
        ORDER BY c.`name` ASC
        LIMIT 500
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    /* Mask api_key in the list. Reveal endpoint returns the full
       value on explicit admin click. */
    $masked = array_map(function ($r) {
        $r['api_key_masked'] = _cons_mask_key((string) $r['api_key']);
        unset($r['api_key']);
        return $r;
    }, $rows);
    impacts_json(200, ['ok' => true, 'consumers' => $masked, 'count' => count($masked)]);
}

function _cons_get(PDO $pdo): void
{
    $cid = (int) ($_GET['consumer_id'] ?? 0);
    if ($cid <= 0) impacts_json(400, ['ok' => false, 'error' => 'consumer_id required']);
    $stmt = $pdo->prepare("SELECT * FROM `consumer` WHERE `id` = ? LIMIT 1");
    $stmt->execute([$cid]);
    $consumer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$consumer) impacts_json(404, ['ok' => false, 'error' => 'consumer not found']);
    $consumer['api_key_masked'] = _cons_mask_key((string) $consumer['api_key']);
    unset($consumer['api_key']);
    impacts_json(200, ['ok' => true, 'consumer' => $consumer]);
}

function _cons_reveal_key(PDO $pdo): void
{
    $cid = (int) ($_GET['consumer_id'] ?? 0);
    if ($cid <= 0) impacts_json(400, ['ok' => false, 'error' => 'consumer_id required']);
    $stmt = $pdo->prepare("SELECT `api_key` FROM `consumer` WHERE `id` = ? LIMIT 1");
    $stmt->execute([$cid]);
    $key = $stmt->fetchColumn();
    if (!$key) impacts_json(404, ['ok' => false, 'error' => 'consumer not found']);
    impacts_json(200, ['ok' => true, 'api_key' => $key]);
}

function _cons_create(PDO $pdo, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) impacts_json(400, ['ok' => false, 'error' => 'invalid JSON body']);

    $name = trim((string) ($body['name'] ?? ''));
    $slug = trim((string) ($body['slug'] ?? ''));
    if ($name === '' || $slug === '') {
        impacts_json(400, ['ok' => false, 'error' => 'name + slug required']);
    }

    $payload = _cons_clean($body);
    $payload['name'] = $name;
    $payload['slug'] = strtolower(preg_replace('/[^a-z0-9-]+/i', '-', $slug));
    $payload['api_key'] = bin2hex(random_bytes(32));
    if (!isset($payload['is_active'])) $payload['is_active'] = 1;
    if (!isset($payload['widget_origins'])) $payload['widget_origins'] = json_encode([]);

    $cols = array_keys($payload);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO `consumer` (`" . implode('`, `', $cols) . "`) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute(array_values($payload));
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'uq_consumer_slug') !== false) {
            impacts_json(409, ['ok' => false, 'error' => 'slug already in use']);
        }
        throw $e;
    }
    impacts_json(201, [
        'ok'           => true,
        'consumer_id'  => (int) $pdo->lastInsertId(),
        'api_key'      => $payload['api_key'],  /* returned ONCE on create */
        'created_by'   => $admin['id'],
    ]);
}

function _cons_rotate_key(PDO $pdo, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $cid = (int) ($_GET['consumer_id'] ?? 0);
    if ($cid <= 0) impacts_json(400, ['ok' => false, 'error' => 'consumer_id required']);
    $newKey = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare("UPDATE `consumer` SET `api_key` = ? WHERE `id` = ?");
    $stmt->execute([$newKey, $cid]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare("SELECT 1 FROM `consumer` WHERE `id` = ? LIMIT 1");
        $check->execute([$cid]);
        if (!$check->fetchColumn()) impacts_json(404, ['ok' => false, 'error' => 'consumer not found']);
    }
    impacts_json(200, ['ok' => true, 'consumer_id' => $cid, 'api_key' => $newKey, 'rotated_by' => $admin['id']]);
}

function _cons_update(PDO $pdo, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $cid = (int) ($_GET['consumer_id'] ?? 0);
    if ($cid <= 0) impacts_json(400, ['ok' => false, 'error' => 'consumer_id required']);

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) impacts_json(400, ['ok' => false, 'error' => 'invalid JSON body']);

    $payload = _cons_clean($body);
    if (isset($payload['slug'])) {
        $payload['slug'] = strtolower(preg_replace('/[^a-z0-9-]+/i', '-', $payload['slug']));
    }
    if (!$payload) impacts_json(400, ['ok' => false, 'error' => 'no writable fields supplied']);

    $sets = []; $vals = [];
    foreach ($payload as $f => $v) {
        $sets[] = "`$f` = ?";
        $vals[] = $v;
    }
    $vals[] = $cid;
    $sql = "UPDATE `consumer` SET " . implode(', ', $sets) . " WHERE `id` = ?";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute($vals);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'uq_consumer_slug') !== false) {
            impacts_json(409, ['ok' => false, 'error' => 'slug already in use']);
        }
        throw $e;
    }
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare("SELECT 1 FROM `consumer` WHERE `id` = ? LIMIT 1");
        $check->execute([$cid]);
        if (!$check->fetchColumn()) impacts_json(404, ['ok' => false, 'error' => 'consumer not found']);
    }
    impacts_json(200, ['ok' => true, 'consumer_id' => $cid, 'updated_by' => $admin['id']]);
}

function _cons_delete(PDO $pdo, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $cid = (int) ($_GET['consumer_id'] ?? 0);
    if ($cid <= 0) impacts_json(400, ['ok' => false, 'error' => 'consumer_id required']);
    $stmt = $pdo->prepare("DELETE FROM `consumer` WHERE `id` = ?");
    $stmt->execute([$cid]);
    if ($stmt->rowCount() === 0) impacts_json(404, ['ok' => false, 'error' => 'consumer not found']);
    impacts_json(200, ['ok' => true, 'consumer_id' => $cid, 'deleted_by' => $admin['id']]);
}
