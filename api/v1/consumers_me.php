<?php
/**
 * api/v1/consumers_me.php — smoke-test endpoint for Phase 0.
 *
 * Returns the consumer record for the api_key on the request. Used
 * by consuming sites (WBM first) to verify their api_key is valid
 * + their CORS origin is allowed. No project data yet — Phase 1
 * adds /v1/projects, /v1/map, /v1/go/<token>.
 *
 *   GET /api/v1/consumers_me.php?api_key=<key>
 *     OR
 *   GET /api/v1/consumers_me.php          (Authorization: Bearer <key>)
 *
 * Response shape:
 *   {
 *     "ok": true,
 *     "consumer": {
 *       "id": "...",
 *       "name": "...",
 *       "slug": "...",
 *       "widget_origins": ["https://..."]
 *     }
 *   }
 */

declare(strict_types=1);

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';

impacts_send_cors_origin();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    /* CORS preflight — Allow-Origin already set by impacts_send_cors_origin
       when the api_key + origin both match. */
    http_response_code(204);
    exit;
}

$apiKey = impacts_extract_api_key();
if ($apiKey === '') {
    impacts_json(401, ['ok' => false, 'error' => 'api_key required (Authorization: Bearer <key> or ?api_key=<key>)']);
}

try {
    $pdo = impacts_db();
    $stmt = $pdo->prepare("
        SELECT `id`, `name`, `slug`, `widget_origins`, `is_active`
        FROM `consumer`
        WHERE `api_key` = ?
        LIMIT 1
    ");
    $stmt->execute([$apiKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int) $row['is_active'] !== 1) {
        impacts_json(401, ['ok' => false, 'error' => 'invalid or inactive api_key']);
    }

    $widgetOrigins = json_decode((string) ($row['widget_origins'] ?? '[]'), true);
    if (!is_array($widgetOrigins)) $widgetOrigins = [];

    impacts_json(200, [
        'ok'       => true,
        'consumer' => [
            'id'             => (string) $row['id'],
            'name'           => (string) $row['name'],
            'slug'           => (string) $row['slug'],
            'widget_origins' => $widgetOrigins,
        ],
    ]);
} catch (Throwable $e) {
    impacts_safe_error($e, 'lookup failed');
}
