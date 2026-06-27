<?php
/**
 * api/admin/safeguarding_queue.php — pending verification work for admins.
 *
 * Brief §8: surfaces every project that needs admin attention on the
 * safeguarding front. Two slices:
 *
 *   1. queue   — vulnerable projects sitting at pending or rejected.
 *                These are the actionable ones.
 *   2. blocked — vulnerable projects whose start_at is past but who
 *                are stuck in planning because verification is missing.
 *                The state engine emits these in its
 *                blocked_by_verification report; this endpoint surfaces
 *                them for the admin dashboard alongside the queue.
 *
 * Also includes the project's safeguarding_record history so the
 * reviewer can see prior decisions / sponsor responses without a
 * second round-trip.
 *
 * Auth: admin_session.
 *
 *   GET /api/admin/safeguarding_queue.php
 *     [?include_history=1]   — attach last 5 safeguarding_record rows per project
 */

declare(strict_types=1);

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth.php';

header('Cache-Control: no-store');

$pdo   = impacts_db();
$admin = impacts_admin_require($pdo);

$includeHistory = !empty($_GET['include_history']);

try {
    $qStmt = $pdo->prepare("
        SELECT p.`id`, p.`title`, p.`verification_status`, p.`state`,
               p.`start_at`, p.`end_at`,
               s.`display_name` AS sponsor_name, s.`email` AS sponsor_email
        FROM `impact_project` p
        LEFT JOIN `sponsor` s ON s.`id` = p.`sponsor_id`
        WHERE p.`involves_minors_or_vulnerable` = 1
          AND p.`verification_status` IN ('pending', 'rejected')
        ORDER BY
          FIELD(p.`state`, 'planning', 'mission', 'execution', 'done'),
          p.`start_at` IS NULL,
          p.`start_at` ASC
    ");
    $qStmt->execute();
    $queue = $qStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $bStmt = $pdo->prepare("
        SELECT p.`id`, p.`title`, p.`start_at`, p.`verification_status`,
               TIMESTAMPDIFF(DAY, p.`start_at`, UTC_TIMESTAMP()) AS days_overdue
        FROM `impact_project` p
        WHERE p.`state` = 'planning'
          AND p.`start_at` IS NOT NULL
          AND p.`start_at` <= UTC_TIMESTAMP()
          AND p.`involves_minors_or_vulnerable` = 1
          AND p.`verification_status` != 'verified'
        ORDER BY p.`start_at` ASC
    ");
    $bStmt->execute();
    $blocked = $bStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $historyByProject = [];
    if ($queue) {
        $ids = array_map(function ($r) { return (int) $r['id']; }, $queue);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $hStmt = $pdo->prepare("
            SELECT sr.`id`, sr.`impact_project_id`, sr.`prior_status`, sr.`new_status`,
                   sr.`documents_reviewed`, sr.`notes`, sr.`decided_at`,
                   au.`email` AS reviewer_email
            FROM `safeguarding_record` sr
            LEFT JOIN `admin_user` au ON au.`id` = sr.`reviewer_admin_id`
            WHERE sr.`impact_project_id` IN ($placeholders)
            ORDER BY sr.`decided_at` DESC
        ");
        $hStmt->execute($ids);
        while ($row = $hStmt->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int) $row['impact_project_id'];
            if (!isset($historyByProject[$pid])) $historyByProject[$pid] = [];
            if (count($historyByProject[$pid]) >= 5) continue;
            $historyByProject[$pid][] = $row;
        }
    }

    $queueOut = array_map(function ($r) use ($historyByProject, $includeHistory) {
        $pid = (int) $r['id'];
        $latest = !empty($historyByProject[$pid]) ? $historyByProject[$pid][0] : null;
        $out = [
            'project_id'          => $pid,
            'title'               => (string) $r['title'],
            'sponsor_name'        => $r['sponsor_name'] ?? null,
            'sponsor_email'       => $r['sponsor_email'] ?? null,
            'verification_status' => (string) $r['verification_status'],
            'state'               => (string) $r['state'],
            'start_at'            => $r['start_at'],
            'end_at'              => $r['end_at'],
            'latest_decision'     => $latest,
        ];
        if ($includeHistory) {
            $out['history'] = $historyByProject[$pid] ?? [];
        }
        return $out;
    }, $queue);

    impacts_json(200, [
        'ok'      => true,
        'counts'  => ['queue' => count($queueOut), 'blocked' => count($blocked)],
        'queue'   => $queueOut,
        'blocked' => array_map(function ($r) { return [
            'project_id'          => (int) $r['id'],
            'title'               => (string) $r['title'],
            'start_at'            => $r['start_at'],
            'days_overdue'        => (int) $r['days_overdue'],
            'verification_status' => (string) $r['verification_status'],
        ]; }, $blocked),
    ]);
} catch (Throwable $e) {
    impacts_safe_error($e, 'safeguarding queue failed');
}
