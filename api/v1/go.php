<?php
/**
 * api/v1/go.php — logged outbound-redirect endpoint.
 *
 * Brief §6 + §7: the money lane is route-only. GMI never custodies
 * funds. Consumers point CTA links at this endpoint instead of the
 * raw external_destination_url so we:
 *
 *   1. Log a click_event (attribution for Phase 1h reporting)
 *   2. 302 redirect to the ask's external_destination_url
 *
 * Usage from a consumer site (HTML widget):
 *
 *   <a href="https://www.impacts-foundry.com/api/v1/go.php?ask=42&api_key=<key>">
 *     Donate to materials fund →
 *   </a>
 *
 * Auth: consumer api_key (Bearer header OR ?api_key= query param).
 * api_key on a redirect endpoint is unusual but lets us track WHICH
 * consumer drove the click. Without it the click is logged with
 * consumer_id=NULL.
 *
 * Restrictions:
 *   - Ask must be active (is_active=1)
 *   - Ask's project must be in planning or execution state
 *   - Ask must have a redirect URL — works for any lane, not just
 *     money (effort/energy can also use it for "learn more" links
 *     if external_destination_url is populated)
 *
 * Privacy: anonymised_user_id = SHA-256(ip + secret + daily salt)
 * — no raw IPs stored. UA truncated to 500 chars.
 *
 * Failure mode: when the ask isn't redirectable (inactive, missing
 * URL, project in mission/done), we 410 Gone with a tiny JSON body
 * instead of redirecting to a default — better the consumer's link
 * looks broken than for the supporter to land somewhere unexpected.
 */

declare(strict_types=1);

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';

$askId = (int) ($_GET['ask'] ?? 0);
if ($askId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'ask query param required']);
    exit;
}

/* api_key is optional on this endpoint — when present we attribute
   the click to that consumer; when absent we still log + redirect. */
$apiKey = impacts_extract_api_key();

try {
    $pdo = impacts_db();

    $consumerId = null;
    if ($apiKey !== '') {
        $cStmt = $pdo->prepare("SELECT `id` FROM `consumer` WHERE `api_key` = ? AND `is_active` = 1 LIMIT 1");
        $cStmt->execute([$apiKey]);
        $consumerId = (int) ($cStmt->fetchColumn() ?: 0) ?: null;
    }

    /* Resolve the ask + its project state. */
    $aStmt = $pdo->prepare("
        SELECT a.`id`, a.`lane`, a.`is_active`, a.`external_destination_url`,
               p.`state` AS project_state
        FROM `contribution_ask` a
        JOIN `impact_project` p ON p.`id` = a.`impact_project_id`
        WHERE a.`id` = ?
        LIMIT 1
    ");
    $aStmt->execute([$askId]);
    $ask = $aStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ask) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'ask not found']);
        exit;
    }

    if (!(int) $ask['is_active']) {
        http_response_code(410);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'this ask is inactive']);
        exit;
    }

    if (!in_array($ask['project_state'], ['planning', 'execution'], true)) {
        http_response_code(410);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'error' => "project is in state '" . $ask['project_state'] . "' — redirect only available on planning + execution"
        ]);
        exit;
    }

    $redirectTo = (string) ($ask['external_destination_url'] ?? '');
    if ($redirectTo === '') {
        http_response_code(410);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'this ask has no external_destination_url configured']);
        exit;
    }

    /* Validate the URL is http/https. Reject anything else (file://,
       javascript:, data:) so this endpoint can't be weaponised by a
       compromised admin into an arbitrary-URL redirector. */
    if (!preg_match('#^https?://#i', $redirectTo)) {
        http_response_code(409);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'redirect URL must be http or https']);
        exit;
    }

    /* Log the click. Failures here MUST NOT block the redirect —
       a broken log is better than a broken CTA. Wrapped in
       try/catch with a logged warning. */
    try {
        $ip     = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $anonId = impacts_anonymise_ip($ip);
        $ua     = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

        $ins = $pdo->prepare("
            INSERT INTO `click_event`
                (`contribution_ask_id`, `consumer_id`, `anonymised_user_id`, `redirect_to`, `ua_excerpt`)
            VALUES (?, ?, ?, ?, ?)
        ");
        $ins->execute([$askId, $consumerId, $anonId, $redirectTo, $ua]);
    } catch (Throwable $e) {
        error_log('[v1/go] click logging failed for ask ' . $askId . ': ' . $e->getMessage());
    }

    /* 302 redirect — see-other not permanent. The destination is
       sponsor-configurable so we should never cache it. */
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Location: ' . $redirectTo, true, 302);
    exit;
} catch (Throwable $e) {
    impacts_safe_error($e, 'redirect failed');
}
