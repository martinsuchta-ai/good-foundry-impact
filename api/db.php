<?php
/**
 * db.php — single PDO connection helper for every endpoint.
 *
 * Reads credentials from impacts_secrets.php (above webroot — see
 * CLAUDE.md §5) and returns a process-scoped cached PDO handle.
 *
 * Conventions:
 *   - UTF-8 charset
 *   - Exceptions on error (PDO::ERRMODE_EXCEPTION)
 *   - Prepared statements only — never emulate
 *   - MySQL session timezone forced to '+00:00' (CLAUDE.md §2)
 *
 * Usage:
 *   require_once __DIR__ . '/impacts_bootstrap.php';
 *   require_once __DIR__ . '/db.php';
 *   $pdo = impacts_db();
 */

require_once __DIR__ . '/impacts_bootstrap.php';

/* Load secrets ONCE per process. The file lives ABOVE the webroot;
   the path varies subtly by SG layout. CLAUDE.md §5 is the contract.
   If the file is missing we still let the script run (so
   unauthenticated routes can return a clean 503 instead of a stack
   trace) — impacts_db() itself throws when it can't connect.
   Try a series of candidate paths instead of a single realpath,
   because SG's open_basedir can make realpath() return false
   silently. Stop at the first one that loads. */
$_impacts_secrets_candidates = [
    __DIR__ . '/../../impacts_secrets.php',                                 // expected — two levels up from api/db.php
    __DIR__ . '/../impacts_secrets.php',                                    // one-level fallback
    '/home/customer/www/impacts-foundry.com/impacts_secrets.php',           // absolute SG layout
];
foreach ($_impacts_secrets_candidates as $_p) {
    if (@is_file($_p) && @is_readable($_p)) {
        require_once $_p;
        if (!defined('IMPACTS_SECRETS_LOADED_FROM')) define('IMPACTS_SECRETS_LOADED_FROM', $_p);
        break;
    }
}
unset($_p, $_impacts_secrets_candidates);

function impacts_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host = (string) getenv('IMPACTS_DB_HOST');
    $name = (string) getenv('IMPACTS_DB_NAME');
    $user = (string) getenv('IMPACTS_DB_USER');
    $pass = (string) getenv('IMPACTS_DB_PASS');

    if ($host === '' || $name === '' || $user === '') {
        throw new RuntimeException('impacts_db: database credentials missing — impacts_secrets.php not loaded?');
    }

    $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4';
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => false,
    ];

    $pdo = new PDO($dsn, $user, $pass, $opts);

    /* Force session timezone to UTC (CLAUDE.md §2). PDO::ATTR_INIT_COMMAND
       isn't honoured on all drivers so run it as a follow-up exec. */
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

/* JSON response helper — every endpoint that writes JSON should
   route through this so the content type, charset, and HTTP status
   are consistent. */
function impacts_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* Safe error response — logs the throwable's full message + trace
   to the PHP error_log, returns a generic JSON body so we never
   leak schema or secrets to the client. */
function impacts_safe_error(Throwable $e, string $publicMessage = 'internal error'): void
{
    error_log('[impacts_db] ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    impacts_json(500, ['ok' => false, 'error' => $publicMessage]);
}
