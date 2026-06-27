<?php
/**
 * impacts_notifications.php — transition-driven email notifications.
 *
 * Brief §4 contract: the scheduler MUST fire transition-driven
 * CTAs/reminders. This module supplies the impacts_notify_transition()
 * function that impacts_state_engine.php calls after every successful
 * state flip.
 *
 * Recipients per transition:
 *
 *   mission → planning (admin-only, brief Phase 1b: never auto)
 *     - Sponsor: "Your project is approved + open for support"
 *     - Admin (info@): audit trail BCC
 *
 *   planning → execution (auto via scheduler at start_at)
 *     - Sponsor: "Your project is live — execution window has started"
 *     - Supporters who pledged (with email): "The project you backed is live"
 *     - Admin (info@): audit BCC
 *
 *   execution → done (auto via scheduler at end_at)
 *     - Sponsor: "Project closed — please record the outcome"
 *     - Supporters: "Thank you — the project you backed is now done"
 *     - Admin (info@): audit BCC
 *
 * Failure semantics: every send is wrapped in try/catch. A failed
 * email is logged but NEVER blocks the state transition. The state
 * machine is the source of truth; emails are a notification layer.
 *
 * Loop scope cap: when there are >200 supporter emails for a single
 * transition, we hard-cap at 200 and log the overflow. Prevents a
 * runaway scheduler from sending 10k emails in one tick.
 */

if (defined('IMPACTS_NOTIFICATIONS_LOADED')) return;
define('IMPACTS_NOTIFICATIONS_LOADED', true);

require_once __DIR__ . '/impacts_bootstrap.php';
require_once __DIR__ . '/impacts_mail.php';

/**
 * Fire transition notifications. Called from impacts_state_engine
 * after the state change + audit row land.
 *
 * @param PDO    $pdo
 * @param int    $projectId
 * @param string $fromState  ('mission'|'planning'|'execution'|'done')
 * @param string $toState
 * @return array { sent: int, failed: int, skipped_no_recipients: bool }
 */
function impacts_notify_transition(PDO $pdo, int $projectId, string $fromState, string $toState): array
{
    $report = ['sent' => 0, 'failed' => 0, 'skipped_no_recipients' => false];

    try {
        /* Load the project + sponsor in one query so we can compose
           the email body with the right names + dates. */
        $stmt = $pdo->prepare("
            SELECT p.`id`, p.`title`, p.`description`, p.`scale`,
                   p.`start_at`, p.`end_at`, p.`success_measure`,
                   p.`sponsor_id`,
                   s.`display_name` AS sponsor_name, s.`email` AS sponsor_email
            FROM `impact_project` p
            LEFT JOIN `sponsor` s ON s.`id` = p.`sponsor_id`
            WHERE p.`id` = ?
            LIMIT 1
        ");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$project) {
            error_log('[impacts_notifications] project not found: ' . $projectId);
            return $report;
        }

        $subject = _impacts_notify_subject($project, $fromState, $toState);
        $body    = _impacts_notify_body($project, $fromState, $toState);

        $sentTo = [];

        /* Sponsor — always notified when they have an email. */
        if (!empty($project['sponsor_email'])) {
            $ok = impacts_send_mail($project['sponsor_email'], $subject, $body, [
                'bcc' => 'info@impacts-foundry.com',
            ]);
            $ok ? $report['sent']++ : $report['failed']++;
            $sentTo[] = $project['sponsor_email'];
        }

        /* Supporters with email — notify on planning→execution +
           execution→done. mission→planning doesn't ping supporters
           because there are no pledges yet (project just opened). */
        if (in_array($toState, ['execution', 'done'], true)) {
            $supStmt = $pdo->prepare("
                SELECT DISTINCT s.`email`, s.`display_name`
                FROM `contribution_pledge` cp
                JOIN `contribution_ask` ca ON ca.`id` = cp.`contribution_ask_id`
                JOIN `supporter` s         ON s.`id` = cp.`supporter_id`
                WHERE ca.`impact_project_id` = ?
                  AND cp.`status` IN ('pledged', 'confirmed', 'fulfilled')
                  AND s.`email` IS NOT NULL
                LIMIT 200
            ");
            $supStmt->execute([$projectId]);
            $supporters = $supStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (count($supporters) === 200) {
                error_log('[impacts_notifications] supporter cap hit (200) on project ' . $projectId
                    . ' transition ' . $fromState . '->' . $toState . ' — additional supporters not emailed');
            }

            foreach ($supporters as $sup) {
                /* Skip if we already sent to this address as the
                   sponsor — same email, no duplicate. */
                if (in_array($sup['email'], $sentTo, true)) continue;
                $ok = impacts_send_mail($sup['email'], $subject, $body);
                $ok ? $report['sent']++ : $report['failed']++;
                $sentTo[] = $sup['email'];
            }
        }

        if (!$sentTo) {
            $report['skipped_no_recipients'] = true;
            /* Still BCC the admin so transitions on sponsorless +
               supporterless projects don't go entirely unnoticed. */
            impacts_send_mail('info@impacts-foundry.com', $subject, $body);
            $report['sent']++;
        }
    } catch (Throwable $e) {
        error_log('[impacts_notifications] unhandled exception on project ' . $projectId
            . ' (' . $fromState . '->' . $toState . '): ' . $e->getMessage());
    }

    return $report;
}

function _impacts_notify_subject(array $project, string $fromState, string $toState): string
{
    $title = $project['title'] ?: ('Project #' . $project['id']);
    if ($toState === 'planning') return '[Impacts] "' . $title . '" is open for support';
    if ($toState === 'execution') return '[Impacts] "' . $title . '" is live now';
    if ($toState === 'done')      return '[Impacts] "' . $title . '" has closed — please record the outcome';
    return '[Impacts] "' . $title . '" — ' . $fromState . ' → ' . $toState;
}

function _impacts_notify_body(array $project, string $fromState, string $toState): string
{
    $title    = htmlspecialchars($project['title'] ?: ('Project #' . $project['id']), ENT_QUOTES, 'UTF-8');
    $sponsor  = htmlspecialchars((string) ($project['sponsor_name'] ?? 'the sponsor'), ENT_QUOTES, 'UTF-8');
    $startAt  = $project['start_at'] ? htmlspecialchars((string) $project['start_at'], ENT_QUOTES, 'UTF-8') : '';
    $endAt    = $project['end_at']   ? htmlspecialchars((string) $project['end_at'],   ENT_QUOTES, 'UTF-8') : '';
    $descrip  = htmlspecialchars((string) ($project['description'] ?? ''), ENT_QUOTES, 'UTF-8');
    $success  = htmlspecialchars((string) ($project['success_measure'] ?? ''), ENT_QUOTES, 'UTF-8');

    /* Per-transition headline + CTA. */
    $headline = '';
    $cta      = '';
    if ($toState === 'planning') {
        $headline = 'Open for support';
        $cta      = 'This project has been approved and is now accepting commitments. Anyone who wants to contribute Effort, Energy, or Money can pledge between now and ' . ($startAt ? ('the start at <strong>' . $startAt . '</strong>') : 'execution');
    } elseif ($toState === 'execution') {
        $headline = 'Live now';
        $cta      = 'The execution window for this project is now open' . ($endAt ? (' through <strong>' . $endAt . '</strong>') : '') . '. Confirmed pledges should be fulfilled within the window.';
    } elseif ($toState === 'done') {
        $headline = 'Closed — please record the outcome';
        $cta      = 'The execution window has ended. ' . $sponsor . ', please log the project\'s outcome against the success measure so the impact lineage is captured';
        if ($success !== '') $cta .= ' (<em>' . $success . '</em>)';
        $cta     .= '.';
    } else {
        $headline = $fromState . ' → ' . $toState;
        $cta      = 'State transition logged.';
    }

    return ''
        . '<div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#2e7d52;margin-bottom:8px">' . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<h2 style="margin:0 0 14px;font-size:20px;font-weight:800;letter-spacing:-.01em">' . $title . '</h2>'
        . ($descrip !== '' ? '<p style="font-size:13px;line-height:1.55;color:#4a5f72;margin:0 0 16px">' . $descrip . '</p>' : '')
        . '<div style="background:#f4f7fb;border-left:3px solid #2e7d52;padding:12px 14px;border-radius:6px;font-size:13px;line-height:1.55;color:#1c2b3a">' . $cta . '</div>';
}
