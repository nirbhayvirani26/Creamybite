<?php
// ============================================================
//  Creamy Bite – Admin: the trade half of "Are you taking orders?"
//
//  Actions: toggle | save_note
//
//  A plain form POST followed by a redirect, the same shape as
//  invoice_handler.php. The two original switches on that page use the
//  page's own JavaScript; these two do not need it, and a form works with
//  scripting off and cannot get out of step with what is on screen.
// ============================================================
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../_guard.php';
csrfCheck();
require_once __DIR__ . '/../_permissions.php';
adminRequire('store');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/store_ordering_trade.php';

/** Back to the switches with a message. */
function tradeOrderingBack(string $msg = '', string $type = 'ok'): void
{
    $_SESSION['trade_ordering_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: ../store.php#s-open');
    exit;
}

$action = $_POST['action'] ?? '';
$method = trim($_POST['method'] ?? '');

if (!in_array($method, CB_TRADE_ORDER_METHODS, true)) {
    tradeOrderingBack('That is not a way of ordering we know about.', 'error');
}

$word = $method === 'delivery' ? 'delivery' : 'collection';

try {
    switch ($action) {

        // ── Start or stop taking this kind of trade order ────
        case 'toggle': {
            $open = !cbTradeOrderingOpen($pdo, $method);

            if (!cbSetTradeOrdering($pdo, $method, $open)) {
                tradeOrderingBack(
                    'Could not save that. The trade columns are not on this database yet — '
                    . 'open this page again and it will try to add them.',
                    'error'
                );
            }

            tradeOrderingBack($open
                ? 'Now taking trade ' . $word . ' orders again.'
                : 'Stopped taking trade ' . $word . ' orders.');
        }

        // ── Save what trade customers are told while it is off ──
        case 'save_note': {
            if (!cbSetTradeOrderingNote($pdo, $method, (string)($_POST['note'] ?? ''))) {
                tradeOrderingBack('Could not save that message.', 'error');
            }
            tradeOrderingBack('Saved what trade customers are told about ' . $word . '.');
        }

        default:
            tradeOrderingBack('Unknown action.', 'error');
    }
} catch (Throwable $e) {
    error_log('Trade ordering action failed: ' . $e->getMessage());
    tradeOrderingBack('Could not complete that: ' . $e->getMessage(), 'error');
}
