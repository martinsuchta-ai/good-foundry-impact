<?php
/**
 * api/admin/set_verification.php — record a safeguarding verification decision.
 *
 * Brief §8: every change to impact_project.verification_status writes an
 * audit row in safeguarding_record so the chain of review is provable.
 * Use this endpoint INSTEAD of patching verification_status directly
 * through admin/projects.php (which doesn't even allow that field —
 * deliberately, per its writable-fields whitelist).
 *
 * Auth: admin_session.
 *
 *   POST /api/admin/set_verification.php
 *     Body: {
 *       "project_id":          123,
 *       "new_status":          "pending"|"verified"|"rejected"|"not_required",
 *       "documents_reviewed":  "..."          // optional, what evidence was checked
 *       "notes":               "..."          // optional, reviewer comments
 *     }
 *
 *   Returns:
 *     200 { ok, project_id, prior_status, new_status, record_id,
 *           reviewer_admin_id, decided_at }
 *     400 on bad payload
 *     404 when project not found
 *     409 when trying to set verified on a project that no longer
 *         has involves_minors_or_vulnerable=1 (use not_required)
 */

declare(strict_types=1);

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';
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
$newStatus = trim((string) ($body['new_status'] ?? ''));
$docs      = isset($body['documents_reviewed']) ? trim((string) $body['documents_reviewed']) : '';
$notes     = isset($body['notes']) ? trim((string) $body['notes']) : '';

if ($projectId <= 0) impacts_json(400, ['ok' => false, 'error' => 'project_id required']);

$valid = ['not_required', 'pending', 'verified', 'rejected'];
if (!in_array($newStatus, $valid, true)) {
    impacts_json(400, ['ok' => false, 'error' => 'new_status must be one of: ' . implode(', ', $valid)]);
}

/* Reviewer must supply documents_reviewed when setting verified —
   regulators want to see what was actually checked, not just a
   thumbs-up. notes is optional. */
if ($newStatus === 'verified' && $docs === '') {
    impacts_json(400, [
        'ok'    => false,
        'error' => 'documents_reviewed is required when setting verified — describe the evidence reviewed (e.g. "Working with Children Check NN, sponsor police check 2026, child-safety policy v2.1")',
    ]);
}

try {
    $stmt = $pdo->prepare("
        SELECT `id`, `verification_status`, `involves_minors_or_vulnerable`, `title`
        FROM `impact_project`
        WHERE `id` = ?
        LIMIT 1
    ");
    $stmt->execute([$projectId]);
    $proj = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$proj) impacts_json(404, ['ok' => false, 'error' => 'project not found']);

    $priorStatus = (string) $proj['verification_status'];

    /* not_required is reserved for projects without the vulnerable
       flag — block a reviewer from accidentally clearing the gate. */
    if ($newStatus === 'not_required' && (int) $proj['involves_minors_or_vulnerable'] === 1) {
        impacts_json(409, [
            'ok'    => false,
            'error' => 'cannot set not_required on a project with involves_minors_or_vulnerable=1 — use pending/verified/rejected',
        ]);
    }
    if ($newStatus === 'verified' && (int) $proj['involves_minors_or_vulnerable'] !== 1) {
        impacts_json(409, [
            'ok'    => false,
            'error' => 'verified only applies to projects with involves_minors_or_vulnerable=1 — set not_required instead',
        ]);
    }

    /* Same-status no-op short-circuits to a stub record so the audit
       trail still captures the review event (a regulator may want
       to see "admin re-confirmed pending on 2026-08-01 after sponsor
       added more docs"). */
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("UPDATE `impact_project` SET `verification_status` = ? WHERE `id` = ?");
        $upd->execute([$newStatus, $projectId]);

        $ins = $pdo->prepare("
            INSERT INTO `safeguarding_record`
                (`impact_project_id`, `prior_status`, `new_status`,
                 `reviewer_admin_id`, `documents_reviewed`, `notes`)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $projectId,
            $priorStatus,
            $newStatus,
            (int) $admin['id'],
            $docs !== '' ? $docs : null,
            $notes !== '' ? $notes : null,
        ]);
        $recordId = (int) $pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    impacts_json(200, [
        'ok'                => true,
        'project_id'        => $projectId,
        'project_title'     => (string) $proj['title'],
        'prior_status'      => $priorStatus,
        'new_status'        => $newStatus,
        'record_id'         => $recordId,
        'reviewer_admin_id' => (int) $admin['id'],
        'decided_at'        => gmdate('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    impacts_safe_error($e, 'set_verification failed');
}
