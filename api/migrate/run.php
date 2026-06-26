<?php
/**
 * api/migrate/run.php — schema migration runner.
 *
 * Scans api/migrate/*.sql, applies any that haven't been recorded
 * in the `schema_migrations` table, and records each on success.
 *
 *   GET ?token=<IMPACTS_MIGRATE_TOKEN>
 *     Apply pending migrations. Returns a JSON report.
 *
 *   GET ?token=<IMPACTS_MIGRATE_TOKEN>&dry_run=1
 *     List pending migrations without applying them.
 *
 * Conventions (CLAUDE.md §3):
 *   - Files named NNN_<name>.sql apply in lexical order
 *   - NO transactions around DDL — MySQL implicitly commits on
 *     every CREATE/ALTER, so a wrapping transaction throws
 *     "no active transaction" on commit. Use IF NOT EXISTS guards.
 */

declare(strict_types=1);

/* 2026-06-27 — top-level uncaught-exception handler so a fatal
   (e.g. impacts_db() throws "credentials missing" or DB grant
   missing) returns a JSON body instead of a blank 500. Lets the
   admin running the migration see WHAT failed without needing
   to read the PHP error log. */
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    /* Show the actual exception class + message + file:line so a
       missing secrets path, bad grant, etc. surface in one click.
       This endpoint is admin-only (token-gated) so leaking the
       error detail is acceptable. */
    echo json_encode([
        'ok'    => false,
        'error' => 'uncaught: ' . get_class($e) . ': ' . $e->getMessage(),
        'file'  => basename($e->getFile()),
        'line'  => $e->getLine(),
        'hint'  => (strpos($e->getMessage(), 'credentials missing') !== false)
            ? 'impacts_secrets.php not loadable — check path/permissions (db.php tries ../../, ../, and /home/customer/www/impacts-foundry.com/)'
            : ((strpos($e->getMessage(), 'Access denied') !== false || strpos($e->getMessage(), '1045') !== false)
                ? 'DB grant missing — SG panel -> MySQL -> Manage Access -> assign user to the database'
                : null),
    ], JSON_UNESCAPED_SLASHES);
});

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../impacts_cron_auth.php';

impacts_cron_auth_check();   /* same gate as crons — see impacts_cron_auth.php */

$pdo = impacts_db();

/* Bootstrap the registry table on first run. */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `migration` VARCHAR(255) NOT NULL,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`migration`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$applied = [];
$stmt = $pdo->query("SELECT `migration` FROM `schema_migrations`");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $applied[(string) $row['migration']] = true;
}

$files = glob(__DIR__ . '/*.sql') ?: [];
sort($files);

$dryRun  = !empty($_GET['dry_run']);
$pending = [];
$report  = ['applied' => [], 'skipped' => [], 'failed' => null];

foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        $report['skipped'][] = $name;
        continue;
    }
    $pending[] = $file;
}

if ($dryRun) {
    impacts_json(200, [
        'ok'           => true,
        'dry_run'      => true,
        'pending'      => array_map('basename', $pending),
        'applied_so_far' => array_keys($applied),
    ]);
}

foreach ($pending as $file) {
    $name = basename($file);
    $sql  = file_get_contents($file);
    if ($sql === false) {
        $report['failed'] = ['migration' => $name, 'error' => 'unreadable'];
        break;
    }

    try {
        /* Split on `;` boundaries that are followed by whitespace +
           a keyword (CREATE/ALTER/INSERT/etc). Cheap but robust for
           the DDL we expect — no stored-proc / trigger bodies. */
        $statements = array_values(array_filter(
            array_map('trim', preg_split('/;\s*(?=(CREATE|ALTER|INSERT|UPDATE|DELETE|DROP|RENAME)\b)/i', $sql)),
            function ($s) { return $s !== '' && $s !== ';'; }
        ));
        if (!$statements) {
            $statements = [$sql];   /* fall back to whole file */
        }
        foreach ($statements as $s) {
            $s = trim($s);
            if ($s === '' || $s === ';') continue;
            $pdo->exec($s);
        }
        $stmt = $pdo->prepare("INSERT INTO `schema_migrations` (`migration`) VALUES (?)");
        $stmt->execute([$name]);
        $report['applied'][] = $name;
    } catch (Throwable $e) {
        $report['failed'] = ['migration' => $name, 'error' => $e->getMessage()];
        break;
    }
}

impacts_json($report['failed'] ? 500 : 200, ['ok' => $report['failed'] === null] + $report);
