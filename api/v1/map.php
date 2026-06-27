<?php
/**
 * api/v1/map.php — GeoJSON feed of impact projects with locations.
 *
 * Brief §7: "Map endpoint returning aggregated project locations
 * for consumer-side map widget. Server-side precision reduction
 * enforced for vulnerable-people projects."
 *
 * Differences from /v1/projects.php:
 *   - Returns GeoJSON FeatureCollection (industry-standard for map libs)
 *   - Skips projects with location_mode='none' (nothing to plot)
 *   - Same precision-reduction hard-rule for vulnerable people
 *   - Each feature carries enough metadata for a marker popup
 *     (title, state, scale, CTA url) without a second round-trip
 *
 *   GET /api/v1/map.php
 *     Authorization: Bearer <api_key>   OR  ?api_key=<key>
 *     Optional:
 *       ?state=planning|execution|done
 *       ?scale=micro|mid|macro|borderless
 *       ?bbox=swLng,swLat,neLng,neLat   — filter to bounding box
 *
 *   Response:
 *     {
 *       "type": "FeatureCollection",
 *       "ok": true,
 *       "consumer": { id, name, slug },
 *       "count": N,
 *       "features": [
 *         {
 *           "type": "Feature",
 *           "geometry": { "type": "Point", "coordinates": [lng, lat] },
 *           "properties": {
 *             id, title, state, scale,
 *             location_label, location_precision,
 *             cta_url           // /api/v1/go.php URL or null
 *           }
 *         },
 *         ...
 *       ]
 *     }
 */

declare(strict_types=1);

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';

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

    /* Base where — map only renders projects with a location. */
    $where = "p.`state` IN ('planning', 'execution', 'done') AND p.`location_mode` != 'none' AND p.`latitude` IS NOT NULL AND p.`longitude` IS NOT NULL";
    $params = [];

    $stateFilter = trim((string) ($_GET['state'] ?? ''));
    if (in_array($stateFilter, ['planning', 'execution', 'done'], true)) {
        $where .= ' AND p.`state` = ?';
        $params[] = $stateFilter;
    }
    $scaleFilter = trim((string) ($_GET['scale'] ?? ''));
    if (in_array($scaleFilter, ['micro', 'mid', 'macro', 'borderless'], true)) {
        $where .= ' AND p.`scale` = ?';
        $params[] = $scaleFilter;
    }

    /* Bounding box filter. Format: swLng,swLat,neLng,neLat. Filter
       BEFORE precision reduction so a vulnerable project just outside
       the bbox doesn't slip in because its coords got snapped. */
    $bboxParam = trim((string) ($_GET['bbox'] ?? ''));
    if ($bboxParam !== '') {
        $parts = explode(',', $bboxParam);
        if (count($parts) === 4) {
            $swLng = (float) $parts[0]; $swLat = (float) $parts[1];
            $neLng = (float) $parts[2]; $neLat = (float) $parts[3];
            if ($swLng < $neLng && $swLat < $neLat) {
                $where .= ' AND p.`longitude` BETWEEN ? AND ? AND p.`latitude` BETWEEN ? AND ?';
                $params[] = $swLng;
                $params[] = $neLng;
                $params[] = $swLat;
                $params[] = $neLat;
            }
        }
    }

    $sql = "
        SELECT `id`, `title`, `scale`, `state`,
               `latitude`, `longitude`, `location_label`,
               `location_precision`, `involves_minors_or_vulnerable`
        FROM `impact_project` p
        WHERE $where
        ORDER BY `updated_at` DESC
        LIMIT 500
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /* Same primary_ask batch as v1/projects — reused so popup CTAs
       route through /v1/go.php for click attribution. */
    $primaryAskByProject = [];
    if ($rows) {
        $ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $askStmt = $pdo->prepare("
            SELECT a.`impact_project_id`, MIN(a.`id`) AS primary_ask_id
            FROM `contribution_ask` a
            WHERE a.`impact_project_id` IN ($placeholders)
              AND a.`is_active` = 1
              AND a.`lane` = 'money'
              AND a.`external_destination_url` IS NOT NULL
              AND a.`external_destination_url` != ''
            GROUP BY a.`impact_project_id`
        ");
        $askStmt->execute($ids);
        while ($ar = $askStmt->fetch(PDO::FETCH_ASSOC)) {
            $primaryAskByProject[(int) $ar['impact_project_id']] = (int) $ar['primary_ask_id'];
        }
    }

    $features = [];
    foreach ($rows as $r) {
        $isVuln = ((int) $r['involves_minors_or_vulnerable']) === 1;
        $precision = $r['location_precision'] ?: 'suburb';
        if ($isVuln && $precision === 'exact') $precision = 'suburb';

        $lat = (float) $r['latitude'];
        $lng = (float) $r['longitude'];
        switch ($precision) {
            case 'exact':   /* leave as stored */ break;
            case 'suburb':  $lat = round($lat, 3); $lng = round($lng, 3); break;
            case 'region':  $lat = round($lat, 1); $lng = round($lng, 1); break;
            case 'country': $lat = round($lat, 0); $lng = round($lng, 0); break;
        }

        $askId = $primaryAskByProject[(int) $r['id']] ?? null;
        $ctaUrl = $askId
            ? '/api/v1/go.php?ask=' . $askId . '&api_key=' . urlencode($apiKey)
            : null;

        $features[] = [
            'type'     => 'Feature',
            'geometry' => [
                'type'        => 'Point',
                /* GeoJSON convention: [lng, lat] order. */
                'coordinates' => [$lng, $lat],
            ],
            'properties' => [
                'id'                 => (int) $r['id'],
                'title'              => (string) $r['title'],
                'state'              => (string) $r['state'],
                'scale'              => (string) $r['scale'],
                'location_label'     => $r['location_label'],
                'location_precision' => $precision,
                'cta_url'            => $ctaUrl,
            ],
        ];
    }

    impacts_json(200, [
        'type'     => 'FeatureCollection',
        'ok'       => true,
        'consumer' => [
            'id'   => (string) $consumer['id'],
            'name' => (string) $consumer['name'],
            'slug' => (string) $consumer['slug'],
        ],
        'count'    => count($features),
        'features' => $features,
    ]);
} catch (Throwable $e) {
    impacts_safe_error($e, 'map feed failed');
}
