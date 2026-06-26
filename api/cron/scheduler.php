<?php
/**
 * api/cron/scheduler.php — periodic state-machine driver for impact_project.
 *
 * Brief §4 contract: every 5 minutes (or whatever cadence the SG cron
 * job is set to), flip every project whose start_at has passed from
 * `planning` to `execution`, and every project whose end_at has passed
 * from `execution` to `done`. Idempotent + reconciliation-safe.
 *
 * Invocation:
 *
 *   CLI (preferred — SG cron):
 *     /usr/local/bin/php /home/customer/www/impacts-foundry.com/public_html/api/cron/scheduler.php
 *
 *   HTTP (fallback):
 *     GET /api/cron/scheduler.php?token=<IMPACTS_CRON_SECRET>
 *       (also accepts IMPACTS_MIGRATE_TOKEN per the shared auth helper)
 *
 * Response (JSON):
 *   {
 *     "ok": true,
 *     "ran_at": "2026-06-27 12:00:00",
 *     "planning_to_execution": [12, 47],
 *     "execution_to_done": [9],
 *     "blocked_by_verification": [33],
 *     "errors": []
 *   }
 *
 * Recommended SG cron schedule: every 5 minutes.
 *   Frequent enough that a project's start_at is typically only 0-5 min late
 *   when its execution kicks off. Rare enough that the scheduler isn't
 *   bombarding the DB with empty scans.
 */

declare(strict_types=1);
@set_time_limit(60);

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../impacts_cron_auth.php';
require_once __DIR__ . '/../impacts_state_engine.php';

impacts_cron_auth_check();

try {
    $pdo = impacts_db();
    $report = impacts_run_scheduler($pdo);

    /* Log every non-trivial run to the PHP error log so SG's log
       viewer doubles as the scheduler audit. Empty runs stay
       silent so we don't fill the log with no-op noise. */
    if (
        !empty($report['planning_to_execution']) ||
        !empty($report['execution_to_done']) ||
        !empty($report['blocked_by_verification']) ||
        !empty($report['errors'])
    ) {
        error_log('[impacts_scheduler] ran_at=' . $report['ran_at']
            . ' p2e=' . count($report['planning_to_execution'])
            . ' e2d=' . count($report['execution_to_done'])
            . ' blocked=' . count($report['blocked_by_verification'])
            . ' errors=' . count($report['errors']));
    }

    impacts_json($report['ok'] ? 200 : 500, $report);
} catch (Throwable $e) {
    impacts_safe_error($e, 'scheduler failed');
}
