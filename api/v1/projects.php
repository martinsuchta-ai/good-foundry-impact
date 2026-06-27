<?php
/**
 * api/v1/projects.php — public consumer feed of impact projects.
 *
 * Brief §7 delivery layer:
 *   "JSON API — list/browse projects for a consumer+placement,
 *    project detail, open asks; authenticated by consumer key,
 *    CORS-restricted to registered origins."
 *
 * Phase 1b — read-only list. Returns every project visible to the
 * authenticated consumer in states planning / execution / recent
 * done (last 90 days). mission state projects (drafts not yet
 * approved by admin) are NEVER surfaced publicly.
 *
 * Brief §6a — location precision enforced server-side. Exact
 * coordinates for projects flagged involves_minors_or_vulnerable
 * NEVER reach the public response. Snapped to ~suburb (3 decimal
 * places ≈ 110m) on output.
 *
 *   GET /api/v1/projects.php
 *     Authorization: Bearer <api_key>   OR  ?api_key=<key>
 *     Optional:
 *       ?state=planning|execution|done
 *       ?scale=micro|mid|macro|borderless
 *       ?include_done_within_days=N  (default 90)
 *
 *   Response shape:
 *     {
 *       ok: true,
 *       consumer: { id, name, slug },
 *       projects: [
 *         {
 *           id, title, description, scale, state,
 *           start_at, end_at,
 *           location_mode, latitude, longitude, location_label,
 *           location_precision,
 *           involves_minors_or_vulnerable
 *         }, ...
 *       ],
 *       count
 *     }
 */

declare(strict_types=1);

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../impacts_tier_thresholds.php';

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

    /* Resolve the consumer first — any 401 short-circuits the
       projects scan. */
    $cStmt = $pdo->prepare("
        SELECT `id`, `name`, `slug`
        FROM `consumer`
        WHERE `api_key` = ? AND `is_active` = 1
        LIMIT 1
    ");
    $cStmt->execute([$apiKey]);
    $consumer = $cStmt->fetch(PDO::FETCH_ASSOC);
    if (!$consumer) impacts_json(401, ['ok' => false, 'error' => 'invalid or inactive api_key']);

    /* Filter params. */
    $stateFilter = trim((string) ($_GET['state'] ?? ''));
    $scaleFilter = trim((string) ($_GET['scale'] ?? ''));
    $doneDays    = isset($_GET['include_done_within_days'])
                   ? max(0, min(365, (int) $_GET['include_done_within_days']))
                   : 90;

    /* Base where: never publish mission state. */
    $where = "p.`state` IN ('planning', 'execution', 'done')";
    $params = [];

    if (in_array($stateFilter, ['planning', 'execution', 'done'], true)) {
        $where = "p.`state` = ?";
        $params[] = $stateFilter;
    }

    if (in_array($scaleFilter, ['micro', 'mid', 'macro', 'borderless'], true)) {
        $where .= " AND p.`scale` = ?";
        $params[] = $scaleFilter;
    }

    /* Recent done window. Anything done before the cutoff is hidden
       so the feed doesn't fill with ancient completed projects.
       Skipped when state=done is the explicit filter (admin asked
       for the historical view). */
    if ($stateFilter !== 'done' && $doneDays > 0) {
        $where .= " AND (p.`state` != 'done' OR p.`updated_at` >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY))";
        $params[] = $doneDays;
    }

    $sql = "
        SELECT `id`, `title`, `description`, `scale`, `state`,
               `start_at`, `end_at`,
               `location_mode`, `latitude`, `longitude`,
               `location_label`, `location_precision`,
               `involves_minors_or_vulnerable`
        FROM `impact_project` p
        WHERE $where
        ORDER BY `start_at` IS NULL, `start_at` ASC
        LIMIT 100
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /* Brief §6a HARD RULE — precision-reduce coordinates server-side
       BEFORE they reach the client. Projects with vulnerable people
       are hard-clamped to suburb regardless of stored precision. */
    $cleaned = array_map(function (array $r) use ($pdo): array {
        $isVuln = ((int) $r['involves_minors_or_vulnerable']) === 1;
        $precision = $r['location_precision'] ?: 'suburb';
        /* Hard clamp for vulnerable. */
        if ($isVuln && in_array($precision, ['exact'], true)) {
            $precision = 'suburb';
        }
        $lat = $r['latitude'];
        $lng = $r['longitude'];
        if ($lat !== null && $lng !== null) {
            switch ($precision) {
                case 'exact':   /* leave as stored */                                 break;
                case 'suburb':  $lat = round((float) $lat, 3); $lng = round((float) $lng, 3); break;
                case 'region':  $lat = round((float) $lat, 1); $lng = round((float) $lng, 1); break;
                case 'country': $lat = round((float) $lat, 0); $lng = round((float) $lng, 0); break;
            }
        }
        /* Brief §4/§5 go-live progress meter. For projects in
           planning we surface what's needed before execution opens
           so the consumer-side widget can render "this project needs
           N more supporters". Skipped for execution/done (gate is
           moot) to keep the payload light. progress is the public-
           safe subset — no admin override reason exposed. */
        $progress = null;
        if ($r['state'] === 'planning') {
            $eval = impacts_evaluate_thresholds($pdo, (int) $r['id']);
            $progress = [
                'tier'          => $eval['tier'],
                'thresholds'    => $eval['thresholds'],
                'progress'      => $eval['progress'],
                'shortfall'     => $eval['shortfall'],
                'ready_for_go_live' => $eval['met'] || $eval['override'],
            ];
        }

        return [
            'id'                            => (int) $r['id'],
            'title'                         => (string) $r['title'],
            'description'                   => (string) ($r['description'] ?? ''),
            'scale'                         => (string) $r['scale'],
            'state'                         => (string) $r['state'],
            'start_at'                      => $r['start_at'],
            'end_at'                        => $r['end_at'],
            'location_mode'                 => (string) $r['location_mode'],
            'latitude'                      => $lat,
            'longitude'                     => $lng,
            'location_label'                => $r['location_label'],
            'location_precision'            => $precision,
            'involves_minors_or_vulnerable' => $isVuln,
            'go_live_progress'              => $progress,
        ];
    }, $rows);

    impacts_json(200, [
        'ok'       => true,
        'consumer' => [
            'id'   => (string) $consumer['id'],
            'name' => (string) $consumer['name'],
            'slug' => (string) $consumer['slug'],
        ],
        'projects' => $cleaned,
        'count'    => count($cleaned),
    ]);
} catch (Throwable $e) {
    impacts_safe_error($e, 'projects feed failed');
}
