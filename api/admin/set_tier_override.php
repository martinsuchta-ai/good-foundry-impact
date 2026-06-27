<?php
/**
 * api/admin/set_tier_override.php — set/clear tier-threshold override.
 *
 * Brief §4 + §5: planning -> execution is gated by tier-mandated
 * minimums. Admin can bypass the gate for a specific project by
 * setting a tier_override_reason; clearing the reason re-applies the
 * gate at next evaluation. Audit lives on the project row
 * (tier_override_reason / _by / _at) AND in project_transition_log
 * once the project flips to execution.
 *
 * Admin auth required.
 *
 *   POST /api/admin/set_tier_override.php
 *     Body: {
 *       "project_id":  123,
 *       "reason":      "..."      // non-empty to set, empty to clear
 *     }
 *
 *   Returns:
 *     200 { "ok": true, "project_id": 123, "tier_override_reason": "..."|null,
 *           "tier_override_by": <admin_id>|null, "tier_override_at": "..."|null,
 *           "evaluation": { tier, thresholds, progress, shortfall, met, override } }
 *     400 on bad payload
 *     401 when admin auth missing
 *     404 when project not found
 */

declare(strict_types=1);

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../impacts_tier_thresholds.php';
require_once __DIR__ . '/auth.php';

header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    impacts_json(405, ['ok' => false, 'error' => 'POST required']);
}

$pdo   = impacts_db();
$admin = impacts_admin_require($pdo);

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    impacts_json(400, ['ok' => false, 'error' => 'invalid JSON body']);
}

$projectId = isset($body['project_id']) ? (int) $body['project_id'] : 0;
$reasonRaw = isset($body['reason']) ? (string) $body['reason'] : '';
$reason    = trim($reasonRaw);

if ($projectId <= 0) {
    impacts_json(400, ['ok' => false, 'error' => 'project_id required']);
}

try {
    $exists = $pdo->prepare("SELECT `id` FROM `impact_project` WHERE `id` = ? LIMIT 1");
    $exists->execute([$projectId]);
    if (!$exists->fetchColumn()) {
        impacts_json(404, ['ok' => false, 'error' => 'project not found']);
    }

    if ($reason === '') {
        /* Clear the override. */
        $upd = $pdo->prepare("
            UPDATE `impact_project`
            SET `tier_override_reason` = NULL,
                `tier_override_by`     = NULL,
                `tier_override_at`     = NULL
            WHERE `id` = ?
        ");
        $upd->execute([$projectId]);
    } else {
        /* Set / replace the override. Cap reason at 2000 chars
           defensively (TEXT column is far larger but we don't want
           an admin pasting a novel). */
        $reasonClipped = mb_substr($reason, 0, 2000);
        $upd = $pdo->prepare("
            UPDATE `impact_project`
            SET `tier_override_reason` = ?,
                `tier_override_by`     = ?,
                `tier_override_at`     = UTC_TIMESTAMP()
            WHERE `id` = ?
        ");
        $upd->execute([$reasonClipped, (int) $admin['id'], $projectId]);
    }

    /* Re-fetch + re-evaluate so the response shows the post-change
       state directly. */
    $fresh = $pdo->prepare("
        SELECT `tier_override_reason`, `tier_override_by`, `tier_override_at`
        FROM `impact_project` WHERE `id` = ?
    ");
    $fresh->execute([$projectId]);
    $row = $fresh->fetch(PDO::FETCH_ASSOC) ?: [];
    $eval = impacts_evaluate_thresholds($pdo, $projectId);

    impacts_json(200, [
        'ok'                   => true,
        'project_id'           => $projectId,
        'tier_override_reason' => $row['tier_override_reason'] ?? null,
        'tier_override_by'     => isset($row['tier_override_by']) ? (int) $row['tier_override_by'] : null,
        'tier_override_at'     => $row['tier_override_at'] ?? null,
        'evaluation'           => $eval,
    ]);
} catch (Throwable $e) {
    impacts_safe_error($e, 'set_tier_override failed');
}
