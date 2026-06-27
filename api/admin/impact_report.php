<?php
/**
 * api/admin/impact_report.php — admin cross-consumer impact view.
 *
 * Brief §7. Aggregates clicks + pledges across the platform.
 * Filter to a single consumer / project / window when drilling in.
 *
 * Auth: admin_session.
 *
 *   GET /api/admin/impact_report.php
 *     [?since=YYYY-MM-DD]   defaults to 30 days back
 *     [?until=YYYY-MM-DD]   defaults to today UTC
 *     [?consumer_id=N]
 *     [?project_id=N]
 *
 *   Response:
 *     {
 *       ok: true,
 *       generated_at: "...",
 *       window: { since, until },
 *       scope: { consumer_id, project_id },
 *       clicks:  { total_clicks, by_consumer, by_project, by_day },
 *       pledges: { total_pledges, money/effort/energy, distinct_supporters, by_project },
 *       conversion_pct
 *     }
 */

declare(strict_types=1);

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../impacts_reporting.php';
require_once __DIR__ . '/auth.php';

header('Cache-Control: no-store');

$pdo   = impacts_db();
$admin = impacts_admin_require($pdo);

$consumerId = isset($_GET['consumer_id']) && $_GET['consumer_id'] !== '' ? (int) $_GET['consumer_id'] : null;
$projectId  = isset($_GET['project_id'])  && $_GET['project_id']  !== '' ? (int) $_GET['project_id']  : null;

$win = impacts_parse_window(
    isset($_GET['since']) ? (string) $_GET['since'] : null,
    isset($_GET['until']) ? (string) $_GET['until'] : null
);

try {
    $report = impacts_combined_report($pdo, $win, $consumerId, $projectId);
    impacts_json(200, [
        'ok'           => true,
        'generated_at' => gmdate('Y-m-d H:i:s'),
        'generated_by' => (int) $admin['id'],
    ] + $report);
} catch (Throwable $e) {
    impacts_safe_error($e, 'impact report failed');
}
