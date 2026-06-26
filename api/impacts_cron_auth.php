<?php
/**
 * impacts_cron_auth.php — shared cron auth helper.
 *
 * CLI invocations are trusted by filesystem permissions (the
 * SiteGround panel is the only way to write a cron entry, and
 * crontab entries can't be spoofed from the web). HTTP invocations
 * must pass either ?token=<IMPACTS_CRON_SECRET> (preferred) or
 * ?token=<IMPACTS_MIGRATE_TOKEN> (admin-paste fallback).
 *
 * Every cron / scheduled endpoint MUST call this helper at the top
 * of the file — never inline a token check.
 *
 * Usage:
 *   require_once __DIR__ . '/impacts_cron_auth.php';
 *   impacts_cron_auth_check();
 */

require_once __DIR__ . '/impacts_bootstrap.php';

function impacts_cron_auth_check(): void
{
    /* CLI — trusted. */
    if (PHP_SAPI === 'cli') return;

    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "forbidden\n";
        exit;
    }

    $cron    = (string) getenv('IMPACTS_CRON_SECRET');
    $migrate = (string) getenv('IMPACTS_MIGRATE_TOKEN');

    /* Constant-time compare for both candidates so this endpoint
       isn't a timing oracle for the secrets. hash_equals returns
       false when the strings differ in length, so wrap with a
       length-normalising check. */
    $ok = false;
    if ($cron !== '' && hash_equals($cron, $token))    $ok = true;
    if ($migrate !== '' && hash_equals($migrate, $token)) $ok = true;

    if (!$ok) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "forbidden\n";
        exit;
    }
}
