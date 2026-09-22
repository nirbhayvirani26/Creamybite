<?php
// ============================================================
//  Creamy Bite – Why is no email arriving?
//  URL: /admin/migrations/mail_test.php
//
//  Every send in includes/mailer.php is wrapped in try/catch, and every catch
//  does the same thing: error_log() and return false. No caller checks the
//  return value. So when mail stops working the shop sees nothing at all —
//  orders still complete, invoices still save, and the emails simply never
//  arrive. The reason is written to a log file most people never open.
//
//  This page is the missing half of that. It reads the settings the mailer
//  would use, opens the connection itself so a blocked port can be told apart
//  from a wrong password, and — when asked — sends one real message and shows
//  the entire SMTP conversation, error and all.
//
//  Sends nothing unless the button is pressed. Changes nothing, ever.
//
//  The password is never printed. PHPMailer's debug output contains the AUTH
//  exchange, so cbMailScrub() below strips it before anything reaches the page.
// ============================================================
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_permissions.php';
adminRequire('store');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$cfg = [
    'host' => defined('SMTP_HOST') ? (string)SMTP_HOST : '',
    'port' => defined('SMTP_PORT') ? (int)SMTP_PORT : 0,
    'user' => defined('SMTP_USER') ? trim((string)SMTP_USER) : '',
    'pass' => defined('SMTP_PASS') ? (string)SMTP_PASS : '',
];
$shopHost = (defined('SHOP_EMAIL') && str_contains(SHOP_EMAIL, '@'))
    ? strtolower(substr(strrchr(SHOP_EMAIL, '@'), 1))
    : '';
$userHost = str_contains($cfg['user'], '@')
    ? strtolower(substr(strrchr($cfg['user'], '@'), 1))
    : '';

/**
 * Remove the credentials from PHPMailer's debug transcript.
 *
 * SMTPDebug level 2 prints the AUTH exchange verbatim, and the username and
 * password go over the wire base64-encoded — which is encoding, not secrecy.
 * Pasting an unscrubbed transcript into a support chat hands over the mailbox
 * password, so this runs on every line before any of it is displayed.
 */
function cbMailScrub(string $text, string $user, string $pass): string
{
    $secrets = array_filter([$pass, base64_encode($pass), $user, base64_encode($user)]);
    foreach ($secrets as $s) {
        if (strlen($s) > 3) {
            $text = str_replace($s, '[removed]', $text);
        }
    }
    // Belt and braces: after AUTH LOGIN the next client lines are the
    // credentials, and a password we failed to match above would still be
    // sitting there. Any lone base64 blob from the client goes too.
    return preg_replace(
        '/(CLIENT -> SERVER:\s*)[A-Za-z0-9+\/]{12,}={0,2}\s*$/m',
        '$1[removed]',
        $text
    ) ?? $text;
}

// ── Checks ──────────────────────────────────────────────────
//
// Ordered the way the send itself fails: nothing can work without PHPMailer,
// nothing can authenticate without a login, and nothing can connect at all if
// the host blocks the port.
$checks = [];
$add = function (string $what, ?bool $ok, string $detail, string $fix = '') use (&$checks) {
    $checks[] = ['what' => $what, 'ok' => $ok, 'detail' => $detail, 'fix' => $fix];
};

$add('PHPMailer', PHPMAILER_AVAILABLE,
    PHPMAILER_AVAILABLE ? 'loaded' : 'NOT FOUND — nothing can be sent at all',
    PHPMAILER_AVAILABLE ? '' : 'Upload the /PHPMailer/ folder to the site root. It is in the repository.');

$envFile = dirname(__DIR__, 2) . '/.env';
$secFile = dirname(__DIR__, 2) . '/includes/secrets.php';
$haveSecrets = is_readable($envFile) || is_readable($secFile);
$add('Where the settings come from', $haveSecrets,
    is_readable($secFile) ? 'includes/secrets.php' : (is_readable($envFile) ? '.env' : 'NEITHER .env NOR includes/secrets.php exists'),
    $haveSecrets ? '' : 'Copy .env.example to .env in the site root and fill it in. Without it there is no mail password.');

$add('Mail server', $cfg['host'] !== '', $cfg['host'] !== '' ? $cfg['host'] : 'EMPTY',
    $cfg['host'] !== '' ? '' : 'Set SMTP_HOST in .env.');

$add('Login', $cfg['user'] !== '', $cfg['user'] !== '' ? $cfg['user'] : 'EMPTY — nothing can be sent',
    $cfg['user'] !== '' ? '' : 'Set SMTP_USER in .env to the shop mailbox.');

$add('Password', $cfg['pass'] !== '',
    $cfg['pass'] !== '' ? 'set (' . strlen($cfg['pass']) . ' characters)' : 'EMPTY — every send will fail authentication',
    $cfg['pass'] !== '' ? '' : 'Set SMTP_PASS in .env to that mailbox\'s password, then restart PHP in hPanel.');

// Port and encryption travel together. 465 is implicit SSL; 587 upgrades with
// STARTTLS. Sending STARTTLS at a 465 listener hangs rather than failing
// cleanly, which is the failure this shop has already had once.
$enc = ($cfg['port'] === 465) ? 'SSL (implicit)' : 'STARTTLS';
$add('Port', in_array($cfg['port'], [465, 587, 25, 2525], true),
    $cfg['port'] . ' → ' . $enc,
    in_array($cfg['port'], [465, 587, 25, 2525], true) ? '' : 'Set SMTP_PORT to 465 for Hostinger.');

// The one that quietly breaks a working shop: config.php falls back to
// smtp.gmail.com when SMTP_HOST is unset, so a missing line in .env sends
// a Hostinger password to Google and gets a bare authentication failure.
// Checked against the shop's own domain rather than the login, so it still
// fires when SMTP_USER is blank — which is exactly the case where the whole
// block is a default nobody chose.
$hostIsGmail = str_contains(strtolower($cfg['host']), 'gmail');
if ($hostIsGmail && $shopHost !== '' && !str_contains($shopHost, 'gmail')) {
    $add('Host matches the login', false,
        $cfg['host'] . ' — almost certainly the fallback, not a setting',
        'config.php falls back to smtp.gmail.com whenever SMTP_HOST is missing, so this is what an absent or '
        . 'unread .env looks like. Mail for ' . $shopHost . ' does not go through Google'
        . ($userHost !== '' && !str_contains($userHost, 'gmail')
            ? ', and the login ' . $cfg['user'] . ' is not a Google account, so authentication cannot succeed'
            : '')
        . '. Set SMTP_HOST and SMTP_PORT explicitly in .env to your mail provider.');
}
if (str_contains(strtolower($cfg['host']), 'hostinger') && $cfg['port'] !== 465) {
    $add('Port suits the host', false,
        'Hostinger on port ' . $cfg['port'],
        'Hostinger expects 465. On 587 the connection opens and then hangs until it times out.');
}

// Not a reason mail fails to leave — a reason it lands in spam once it does.
if ($cfg['user'] !== '' && $shopHost !== '') {
    $ownDomain = ($userHost === $shopHost);
    $add('From address', $ownDomain,
        'customers see From: ' . $cfg['user'] . ($ownDomain ? ' — your own domain' : ' — NOT ' . $shopHost),
        $ownDomain ? '' : 'Your SPF record authorises ' . $shopHost . ' only. Mail sent from another domain '
            . 'is likely to be junked. Use a mailbox on ' . $shopHost . ' as SMTP_USER.');
}

// ── Can we even reach the mail server? ──────────────────────
//
// This is the check that saves the most time. Shared hosting commonly blocks
// outbound SMTP, and a blocked port looks exactly like a wrong password from
// the outside — both arrive as "could not send". Opening the socket here
// separates them before a single credential is tried.
$reach = null;
if ($cfg['host'] !== '' && $cfg['port'] > 0) {
    $t0 = microtime(true);
    $errNo = 0; $errStr = '';
    $sock = @stream_socket_client(
        ($cfg['port'] === 465 ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['port'],
        $errNo, $errStr, 6
    );
    $ms = (int)round((microtime(true) - $t0) * 1000);
    if ($sock) {
        stream_set_timeout($sock, 5);
        $banner = trim((string)@fgets($sock, 512));
        @fclose($sock);
        $reach = ['ok' => true, 'msg' => 'connected in ' . $ms . 'ms' . ($banner !== '' ? ' — ' . $banner : '')];
    } else {
        $reach = ['ok' => false, 'msg' => 'could not connect after ' . $ms . 'ms'
            . ($errStr !== '' ? ' — ' . $errStr : '') . ' (error ' . $errNo . ')'];
    }
    $add('Reaching the mail server', $reach['ok'], $reach['msg'],
        $reach['ok'] ? '' : 'The server cannot open a connection to ' . $cfg['host'] . ':' . $cfg['port'] . '. '
            . 'Either the port is wrong or this host blocks outgoing mail. Ask Hostinger support to confirm '
            . 'outbound SMTP on port ' . $cfg['port'] . ' is open for this account.');
}

// ── Send one, on request ────────────────────────────────────
$sent = null;
$transcript = '';
$sendTo = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    csrfCheck();
    $sendTo = trim((string)($_POST['to'] ?? ''));

    if (!filter_var($sendTo, FILTER_VALIDATE_EMAIL)) {
        $sent = ['ok' => false, 'msg' => 'That is not a valid email address.'];
    } elseif (!PHPMAILER_AVAILABLE) {
        $sent = ['ok' => false, 'msg' => 'PHPMailer is not loaded, so nothing can be sent.'];
    } elseif ($cfg['user'] === '') {
        // Without this PHPMailer reports "Invalid address: (From):", which is
        // true but tells nobody that the cause is an empty SMTP_USER.
        $sent = ['ok' => false, 'msg' => 'There is no SMTP_USER, so there is no address to send from. '
            . 'Set it in .env, then reload this page.'];
    } else {
        $mail = new PHPMailer(true);
        $buf  = '';
        try {
            cbMailTransport($mail);
            // The whole point of this page: keep the conversation instead of
            // throwing it away, so a failure can be read rather than guessed at.
            $mail->SMTPDebug   = 2;
            $mail->Debugoutput = function ($str) use (&$buf) { $buf .= rtrim($str) . "\n"; };
            $mail->Timeout     = 15;

            $mail->setFrom($cfg['user'], SHOP_NAME);
            $mail->addAddress($sendTo);
            $mail->isHTML(true);
            $mail->Subject = 'Creamy Bite test email — ' . date('j M Y H:i');
            $mail->Body    = '<p style="font-family:sans-serif;font-size:15px;">'
                           . 'This is a test from the Creamy Bite admin panel.</p>'
                           . '<p style="font-family:sans-serif;font-size:14px;color:#555;">'
                           . 'If you are reading this, outgoing email is working. Sent '
                           . $h(date('j M Y \a\t H:i')) . ' from ' . $h($cfg['user']) . '.</p>';
            $mail->AltBody = 'Test from the Creamy Bite admin panel. If you are reading this, outgoing email works.';
            $mail->send();
            $sent = ['ok' => true, 'msg' => 'Accepted by ' . $cfg['host'] . ' for delivery to ' . $sendTo . '.'];
        } catch (Throwable $e) {
            $sent = ['ok' => false, 'msg' => $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage()];
        }
        $transcript = cbMailScrub($buf, $cfg['user'], $cfg['pass']);
    }
}

$allOk = true;
foreach ($checks as $c) { if ($c['ok'] === false) { $allOk = false; break; } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Test</title>
    <?php require __DIR__ . '/../../includes/favicon.php'; ?>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/setup.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-wrapper su-page-warm">
<div class="su-wrap">
    <div class="glass-panel su-card">
        <h1 class="su-h1">✉️ Email Test</h1>
        <p class="su-lead">
            Why email is or is not going out. Nothing is sent until you press the
            button, and nothing on this page changes a setting.
        </p>

        <p class="su-env <?= IS_LOCAL ? 'su-env-local' : 'su-env-live' ?>">
            <?= IS_LOCAL ? '💻 LOCAL' : '🌍 LIVE' ?> &mdash; <?= $h(DB_NAME) ?>
        </p>

        <h2 class="su-h2">Settings in use</h2>
        <table class="su-table">
            <?php foreach ($checks as $c): ?>
            <tr class="su-row">
                <td class="su-cell-name"><?= $h($c['what']) ?></td>
                <td class="su-cell-state <?= $c['ok'] === false ? 'su-err' : 'su-ok' ?>">
                    <?= $c['ok'] === false ? '✗' : '✓' ?> <?= $h($c['detail']) ?>
                </td>
            </tr>
            <?php if ($c['fix'] !== ''): ?>
            <tr class="su-row"><td colspan="2" class="su-fixnote"><?= $h($c['fix']) ?></td></tr>
            <?php endif; ?>
            <?php endforeach; ?>
        </table>

        <?php if ($allOk): ?>
        <p class="su-result su-ok">
            Every setting looks right. If mail still does not arrive, send a test
            below — the answer will be in the transcript.
        </p>
        <?php else: ?>
        <p class="su-result su-err">
            Something above is wrong. Fix the ✗ rows first — a test send will only
            repeat the same failure.
        </p>
        <?php endif; ?>

        <h2 class="su-h2">Send a test</h2>
        <p class="su-lead">
            Sends one real message and shows the full conversation with the mail
            server, including the exact error if it fails.
        </p>
        <form method="POST" class="su-form-block">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="send">
            <p class="su-lead">
                <label for="mt_to">Send it to</label><br>
                <input id="mt_to" type="email" name="to" class="form-control su-input"
                       value="<?= $h($sendTo) ?>" required>
            </p>
            <button type="submit" class="btn-primary su-btn">Send test email</button>
        </form>

        <?php if ($sent !== null): ?>
        <h2 class="su-h2"><?= $sent['ok'] ? 'Sent' : 'That did not work' ?></h2>
        <p class="su-result <?= $sent['ok'] ? 'su-ok' : 'su-err' ?>">
            <?= $sent['ok'] ? '✅ ' : '❌ ' ?><?= $h($sent['msg']) ?>
        </p>
        <?php if ($sent['ok']): ?>
        <p class="su-lead">
            The mail server accepted it. If it is still not in the inbox in a few
            minutes, check the spam folder — at that point the problem is delivery,
            not this site.
        </p>
        <?php endif; ?>
        <?php if ($transcript !== ''): ?>
        <div class="su-log"><?php foreach (explode("\n", trim($transcript)) as $line): ?><div class="su-log-line<?=
            preg_match('/^(SMTP ERROR|SERVER -> CLIENT: [45])/i', $line) ? ' is-fatal' : '' ?>"><?=
            $h($line) ?></div><?php endforeach; ?></div>
        <p class="su-lead">
            The login and password have been removed from this transcript, so it is
            safe to copy if you need to send it to Hostinger support.
        </p>
        <?php endif; ?>
        <?php endif; ?>

        <h2 class="su-h2">Where each email goes</h2>
        <p class="su-lead">
            All of it is sent from the login above — that is what the From line
            follows, and it cannot be changed without failing SPF.
        </p>
        <table class="su-table">
            <tr class="su-row"><td class="su-cell-name">New order placed</td><td class="su-cell-mono"><?= $h(ADMIN_EMAIL) ?></td></tr>
            <tr class="su-row"><td class="su-cell-name">Order confirmation, receipt, delivery note</td><td class="su-cell-mono">the customer</td></tr>
            <tr class="su-row"><td class="su-cell-name">Contact form</td><td class="su-cell-mono"><?= $h(SHOP_EMAIL) ?></td></tr>
            <tr class="su-row"><td class="su-cell-name">Invoice</td><td class="su-cell-mono">the address on the invoice</td></tr>
        </table>

        <p><a class="btn-secondary su-btn-back" href="health_check.php">← Health check</a></p>
    </div>
</div>
</body>
</html>
