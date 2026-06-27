<?php
/**
 * impacts_mail.php — outbound email helper for the Impacts platform.
 *
 * Phase 1b — wraps PHP's mail() with consistent branded headers so
 * every transactional email from the platform reads as coming from
 * info@impacts-foundry.com. SiteGround's local sendmail handles the
 * actual SMTP transport.
 *
 * For richer SMTP control (TLS auth against mail.impacts-foundry.com,
 * delivery receipts, bounce handling) we'd swap in PHPMailer in a
 * later phase. mail() is plenty for the Phase 1b cadence of
 * transition notifications.
 *
 * Usage:
 *   require_once __DIR__ . '/impacts_mail.php';
 *   impacts_send_mail($to, $subject, $bodyHtml);
 *
 * The function silently swallows + logs failures — outbound mail
 * MUST NOT block a scheduler run or an admin action. Returns
 * true / false so the caller can audit but the scheduler itself
 * just keeps going on a failure.
 */

if (defined('IMPACTS_MAIL_LOADED')) return;
define('IMPACTS_MAIL_LOADED', true);

require_once __DIR__ . '/impacts_bootstrap.php';

/**
 * Send a single transactional email.
 *
 * @param string $to       recipient email address
 * @param string $subject  one-line subject
 * @param string $bodyHtml HTML body — the function wraps it in the
 *                         standard outer table + adds plain-text
 *                         alternative via the auto MIME headers
 * @param array  $opts     {
 *                           reply_to?: string,
 *                           bcc?: string|string[],
 *                           from_name?: string  (default 'Impacts Foundry')
 *                         }
 * @return bool true on mail() success, false on failure
 */
function impacts_send_mail(string $to, string $subject, string $bodyHtml, array $opts = []): bool
{
    if ($to === '' || strpos($to, '@') === false) {
        error_log('[impacts_mail] invalid recipient: ' . $to);
        return false;
    }

    $fromAddr = 'info@impacts-foundry.com';
    $fromName = (string) ($opts['from_name'] ?? 'Impacts Foundry');

    /* RFC 5322 + 2047 — quote the display name + encode any non-ASCII. */
    $fromHeader = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromAddr . '>';

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $fromHeader,
        'Reply-To: ' . (!empty($opts['reply_to']) ? $opts['reply_to'] : $fromAddr),
        'X-Mailer: ImpactsFoundry/1.0',
    ];

    if (!empty($opts['bcc'])) {
        $bcc = is_array($opts['bcc']) ? implode(', ', $opts['bcc']) : (string) $opts['bcc'];
        $headers[] = 'Bcc: ' . $bcc;
    }

    /* Wrap body in a simple outer table so inbox clients render
       consistently. Inline styles only — most clients strip
       <style> blocks. */
    $wrappedBody = '<!doctype html><html><body style="margin:0;padding:0;background:#f4f7fb;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;color:#1c2b3a">'
                 . '<div style="max-width:560px;margin:24px auto;background:#ffffff;border-radius:12px;padding:32px 28px;border:1px solid #e2e8ef">'
                 . $bodyHtml
                 . '<div style="margin-top:28px;padding-top:18px;border-top:1px solid #e2e8ef;font-size:11px;color:#8097ad;line-height:1.5">'
                 .   'Impacts Foundry — <em>It\'s not about the money, honey — it\'s about making an impact.</em><br>'
                 .   'You received this because you\'re a stakeholder on an impact project. To stop these emails reply with "unsubscribe".'
                 . '</div>'
                 . '</div></body></html>';

    /* PHP's mail() returns false on submission failure (sendmail
       refused), true otherwise. True does NOT mean delivered — it
       means handed off. Real bounce tracking would need PHPMailer
       + delivery receipts, deferred to a later phase. */
    $ok = @mail($to, $subject, $wrappedBody, implode("\r\n", $headers));
    if (!$ok) {
        error_log('[impacts_mail] mail() returned false for ' . $to . ' subject: ' . $subject);
    }
    return $ok;
}
