<?php
// ============================================================
//  Creamy Bite – Admin: Invoice actions
//
//  Everything that changes an invoice goes through here:
//    create_blank | create_from_order | save | add_payment |
//    delete_payment | set_status | duplicate | delete | save_settings
//
//  Plain form POSTs with a redirect rather than AJAX: an invoice edit is a
//  whole-document save, and a redirect after POST stops a refresh from
//  re-submitting it.
// ============================================================
require_once __DIR__ . '/../../includes/session.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../_guard.php';
csrfCheck();
require_once __DIR__ . '/../_permissions.php';
adminRequire('invoices');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/invoice.php';
require_once __DIR__ . '/../../includes/mailer.php';   // sendInvoiceEmail()

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/** Bounce back to the invoice list or a specific invoice with a message. */
function backTo(string $url, string $msg = '', string $type = 'ok'): void
{
    $_SESSION['invoice_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: ' . $url);
    exit;
}

try {
    switch ($action) {

        // ── Create an empty invoice and open it ──────────────
        case 'create_blank': {
            $id = createBlankInvoice($pdo);
            backTo('../invoice_edit.php?id=' . $id, 'New invoice created.');
        }

        // ── Raise an invoice from a placed order ─────────────
        case 'create_from_order': {
            $orderId = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
            if ($orderId <= 0) {
                backTo('../index.php?tab=invoices', 'No order selected.', 'error');
            }

            // Don't silently raise a second invoice for the same order.
            $existing = $pdo->prepare("SELECT id, invoice_number FROM invoices WHERE order_id = :o AND status <> 'void' LIMIT 1");
            $existing->execute(['o' => $orderId]);
            if ($row = $existing->fetch()) {
                backTo('../invoice_edit.php?id=' . (int)$row['id'],
                       'Order already has invoice ' . $row['invoice_number'] . '. Opened it instead.', 'warn');
            }

            $id = createInvoiceFromOrder($pdo, $orderId);

            // If the customer already paid for the order online, the invoice is
            // a receipt, not a demand — so it opens as PAID rather than making
            // someone notice and mark it by hand.
            $msg = 'Invoice raised from order.';
            if (invoiceSettleFromPaidOrder($pdo, $id)) {
                $msg = 'Invoice raised and marked PAID — the order was already paid online.';
            }
            syncInvoicePaymentState($pdo, $id);

            backTo('../invoice_edit.php?id=' . $id, $msg);
        }

        // ── Save the whole invoice, header and lines ─────────
        case 'save': {
            $id = (int)($_POST['invoice_id'] ?? 0);
            if ($id <= 0) {
                backTo('../index.php?tab=invoices', 'Invalid invoice.', 'error');
            }

            $issueDate = trim($_POST['issue_date'] ?? '') ?: date('Y-m-d');
            $dueDate   = trim($_POST['due_date'] ?? '');

            $pdo->prepare(
                "UPDATE invoices SET
                    issue_date = :issue_date, due_terms = :due_terms, due_date = :due_date,
                    currency = :currency,
                    from_name = :from_name, from_address = :from_address, from_phone = :from_phone,
                    from_email = :from_email, from_website = :from_website,
                    to_name = :to_name, to_address = :to_address, to_email = :to_email,
                    to_phone = :to_phone, to_vat_number = :to_vat_number,
                    payment_instructions = :payment_instructions, notes = :notes,
                    discount_type = :discount_type, discount_value = :discount_value,
                    delivery = :delivery, vat_rate = :vat_rate,
                    sales_rep_id = :sales_rep_id, commission_percent = :commission_percent
                 WHERE id = :id"
            )->execute([
                'issue_date'           => $issueDate,
                'due_terms'            => trim($_POST['due_terms'] ?? 'On Receipt'),
                'due_date'             => $dueDate !== '' ? $dueDate : null,
                // The shop bills in pounds only, so there is no currency field
                // on the form any more and nothing to read from the request.
                'currency'             => 'GBP',
                'from_name'            => trim($_POST['from_name'] ?? ''),
                'from_address'         => trim($_POST['from_address'] ?? ''),
                'from_phone'           => trim($_POST['from_phone'] ?? ''),
                'from_email'           => trim($_POST['from_email'] ?? ''),
                'from_website'         => trim($_POST['from_website'] ?? ''),
                'to_name'              => trim($_POST['to_name'] ?? ''),
                'to_address'           => trim($_POST['to_address'] ?? ''),
                'to_email'             => trim($_POST['to_email'] ?? ''),
                'to_phone'             => trim($_POST['to_phone'] ?? ''),
                'to_vat_number'        => trim($_POST['to_vat_number'] ?? ''),
                'payment_instructions' => trim($_POST['payment_instructions'] ?? ''),
                'notes'                => trim($_POST['notes'] ?? ''),
                // The cash figure in `discount` is derived by recalcInvoice()
                // from the type + value, so only those two are stored here.
                'discount_type'        => (($_POST['discount_type'] ?? 'fixed') === 'percent') ? 'percent' : 'fixed',
                'discount_value'       => max(0, round((float)($_POST['discount_value'] ?? 0), 2)),
                'delivery'             => round((float)($_POST['delivery'] ?? 0), 2),
                'vat_rate'             => round((float)($_POST['vat_rate'] ?? 0) / 100, 4),
                'sales_rep_id'         => max(0, (int)($_POST['sales_rep_id'] ?? 0)),
                // Clamped, not merely cast: the agreed band is 2–20%, and a
                // stray 200 typed into the box would otherwise be stored and
                // quietly inflate every commission report from then on. 0 is
                // kept as a valid value meaning "no commission on this one".
                'commission_percent'   => (function (): float {
                    $pct = round((float)($_POST['commission_percent'] ?? 0), 2);
                    if ($pct <= 0) { return 0.0; }
                    return max(2.0, min(20.0, $pct));
                })(),
                'id'                   => $id,
            ]);

            // Replace the lines wholesale. Simpler and safer than diffing:
            // the form always posts the complete, current set.
            $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = :id")->execute(['id' => $id]);

            $descs = $_POST['item_description'] ?? [];
            $rates = $_POST['item_rate']        ?? [];
            $notes = $_POST['item_rate_note']   ?? [];
            $qtys  = $_POST['item_qty']         ?? [];
            $units = $_POST['item_qty_unit']    ?? [];

            $ins = $pdo->prepare(
                "INSERT INTO invoice_items (invoice_id, description, rate, rate_note, qty, qty_unit, amount, sort_order)
                 VALUES (:inv, :desc, :rate, :note, :qty, :unit, :amount, :sort)"
            );

            $sort = 0;
            foreach ($descs as $i => $desc) {
                $desc = trim((string)$desc);
                if ($desc === '') {
                    continue;   // skip blank rows the user left behind
                }
                $rate = round((float)($rates[$i] ?? 0), 2);
                $qty  = round((float)($qtys[$i] ?? 0), 3);
                $ins->execute([
                    'inv'    => $id,
                    'desc'   => $desc,
                    'rate'   => $rate,
                    'note'   => trim((string)($notes[$i] ?? '')),
                    'qty'    => $qty,
                    'unit'   => trim((string)($units[$i] ?? '')),
                    'amount' => round($rate * $qty, 2),
                    'sort'   => ++$sort,
                ]);
            }

            recalcInvoice($pdo, $id);

            // An invoice raised from an order the customer has already settled
            // should not sit there saying UNPAID. Record the payment against it
            // once, then let the normal sync decide the status — so the balance
            // is right rather than the label merely being overwritten.
            $flash = 'Invoice saved.';
            if (invoiceSettleFromPaidOrder($pdo, $id)) {
                $flash = 'Invoice saved and marked PAID — the order it came from was already paid.';
            }
            syncInvoicePaymentState($pdo, $id);

            backTo('../invoice_edit.php?id=' . $id, $flash);
        }

        // ── Record a payment ─────────────────────────────────
        case 'add_payment': {
            $id     = (int)($_POST['invoice_id'] ?? 0);
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            if ($id <= 0 || $amount <= 0) {
                backTo('../invoice_edit.php?id=' . $id, 'Enter a payment amount greater than zero.', 'error');
            }

            $pdo->prepare(
                "INSERT INTO invoice_payments (invoice_id, paid_on, amount, method, reference)
                 VALUES (:id, :on, :amt, :m, :r)"
            )->execute([
                'id'  => $id,
                'on'  => trim($_POST['paid_on'] ?? '') ?: date('Y-m-d'),
                'amt' => $amount,
                'm'   => trim($_POST['method'] ?? 'Bank Transfer'),
                'r'   => trim($_POST['reference'] ?? ''),
            ]);

            syncInvoicePaymentState($pdo, $id);
            backTo('../invoice_edit.php?id=' . $id, 'Payment recorded.');
        }

        case 'delete_payment': {
            $id  = (int)($_POST['invoice_id'] ?? 0);
            $pid = (int)($_POST['payment_id'] ?? 0);
            $pdo->prepare("DELETE FROM invoice_payments WHERE id = :p AND invoice_id = :i")
                ->execute(['p' => $pid, 'i' => $id]);
            syncInvoicePaymentState($pdo, $id);
            backTo('../invoice_edit.php?id=' . $id, 'Payment removed.');
        }

        // ── Status changes ───────────────────────────────────
        case 'set_status': {
            $id     = (int)($_POST['invoice_id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            if (!in_array($status, ['draft', 'sent', 'void'], true)) {
                backTo('../invoice_edit.php?id=' . $id, 'Unknown status.', 'error');
            }
            $pdo->prepare("UPDATE invoices SET status = :s WHERE id = :id")
                ->execute(['s' => $status, 'id' => $id]);
            // Voiding must not be undone by the payment sync.
            if ($status !== 'void') {
                syncInvoicePaymentState($pdo, $id);
            }
            backTo('../invoice_edit.php?id=' . $id, 'Invoice marked ' . strtoupper($status) . '.');
        }

        // ── Duplicate (credit notes, repeat billing) ─────────
        case 'duplicate': {
            $id  = (int)($_POST['invoice_id'] ?? $_GET['invoice_id'] ?? 0);
            $src = loadInvoice($pdo, $id);
            if (!$src) {
                backTo('../index.php?tab=invoices', 'Invoice not found.', 'error');
            }

            $newId = createBlankInvoice($pdo, [
                'order_id'             => null,   // a copy is its own document
                'trade_user_id'        => (int)$src['trade_user_id'],
                'issue_date'           => date('Y-m-d'),
                'due_terms'            => $src['due_terms'],
                'currency'             => $src['currency'],
                'from_name'            => $src['from_name'],
                'from_address'         => $src['from_address'],
                'from_phone'           => $src['from_phone'],
                'from_email'           => $src['from_email'],
                'from_website'         => $src['from_website'],
                'to_name'              => $src['to_name'],
                'to_address'           => $src['to_address'],
                'to_email'             => $src['to_email'],
                'to_phone'             => $src['to_phone'],
                'to_vat_number'        => $src['to_vat_number'],
                'payment_instructions' => $src['payment_instructions'],
                'notes'                => $src['notes'],
                'vat_rate'             => $src['vat_rate'],
            ]);

            $pdo->prepare("UPDATE invoices SET discount = :d, delivery = :del WHERE id = :id")
                ->execute(['d' => $src['discount'], 'del' => $src['delivery'], 'id' => $newId]);

            $ins = $pdo->prepare(
                "INSERT INTO invoice_items (invoice_id, description, rate, rate_note, qty, qty_unit, amount, sort_order)
                 VALUES (:inv, :desc, :rate, :note, :qty, :unit, :amount, :sort)"
            );
            foreach ($src['items'] as $it) {
                $ins->execute([
                    'inv'    => $newId,
                    'desc'   => $it['description'],
                    'rate'   => $it['rate'],
                    'note'   => $it['rate_note'],
                    'qty'    => $it['qty'],
                    'unit'   => $it['qty_unit'],
                    'amount' => $it['amount'],
                    'sort'   => $it['sort_order'],
                ]);
            }

            recalcInvoice($pdo, $newId);
            backTo('../invoice_edit.php?id=' . $newId, 'Duplicated from ' . $src['invoice_number'] . '.');
        }

        // ── Delete ───────────────────────────────────────────
        // Only drafts can be deleted. An issued invoice must be voided so the
        // number is never reused and the sequence stays gapless for accounting.
        case 'delete': {
            $id  = (int)($_POST['invoice_id'] ?? 0);
            $chk = $pdo->prepare("SELECT status, invoice_number FROM invoices WHERE id = :id");
            $chk->execute(['id' => $id]);
            $row = $chk->fetch();

            if (!$row) {
                backTo('../index.php?tab=invoices', 'Invoice not found.', 'error');
            }
            if ($row['status'] !== 'draft') {
                backTo('../invoice_edit.php?id=' . $id,
                       'Only drafts can be deleted. Void ' . $row['invoice_number'] . ' instead, so the number is not reused.',
                       'error');
            }

            $pdo->prepare("DELETE FROM invoices WHERE id = :id")->execute(['id' => $id]);
            backTo('../index.php?tab=invoices', 'Draft ' . $row['invoice_number'] . ' deleted.');
        }

        // ── Shop-wide invoice defaults ───────────────────────
        case 'save_settings': {
            $pdo->prepare(
                "UPDATE invoice_settings SET
                    number_prefix = :p, number_padding = :pad, next_number = :n,
                    from_name = :fn, from_address = :fa, from_phone = :fp,
                    from_email = :fe, from_website = :fw,
                    payment_instructions = :pi, default_terms = :dt, default_vat_rate = :vr
                 WHERE id = 1"
            )->execute([
                'p'   => trim($_POST['number_prefix'] ?? 'INV'),
                'pad' => max(1, (int)($_POST['number_padding'] ?? 4)),
                'n'   => max(1, (int)($_POST['next_number'] ?? 1)),
                'fn'  => trim($_POST['from_name'] ?? ''),
                'fa'  => trim($_POST['from_address'] ?? ''),
                'fp'  => trim($_POST['from_phone'] ?? ''),
                'fe'  => trim($_POST['from_email'] ?? ''),
                'fw'  => trim($_POST['from_website'] ?? ''),
                'pi'  => trim($_POST['payment_instructions'] ?? ''),
                'dt'  => trim($_POST['default_terms'] ?? 'On Receipt'),
                'vr'  => round((float)($_POST['default_vat_rate'] ?? 0) / 100, 4),
            ]);
            backTo('../index.php?tab=invoices', 'Invoice settings saved.');
        }

        // ── Email the invoice to the customer ────────────────
        case 'send_email': {
            $id  = (int)($_POST['invoice_id'] ?? 0);
            $inv = loadInvoice($pdo, $id);
            if (!$inv) {
                backTo('../index.php?tab=invoices', 'Invoice not found.', 'error');
            }
            if ($inv['status'] === 'void') {
                backTo('../invoice_edit.php?id=' . $id, 'This invoice is void — reopen it as a draft first.', 'error');
            }

            $to = trim((string)$inv['to_email']);
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                backTo('../invoice_edit.php?id=' . $id,
                       'No valid email address on this invoice. Add one in the Bill To panel, then send.', 'error');
            }

            // A draft becomes sent the moment it actually goes out, so the
            // status reflects what happened rather than needing a second click.
            if ($inv['status'] === 'draft') {
                $pdo->prepare("UPDATE invoices SET status = 'sent' WHERE id = :id")->execute(['id' => $id]);
                $inv['status'] = 'sent';
            }
            $pdo->prepare("UPDATE invoices SET sent_at = NOW() WHERE id = :id")->execute(['id' => $id]);

            $link = invoicePublicUrl($pdo, $inv);
            if (sendInvoiceEmail($inv, $link)) {
                backTo('../invoice_edit.php?id=' . $id, 'Invoice emailed to ' . $to . '.');
            }
            backTo('../invoice_edit.php?id=' . $id,
                   'Could not send the email. The invoice is marked as sent — check the mail settings.', 'warn');
        }

        // ── Shared by WhatsApp: record it, answer with JSON ──
        // Called by fetch from the invoice editor as the chat opens, so it must
        // NOT redirect: a redirect response is meaningless here, and the old
        // form-submit version cancelled the very window the click had opened.
        case 'mark_shared': {
            header('Content-Type: application/json');
            $id  = (int)($_POST['invoice_id'] ?? 0);
            $inv = loadInvoice($pdo, $id);
            if (!$inv || $inv['status'] === 'void') {
                echo json_encode(['success' => false]);
                exit;
            }
            if ($inv['status'] === 'draft') {
                $pdo->prepare("UPDATE invoices SET status = 'sent' WHERE id = :id")->execute(['id' => $id]);
            }
            $pdo->prepare("UPDATE invoices SET sent_at = NOW() WHERE id = :id")->execute(['id' => $id]);
            echo json_encode(['success' => true]);
            exit;
        }

        // ── Sales reps / agents ──────────────────────────────
        case 'add_rep': {
            $name = trim($_POST['rep_name'] ?? '');
            if ($name === '') {
                backTo('../index.php?tab=invoices', 'Enter the rep or agent name.', 'error');
            }
            try {
                $pdo->prepare(
                    "INSERT INTO sales_reps (name, phone, email) VALUES (:n, :p, :e)"
                )->execute([
                    'n' => $name,
                    'p' => trim($_POST['rep_phone'] ?? ''),
                    'e' => trim($_POST['rep_email'] ?? ''),
                ]);
                backTo('../index.php?tab=invoices', $name . ' added.');
            } catch (PDOException $e) {
                // The name is unique so the same person cannot end up on the
                // list twice with their sales split between the copies.
                $dup = str_contains($e->getMessage(), 'Duplicate');
                backTo('../index.php?tab=invoices',
                       $dup ? $name . ' is already on the list.' : 'Could not add that rep.',
                       'error');
            }
        }

        case 'toggle_rep': {
            $repId = (int)($_POST['rep_id'] ?? $_GET['rep_id'] ?? 0);
            // Deactivated rather than deleted: their name still has to render
            // on the invoices they already sold.
            $pdo->prepare("UPDATE sales_reps SET active = 1 - active WHERE id = :id")
                ->execute(['id' => $repId]);
            backTo('../index.php?tab=invoices', 'Rep updated.');
        }

        default:
            backTo('../index.php?tab=invoices', 'Unknown action.', 'error');
    }

} catch (Throwable $e) {
    error_log('Invoice action failed: ' . $e->getMessage());
    backTo('../index.php?tab=invoices', 'Could not complete that: ' . $e->getMessage(), 'error');
}
