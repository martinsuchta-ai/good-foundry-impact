<?php
/**
 * api/admin/auth.php — admin session helpers (login, logout, current user lookup).
 *
 * Login flow:
 *   POST /api/admin/auth.php?action=login
 *     Body: { "email": "...", "password": "..." }
 *     Sets a httpOnly cookie impacts_admin_session=<token> on success.
 *
 *   POST /api/admin/auth.php?action=logout
 *     Drops the session row + clears the cookie.
 *
 *   GET /api/admin/auth.php?action=me
 *     Returns the current admin user (200) or 401 when no valid session.
 *
 * Session storage: admin_session table (migration 002). Token is a
 * 64-char random hex string. Sessions last 12h from last_seen_at
 * (sliding expiry — every authenticated request pushes expires_at).
 *
 * auth.php doubles as both an entry-point endpoint (login / logout
 * / me action handlers) AND a helper library
 * (impacts_admin_current_user / impacts_admin_require called via
 * require_once from admin/consumers.php etc.). The action-dispatch
 * block below MUST only run when auth.php is the entry point —
 * otherwise every require_once from another endpoint would hit the
 * empty-action gate and 400 with "action required" before the
 * includer even gets to use the helpers. The realpath compare is
 * the standard "is this the main script?" idiom.
 */

declare(strict_types=1);

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';

$_impacts_isEntry = (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__));

if ($_impacts_isEntry) {
    header('Cache-Control: no-store');

    $action = trim((string) ($_GET['action'] ?? ''));
    if ($action === '') {
        impacts_json(400, ['ok' => false, 'error' => 'action required']);
    }

    $pdo = impacts_db();

    try {
        switch ($action) {
            case 'login':   _impacts_admin_login($pdo);   break;
            case 'logout':  _impacts_admin_logout($pdo);  break;
            case 'me':      _impacts_admin_me($pdo);      break;
            default:
                impacts_json(400, ['ok' => false, 'error' => 'unknown action: ' . $action]);
        }
    } catch (Throwable $e) {
        impacts_safe_error($e, 'auth failed');
    }
}

function _impacts_admin_login(PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        impacts_json(405, ['ok' => false, 'error' => 'POST required']);
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        impacts_json(400, ['ok' => false, 'error' => 'invalid JSON body']);
    }
    $email    = trim((string) ($body['email']    ?? ''));
    $password = (string) ($body['password'] ?? '');
    if ($email === '' || $password === '') {
        impacts_json(400, ['ok' => false, 'error' => 'email + password required']);
    }

    /* Constant-time-ish lookup — always run password_verify even on
       a missing user so timing differences don't leak user existence. */
    $stmt = $pdo->prepare("SELECT `id`, `password_hash`, `is_active` FROM `admin_user` WHERE `email` = ? LIMIT 1");
    $stmt->execute([strtolower($email)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $dummyHash = '$2y$10$' . str_repeat('a', 53);   /* timing-padding */
    $hash = (string) ($row['password_hash'] ?? $dummyHash);
    $ok = password_verify($password, $hash);
    if (!$ok || !$row || (int) $row['is_active'] !== 1) {
        impacts_json(401, ['ok' => false, 'error' => 'invalid credentials']);
    }

    /* Issue a new session. */
    $token = bin2hex(random_bytes(32));
    $userId = (string) $row['id'];
    $ttl    = 12 * 60 * 60;
    $expires = gmdate('Y-m-d H:i:s', time() + $ttl);
    $ua  = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $ip  = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $iph = impacts_anonymise_ip($ip);

    $ins = $pdo->prepare("
        INSERT INTO `admin_session` (`id`, `admin_id`, `expires_at`, `ua_excerpt`, `ip_hash`)
        VALUES (?, ?, ?, ?, ?)
    ");
    $ins->execute([$token, $userId, $expires, $ua, $iph]);

    $pdo->prepare("UPDATE `admin_user` SET `last_login_at` = UTC_TIMESTAMP() WHERE `id` = ?")
        ->execute([$userId]);

    /* httpOnly + secure cookie — Domain not set so it scopes to the
       request host (www.impacts-foundry.com in prod). */
    setcookie('impacts_admin_session', $token, [
        'expires'  => time() + $ttl,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    impacts_json(200, ['ok' => true, 'user_id' => $userId]);
}

function _impacts_admin_logout(PDO $pdo): void
{
    $token = (string) ($_COOKIE['impacts_admin_session'] ?? '');
    if ($token !== '') {
        $pdo->prepare("DELETE FROM `admin_session` WHERE `id` = ?")->execute([$token]);
    }
    setcookie('impacts_admin_session', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    impacts_json(200, ['ok' => true]);
}

function _impacts_admin_me(PDO $pdo): void
{
    $user = impacts_admin_current_user($pdo);
    if (!$user) {
        impacts_json(401, ['ok' => false, 'error' => 'not signed in']);
    }
    impacts_json(200, ['ok' => true, 'user' => $user]);
}

/* Reusable helper for protected admin endpoints. Returns the user
   row (id + email + name) or null when no valid session.
   Side effect: bumps last_seen_at on the session so sliding
   expiry resets the 12h clock. */
function impacts_admin_current_user(PDO $pdo): ?array
{
    $token = (string) ($_COOKIE['impacts_admin_session'] ?? '');
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;

    $stmt = $pdo->prepare("
        SELECT u.`id`, u.`email`, u.`display_name`, s.`expires_at`
        FROM `admin_session` s
        JOIN `admin_user`    u ON u.`id` = s.`admin_id` AND u.`is_active` = 1
        WHERE s.`id` = ? AND s.`expires_at` > UTC_TIMESTAMP()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    /* Sliding expiry: bump last_seen_at + extend expires_at by the
       same 12h window so an active admin doesn't get logged out
       mid-session. */
    $newExpires = gmdate('Y-m-d H:i:s', time() + 12 * 60 * 60);
    $pdo->prepare("UPDATE `admin_session` SET `last_seen_at` = UTC_TIMESTAMP(), `expires_at` = ? WHERE `id` = ?")
        ->execute([$newExpires, $token]);

    return [
        'id'    => (string) $row['id'],
        'email' => (string) $row['email'],
        'name'  => (string) ($row['display_name'] ?? ''),
    ];
}

/* Required-auth wrapper — call at the top of admin endpoints. */
function impacts_admin_require(PDO $pdo): array
{
    $user = impacts_admin_current_user($pdo);
    if (!$user) {
        impacts_json(401, ['ok' => false, 'error' => 'admin auth required']);
    }
    return $user;
}
