<?php
/**
 * impacts_reporting.php — shared impact aggregation helpers.
 *
 * Brief §7: "Impact reporting — per consumer + per project +
 * per period: clicks, pledges by lane, supporters reached,
 * conversion ratio."
 *
 * Used by:
 *   - api/admin/impact_report.php  — admin cross-consumer view
 *   - api/v1/report.php            — public consumer self-report
 *
 * Aggregation rules:
 *   - clicks: COUNT(click_event) within window
 *   - supporters reached: COUNT DISTINCT supporter_id across
 *     confirmed/fulfilled effort/energy pledges + ANY money pledges
 *   - pledges by lane: money / effort / energy split
 *   - conversion: pledges / clicks (when clicks > 0)
 *
 * All windows interpreted in UTC. Date format YYYY-MM-DD inclusive
 * boundaries (since 00:00:00 to until 23:59:59).
 */

declare(strict_types=1);

/**
 * Parse a since/until range. Defaults to last 30 days when both
 * absent. Either bound can be omitted to mean "no lower/upper bound".
 *
 * @return array{since: ?string, until: ?string}  UTC datetime strings
 */
function impacts_parse_window(?string $sinceIn, ?string $untilIn): array
{
    $clean = function (?string $s, string $time) {
        if ($s === null) return null;
        $s = trim($s);
        if ($s === '') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
        return $s . ' ' . $time;
    };
    $since = $clean($sinceIn, '00:00:00');
    $until = $clean($untilIn, '23:59:59');

    if ($since === null && $until === null) {
        /* Default: last 30 days (inclusive of today UTC). */
        $until = gmdate('Y-m-d') . ' 23:59:59';
        $since = gmdate('Y-m-d', strtotime('-30 days', strtotime(gmdate('Y-m-d')))) . ' 00:00:00';
    }
    return ['since' => $since, 'until' => $until];
}

/**
 * Build the WHERE clause + params for a window applied to a
 * timestamp column. Returns ['', []] when both bounds are NULL.
 */
function _impacts_window_where(string $tsCol, array $win): array
{
    $w = '';
    $p = [];
    if (!empty($win['since'])) {
        $w .= " AND $tsCol >= ?";
        $p[] = $win['since'];
    }
    if (!empty($win['until'])) {
        $w .= " AND $tsCol <= ?";
        $p[] = $win['until'];
    }
    return [$w, $p];
}

/**
 * Aggregate clicks for the window, optionally scoped to one
 * consumer and/or one project. Returns:
 *   {
 *     total_clicks: int,
 *     by_consumer: [{consumer_id, consumer_name, clicks}],
 *     by_project:  [{project_id, project_title, clicks}],
 *     by_day:      [{day, clicks}]
 *   }
 */
function impacts_clicks_summary(
    PDO $pdo,
    array $win,
    ?int $consumerId = null,
    ?int $projectId = null
): array {
    [$wW, $wP] = _impacts_window_where('c.`clicked_at`', $win);
    $extra = '';
    $params = $wP;
    if ($consumerId !== null) { $extra .= ' AND c.`consumer_id` = ?';            $params[] = $consumerId; }
    if ($projectId !== null)  { $extra .= ' AND a.`impact_project_id` = ?';      $params[] = $projectId; }

    /* Total */
    $totalSql = "SELECT COUNT(*) FROM `click_event` c
                 JOIN `contribution_ask` a ON a.`id` = c.`contribution_ask_id`
                 WHERE 1=1 $wW $extra";
    $stmt = $pdo->prepare($totalSql);
    $stmt->execute($params);
    $totalClicks = (int) $stmt->fetchColumn();

    /* By consumer (top 50 across the window) */
    $byConsumerSql = "SELECT c.`consumer_id`, cn.`name` AS consumer_name, COUNT(*) AS clicks
                      FROM `click_event` c
                      JOIN `contribution_ask` a ON a.`id` = c.`contribution_ask_id`
                      LEFT JOIN `consumer` cn ON cn.`id` = c.`consumer_id`
                      WHERE 1=1 $wW $extra
                      GROUP BY c.`consumer_id`, cn.`name`
                      ORDER BY clicks DESC
                      LIMIT 50";
    $stmt = $pdo->prepare($byConsumerSql);
    $stmt->execute($params);
    $byConsumer = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /* By project (top 50) */
    $byProjectSql = "SELECT a.`impact_project_id` AS project_id, p.`title` AS project_title, COUNT(*) AS clicks
                     FROM `click_event` c
                     JOIN `contribution_ask` a ON a.`id` = c.`contribution_ask_id`
                     JOIN `impact_project`   p ON p.`id` = a.`impact_project_id`
                     WHERE 1=1 $wW $extra
                     GROUP BY a.`impact_project_id`, p.`title`
                     ORDER BY clicks DESC
                     LIMIT 50";
    $stmt = $pdo->prepare($byProjectSql);
    $stmt->execute($params);
    $byProject = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /* By day */
    $byDaySql = "SELECT DATE(c.`clicked_at`) AS day, COUNT(*) AS clicks
                 FROM `click_event` c
                 JOIN `contribution_ask` a ON a.`id` = c.`contribution_ask_id`
                 WHERE 1=1 $wW $extra
                 GROUP BY DATE(c.`clicked_at`)
                 ORDER BY day ASC";
    $stmt = $pdo->prepare($byDaySql);
    $stmt->execute($params);
    $byDay = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return [
        'total_clicks' => $totalClicks,
        'by_consumer'  => array_map(function ($r) { return [
            'consumer_id'   => isset($r['consumer_id']) ? (int) $r['consumer_id'] : null,
            'consumer_name' => $r['consumer_name'] ?? '(anonymous)',
            'clicks'        => (int) $r['clicks'],
        ]; }, $byConsumer),
        'by_project'   => array_map(function ($r) { return [
            'project_id'    => (int) $r['project_id'],
            'project_title' => (string) $r['project_title'],
            'clicks'        => (int) $r['clicks'],
        ]; }, $byProject),
        'by_day'       => array_map(function ($r) { return [
            'day'    => (string) $r['day'],
            'clicks' => (int) $r['clicks'],
        ]; }, $byDay),
    ];
}

/**
 * Aggregate pledges + supporters for the window.
 * Returns:
 *   {
 *     total_pledges:int, money_pledges:int, effort_pledges:int,
 *     energy_pledges:int, distinct_supporters:int,
 *     by_project: [{project_id, project_title, pledges, supporters}]
 *   }
 */
function impacts_pledges_summary(
    PDO $pdo,
    array $win,
    ?int $projectId = null
): array {
    [$wW, $wP] = _impacts_window_where('p.`pledged_at`', $win);
    $extra = '';
    $params = $wP;
    if ($projectId !== null) { $extra .= ' AND a.`impact_project_id` = ?'; $params[] = $projectId; }

    /* Counts. Withdrawn excluded uniformly. */
    $totalsSql = "
        SELECT
            SUM(CASE WHEN p.`status` != 'withdrawn' THEN 1 ELSE 0 END) AS total_pledges,
            SUM(CASE WHEN p.`lane` = 'money'  AND p.`status` != 'withdrawn' THEN 1 ELSE 0 END) AS money_pledges,
            SUM(CASE WHEN p.`lane` = 'effort' AND p.`status` IN ('confirmed','fulfilled') THEN 1 ELSE 0 END) AS effort_pledges,
            SUM(CASE WHEN p.`lane` = 'energy' AND p.`status` IN ('confirmed','fulfilled') THEN 1 ELSE 0 END) AS energy_pledges,
            COUNT(DISTINCT CASE WHEN p.`status` != 'withdrawn' THEN p.`supporter_id` END) AS distinct_supporters
        FROM `contribution_pledge` p
        JOIN `contribution_ask` a ON a.`id` = p.`contribution_ask_id`
        WHERE 1=1 $wW $extra";
    $stmt = $pdo->prepare($totalsSql);
    $stmt->execute($params);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    /* By project */
    $byProjSql = "
        SELECT a.`impact_project_id` AS project_id, pj.`title` AS project_title,
               SUM(CASE WHEN p.`status` != 'withdrawn' THEN 1 ELSE 0 END) AS pledges,
               COUNT(DISTINCT CASE WHEN p.`status` != 'withdrawn' THEN p.`supporter_id` END) AS supporters
        FROM `contribution_pledge` p
        JOIN `contribution_ask` a  ON a.`id` = p.`contribution_ask_id`
        JOIN `impact_project`   pj ON pj.`id` = a.`impact_project_id`
        WHERE 1=1 $wW $extra
        GROUP BY a.`impact_project_id`, pj.`title`
        ORDER BY pledges DESC
        LIMIT 50";
    $stmt = $pdo->prepare($byProjSql);
    $stmt->execute($params);
    $byProj = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return [
        'total_pledges'       => (int) ($totals['total_pledges']       ?? 0),
        'money_pledges'       => (int) ($totals['money_pledges']       ?? 0),
        'effort_pledges'      => (int) ($totals['effort_pledges']      ?? 0),
        'energy_pledges'      => (int) ($totals['energy_pledges']      ?? 0),
        'distinct_supporters' => (int) ($totals['distinct_supporters'] ?? 0),
        'by_project'          => array_map(function ($r) { return [
            'project_id'    => (int) $r['project_id'],
            'project_title' => (string) $r['project_title'],
            'pledges'       => (int) $r['pledges'],
            'supporters'    => (int) $r['supporters'],
        ]; }, $byProj),
    ];
}

/**
 * Combined report shape — both clicks + pledges + conversion ratio.
 * The public consumer endpoint and the admin endpoint both call
 * this; admin passes no consumerId restriction, consumer passes
 * its own id.
 */
function impacts_combined_report(
    PDO $pdo,
    array $win,
    ?int $consumerId = null,
    ?int $projectId = null
): array {
    $clicks  = impacts_clicks_summary($pdo, $win, $consumerId, $projectId);
    /* Pledge filter by consumer_id isn't natural — pledges don't carry
       a consumer_id (only click_event does). Reporting "your" pledges
       per consumer requires joining pledges through the SAME ask the
       consumer's clicks were against. For MVP we report the
       project-level pledge totals (across all attribution) so consumers
       see "you drove N clicks, the project received M pledges total".
       Per-consumer pledge attribution lands in a Phase 2 enrichment. */
    $pledges = impacts_pledges_summary($pdo, $win, $projectId);

    $conv = ($clicks['total_clicks'] > 0)
        ? round(($pledges['total_pledges'] / $clicks['total_clicks']) * 100, 2)
        : null;

    return [
        'window'              => $win,
        'scope'               => [
            'consumer_id' => $consumerId,
            'project_id'  => $projectId,
        ],
        'clicks'              => $clicks,
        'pledges'             => $pledges,
        'conversion_pct'      => $conv,  /* pledges-to-clicks; null when no clicks */
    ];
}
