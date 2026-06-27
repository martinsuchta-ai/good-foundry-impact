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

/* 2026-06-28 — output redesign mirroring WBM api/migrate/run.php.
   Marty: previous JSON-on-one-line response was unreadable in a
   browser once the skipped[] list grew past a screen. Plain text
   sections (NEW / FAILED / RECENTLY APPLIED / SUMMARY) survive
   any backlog size + you can SEE the end of the page. JSON output
   still available via ?format=json for programmatic callers. */

header('Cache-Control: no-store');

$wantJson = (string) ($_GET['format'] ?? '') === 'json';
if (!$wantJson) {
    header('Content-Type: text/plain; charset=utf-8');
}

$applied = [];
$stmt = $pdo->query("SELECT `migration` FROM `schema_migrations`");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $applied[(string) $row['migration']] = true;
}

$files = glob(__DIR__ . '/*.sql') ?: [];
sort($files);

$dryRun = !empty($_GET['dry_run']);

if (!$files) {
    if ($wantJson) impacts_json(200, ['ok' => true, 'message' => 'no migrations']);
    echo "No .sql migration files found.\n";
    exit;
}

$skipped    = [];   /* already applied — collect names */
$newApplied = [];   /* applied this run, in order */
$failedRow  = null; /* [filename, error] on first failure */

foreach ($files as $path) {
    $name = basename($path);
    if (isset($applied[$name])) {
        $skipped[] = $name;
        continue;
    }

    if ($dryRun) {
        if (!$wantJson) echo "[PENDING] $name\n";
        $newApplied[] = $name . ' (dry-run)';
        continue;
    }

    $sql = file_get_contents($path);
    if ($sql === false || !trim($sql)) {
        if (!$wantJson) echo "!! skipping empty/unreadable file: $name\n";
        continue;
    }

    if (!$wantJson) echo "[NEW] applying   $name ... ";

    /* Strip BOM if present (Windows editors). */
    if (substr($sql, 0, 3) === "\xEF\xBB\xBF") $sql = substr($sql, 3);

    try {
        /* Split on `;` boundaries followed by whitespace + a DDL/DML
           keyword. Cheap but robust for the DDL we expect — no
           stored-proc / trigger bodies in our migrations. */
        $statements = array_values(array_filter(
            array_map('trim', preg_split('/;\s*(?=(CREATE|ALTER|INSERT|UPDATE|DELETE|DROP|RENAME)\b)/i', $sql)),
            function ($s) { return $s !== '' && $s !== ';'; }
        ));
        if (!$statements) $statements = [$sql];
        foreach ($statements as $s) {
            $s = trim($s);
            if ($s === '' || $s === ';') continue;
            $pdo->exec($s);
        }
        $stmt = $pdo->prepare(
            "INSERT INTO `schema_migrations` (`migration`) VALUES (?)
             ON DUPLICATE KEY UPDATE `applied_at` = `applied_at`"
        );
        $stmt->execute([$name]);
        if (!$wantJson) echo "OK\n";
        $newApplied[] = $name;
    } catch (Throwable $e) {
        if (!$wantJson) echo "FAILED\n";
        $failedRow = [$name, $e->getMessage()];
        break;  /* abort — print summary so the operator still sees context */
    }
}

if ($wantJson) {
    impacts_json($failedRow ? 500 : 200, [
        'ok'      => $failedRow === null,
        'dry_run' => $dryRun,
        'applied' => $newApplied,
        'skipped' => $skipped,
        'failed'  => $failedRow ? ['migration' => $failedRow[0], 'error' => $failedRow[1]] : null,
    ]);
}

/* ── Plain text summary block ──────────────────────────────────── */
echo "\n";
if ($failedRow) {
    echo "== FAILED ==\n";
    echo "  " . $failedRow[0] . "\n";
    echo "  error: " . $failedRow[1] . "\n";
    echo "  (Aborted run. Fix the error above and re-run — successful migrations before this one are persisted.)\n\n";
}

if (!empty($skipped)) {
    /* Filenames are NNN_xxx prefixed, so rsort gives newest first.
       Show only the latest 5 — Marty wants context, not a backlog
       dump. */
    $recent = $skipped;
    rsort($recent);
    $shown = array_slice($recent, 0, 5);
    echo "== RECENTLY APPLIED == (latest " . count($shown) . " of " . count($skipped) . " already applied)\n";
    foreach ($shown as $name) {
        echo "  v $name\n";
    }
    if (count($skipped) > 5) {
        echo "  ... and " . (count($skipped) - 5) . " older.\n";
    }
    echo "\n";
}

echo "== SUMMARY ==\n";
echo "  New applied:      " . count($newApplied) . ($dryRun ? "  (dry-run — nothing actually applied)" : "") . "\n";
echo "  Already applied:  " . count($skipped) . "\n";
echo "  Failed:           " . ($failedRow ? 1 : 0) . "\n";

if ($failedRow) {
    http_response_code(500);
    exit(1);
}
