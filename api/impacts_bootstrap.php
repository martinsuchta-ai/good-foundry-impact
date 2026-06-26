<?php
/**
 * impacts_bootstrap.php — request-time conventions for every endpoint.
 *
 * Sets up:
 *   1. UTC timezone (PHP + MySQL session)
 *   2. UTF-8 default content type expectation
 *   3. Error reporting tuned for production (errors logged, not displayed)
 *   4. CORS origin helper (called by endpoints that serve consumer requests)
 *   5. SHA-256 anonymisation helper for click/redirect events (privacy §8)
 *   6. Bootstrap-once guard so multiple require_once chains don't re-run setup
 *
 * Every endpoint should require this BEFORE db.php so the timezone
 * + UTF-8 settings land before any connection is opened.
 */

if (defined('IMPACTS_BOOTSTRAPPED')) return;
define('IMPACTS_BOOTSTRAPPED', true);

/* ── 1. UTC timezone (PHP-side) ─────────────────────────────────
   Per CLAUDE.md §2: all timestamps stored UTC. The MySQL session tz
   is forced in db.php on connection — this handles the PHP side. */
date_default_timezone_set('UTC');

/* ── 2. UTF-8 default ───────────────────────────────────────────
   Endpoints can override their content type but the default for
   anything that doesn't is UTF-8 JSON. */
mb_internal_encoding('UTF-8');

/* ── 3. Error reporting ────────────────────────────────────────
   Errors logged to PHP error_log, never shown to clients. Saves
   leaking schema or secrets via a stack trace. */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '0');
ini_set('log_errors',     '1');

/* ── 4. CORS origin helper ─────────────────────────────────────
   Called by endpoints that serve cross-origin consumer requests.
   Reads consumer.widget_origins for the api_key on the
   Authorization header; emits Access-Control-Allow-Origin
   accordingly. Public click endpoints (e.g. /v1/go/<token>) don't
   need this because they're redirects.

   Usage:
     require_once __DIR__ . '/impacts_bootstrap.php';
     impacts_send_cors_origin();
*/
function impacts_send_cors_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') return;

    /* Pull the consumer's widget_origins from the api_key. Endpoints
       authenticate the api_key separately; we accept that the CORS
       header is "advisory" until the auth check happens. A request
       with a bogus api_key + valid origin gets the CORS header but
       still 401s on the actual work. */
    $apiKey = impacts_extract_api_key();
    if ($apiKey === '') return;

    try {
        require_once __DIR__ . '/db.php';
        $pdo = impacts_db();
        $stmt = $pdo->prepare("SELECT `widget_origins` FROM `consumer` WHERE `api_key` = ? AND `is_active` = 1 LIMIT 1");
        $stmt->execute([$apiKey]);
        $origins = $stmt->fetchColumn();
        if ($origins === false) return;
        $allowed = json_decode((string) $origins, true);
        if (!is_array($allowed)) return;
        foreach ($allowed as $allowedOrigin) {
            if (strcasecmp((string) $allowedOrigin, $origin) === 0) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
                header('Access-Control-Allow-Credentials: false');
                header('Access-Control-Allow-Headers: Content-Type, Authorization');
                header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
                return;
            }
        }
    } catch (Throwable $e) {
        /* Log + swallow — CORS is presentation; never block the
           actual request because the lookup hiccuped. */
        error_log('[impacts_bootstrap] cors lookup failed: ' . $e->getMessage());
    }
}

/* Extract the api_key from the request — Authorization: Bearer <k>
   first, then ?api_key=<k> fallback. Empty string when absent. */
function impacts_extract_api_key(): string
{
    $auth = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION']))            $auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
    elseif (function_exists('apache_request_headers'))    {
        $h = apache_request_headers();
        if (is_array($h) && isset($h['Authorization']))   $auth = (string) $h['Authorization'];
    }
    if ($auth !== '' && stripos($auth, 'bearer ') === 0) {
        return trim(substr($auth, 7));
    }
    if (isset($_GET['api_key']) && is_string($_GET['api_key'])) {
        return trim($_GET['api_key']);
    }
    return '';
}

/* ── 5. SHA-256 anonymisation helper ───────────────────────────
   Per CLAUDE.md §8: never store raw IPs. Combine IP + session
   secret + a daily salt so the resulting hash isn't deterministic
   across days — limits replay-attack value of any leaked hashes
   while keeping same-day deduplication possible. */
function impacts_anonymise_ip(string $ip): string
{
    $secret = (string) getenv('IMPACTS_SESSION_SECRET');
    if ($secret === '') {
        /* Bootstrap-safe fallback: if secrets aren't loaded yet
           (e.g. cron auth still resolving), hash with a fixed
           per-day salt so we never write raw IPs. */
        $secret = 'impacts-bootstrap-fallback';
    }
    $day = gmdate('Y-m-d');
    return hash('sha256', $ip . '|' . $day . '|' . $secret);
}
