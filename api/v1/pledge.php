<?php
/**
 * api/v1/pledge.php — public endpoint for a supporter to commit to an ask.
 *
 * Auth: consumer api_key (Bearer or ?api_key=). CORS-restricted to
 * consumer's widget_origins. The api_key identifies the SITE the
 * supporter came in via; the supporter themselves stays
 * pseudonymous (anonymised_user_id hash from IP) unless they
 * volunteer display_name + email.
 *
 *   POST /api/v1/pledge.php
 *     Body: {
 *       contribution_ask_id: 42,
 *       quantity: 4,                  // optional; e.g. 4 hours, $50, etc.
 *       display_name: "Jane",          // optional — supporter consent only
 *       email: "jane@example.com",     // optional — for fulfilment coordination
 *       note: "I can do Saturday 9am"  // optional — free text for the sponsor
 *     }
 *
 *   Returns:
 *     201 { ok, pledge_id, supporter_id, status: 'pledged' }
 *     If lane='money', the response ALSO includes the
 *     external_destination_url so the consumer can redirect the
 *     supporter to the regulated rail (brief §6 — route-only).
 *
 * NEVER stores raw IP. anonymised_user_id is SHA-256(ip + secret +
 * daily salt) per CLAUDE.md §8.
 *
 * Project state gate: pledges are only accepted on asks attached
 * to a project in state='planning' or 'execution'. Mission state
 * projects are not yet open for pledges; done state projects are
 * historical.
 */

declare(strict_types=1);

require_once __DIR__ . '/../impacts_bootstrap.php';
require_once __DIR__ . '/../db.php';

impacts_send_cors_origin();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    impacts_json(405, ['ok' => false, 'error' => 'POST required']);
}

$apiKey = impacts_extract_api_key();
if ($apiKey === '') impacts_json(401, ['ok' => false, 'error' => 'api_key required']);

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) impacts_json(400, ['ok' => false, 'error' => 'invalid JSON body']);

$askId = (int) ($body['contribution_ask_id'] ?? 0);
if ($askId <= 0) impacts_json(400, ['ok' => false, 'error' => 'contribution_ask_id required']);

try {
    $pdo = impacts_db();

    /* Resolve the consumer. */
    $cStmt = $pdo->prepare("SELECT `id` FROM `consumer` WHERE `api_key` = ? AND `is_active` = 1 LIMIT 1");
    $cStmt->execute([$apiKey]);
    $consumerId = (int) $cStmt->fetchColumn();
    if ($consumerId <= 0) impacts_json(401, ['ok' => false, 'error' => 'invalid or inactive api_key']);

    /* Resolve the ask + the project state. */
    $aStmt = $pdo->prepare("
        SELECT a.`id`, a.`lane`, a.`is_active`, a.`external_destination_url`,
               p.`id` AS project_id, p.`state` AS project_state
        FROM `contribution_ask` a
        JOIN `impact_project` p ON p.`id` = a.`impact_project_id`
        WHERE a.`id` = ?
        LIMIT 1
    ");
    $aStmt->execute([$askId]);
    $ask = $aStmt->fetch(PDO::FETCH_ASSOC);
    if (!$ask) impacts_json(404, ['ok' => false, 'error' => 'contribution_ask not found']);
    if (!(int) $ask['is_active']) impacts_json(409, ['ok' => false, 'error' => 'this ask is inactive']);

    /* Project state gate. */
    if (!in_array($ask['project_state'], ['planning', 'execution'], true)) {
        impacts_json(409, [
            'ok'    => false,
            'error' => "project is in state '" . $ask['project_state'] . "' — pledges only accepted on planning + execution"
        ]);
    }

    /* Build (or reuse) the supporter row.
       If email is provided, look up an existing supporter by
       (consumer_id, email) — otherwise create a fresh row keyed
       by anonymised_user_id. Anonymous + reused-IP-same-day pledges
       map to a single supporter row that day. */
    $displayName = isset($body['display_name']) ? trim((string) $body['display_name']) : '';
    $email       = isset($body['email'])        ? trim((string) $body['email'])        : '';
    $ip          = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $anonId      = impacts_anonymise_ip($ip);

    $supporterId = null;
    if ($email !== '') {
        $eStmt = $pdo->prepare("SELECT `id` FROM `supporter` WHERE `email` = ? AND `consumer_id` <=> ? LIMIT 1");
        $eStmt->execute([$email, $consumerId]);
        $supporterId = (int) ($eStmt->fetchColumn() ?: 0);
        if ($supporterId === 0) {
            $insS = $pdo->prepare("
                INSERT INTO `supporter` (`anonymised_user_id`, `display_name`, `email`, `consumer_id`, `last_seen_at`)
                VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
            ");
            $insS->execute([$anonId, $displayName ?: null, $email, $consumerId]);
            $supporterId = (int) $pdo->lastInsertId();
        } else {
            /* Touch last_seen_at + optionally update display_name. */
            $pdo->prepare("UPDATE `supporter` SET `last_seen_at` = UTC_TIMESTAMP(), `display_name` = COALESCE(NULLIF(?, ''), `display_name`) WHERE `id` = ?")
                ->execute([$displayName, $supporterId]);
        }
    } else {
        /* Anonymous — match by anonId for same-day deduplication. */
        $eStmt = $pdo->prepare("
            SELECT `id` FROM `supporter`
            WHERE `anonymised_user_id` = ? AND `email` IS NULL AND `consumer_id` <=> ?
            ORDER BY `id` DESC LIMIT 1
        ");
        $eStmt->execute([$anonId, $consumerId]);
        $supporterId = (int) ($eStmt->fetchColumn() ?: 0);
        if ($supporterId === 0) {
            $insS = $pdo->prepare("
                INSERT INTO `supporter` (`anonymised_user_id`, `display_name`, `consumer_id`, `last_seen_at`)
                VALUES (?, ?, ?, UTC_TIMESTAMP())
            ");
            $insS->execute([$anonId, $displayName ?: null, $consumerId]);
            $supporterId = (int) $pdo->lastInsertId();
        }
    }

    /* Create the pledge. */
    $quantity = (isset($body['quantity']) && $body['quantity'] !== '' && $body['quantity'] !== null)
        ? (float) $body['quantity']
        : null;
    $note = isset($body['note']) ? mb_substr((string) $body['note'], 0, 2000) : null;

    $insP = $pdo->prepare("
        INSERT INTO `contribution_pledge`
            (`contribution_ask_id`, `supporter_id`, `lane`, `quantity`, `status`, `note`)
        VALUES (?, ?, ?, ?, 'pledged', ?)
    ");
    $insP->execute([$askId, $supporterId, $ask['lane'], $quantity, $note]);
    $pledgeId = (int) $pdo->lastInsertId();

    $response = [
        'ok'           => true,
        'pledge_id'    => $pledgeId,
        'supporter_id' => $supporterId,
        'status'       => 'pledged',
        'lane'         => $ask['lane'],
    ];

    /* Brief §6 — money lane responds with the external redirect URL
       so the consumer can route the supporter to the regulated rail.
       GMI never custodies the funds. */
    if ($ask['lane'] === 'money' && !empty($ask['external_destination_url'])) {
        $response['external_destination_url'] = $ask['external_destination_url'];
        $response['note'] = 'GMI does not custody funds. Redirect the supporter to external_destination_url on a regulated rail.';
    }

    impacts_json(201, $response);
} catch (Throwable $e) {
    impacts_safe_error($e, 'pledge failed');
}
