<?php
/**
 * api/v1/report.php — consumer self-report.
 *
 * Public-facing endpoint. Consumers authenticate via api_key and
 * receive their OWN attribution stats — clicks they drove, projects
 * they pointed supporters at, total supporter touch.
 *
 * Pledges are reported at the project level (across all attribution)
 * because pledges aren't yet attributed per-consumer — the consumer
 * sees "you drove N clicks; the project received M pledges". Per-
 * consumer pledge attribution lands in Phase 2.
 *
 *   GET /api/v1/report.php
 *     Authorization: Bearer <api_key>   OR  ?api_key=<key>
 *     [?since=YYYY-MM-DD]   defaults 30 days back
 *     [?until=YYYY-MM-DD]   defaults today UTC
 *     [?project_id=N]       optional project-scoped slice
 *
 *   Response:
 *     {
 *       ok: true,
 *       generated_at: "...",
 *       consumer: { id, name, slug },
 *       window:   { since, until },
 *       clicks:   { total_clicks, by_project, by_day },
 *       pledges:  { project-level totals across attribution },
 *       conversion_pct
 *     }
 */

declare(strict_types=1);

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../impacts_reporting.php';

impacts_send_cors_origin();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$apiKey = impacts_extract_api_key();
if ($apiKey === '') {
    impacts_json(401, ['ok' => false, 'error' => 'api_key required']);
}

try {
    $pdo = impacts_db();

    $cStmt = $pdo->prepare("
        SELECT `id`, `name`, `slug`
        FROM `consumer`
        WHERE `api_key` = ? AND `is_active` = 1
        LIMIT 1
    ");
    $cStmt->execute([$apiKey]);
    $consumer = $cStmt->fetch(PDO::FETCH_ASSOC);
    if (!$consumer) impacts_json(401, ['ok' => false, 'error' => 'invalid or inactive api_key']);

    $consumerId = (int) $consumer['id'];
    $projectId  = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int) $_GET['project_id'] : null;

    $win = impacts_parse_window(
        isset($_GET['since']) ? (string) $_GET['since'] : null,
        isset($_GET['until']) ? (string) $_GET['until'] : null
    );

    $report = impacts_combined_report($pdo, $win, $consumerId, $projectId);

    /* The by_consumer slice is meaningless for a single-consumer
       report — drop it so the response doesn't carry "your consumer
       id appears in this row" noise. */
    unset($report['clicks']['by_consumer']);

    impacts_json(200, [
        'ok'           => true,
        'generated_at' => gmdate('Y-m-d H:i:s'),
        'consumer'     => [
            'id'   => (string) $consumer['id'],
            'name' => (string) $consumer['name'],
            'slug' => (string) $consumer['slug'],
        ],
    ] + $report);
} catch (Throwable $e) {
    impacts_safe_error($e, 'consumer report failed');
}
