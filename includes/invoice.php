<?php
// ============================================================
//  Creamy Bite – Invoicing model
//
//  Design notes
//  ------------
//  * An invoice is a SNAPSHOT. Bill-from, bill-to, rates and totals are all
//    stored on the invoice itself, never recomputed from the order or the
//    shop settings at display time. Changing the shop address next year must
//    not silently rewrite last year's invoices.
//  * Totals are recalculated from the line items whenever items change, so a
//    hand-edited line can never leave the footer disagreeing with the table.
//  * Balance due = total - sum(payments). Part payments are first-class.
//
//  Requires config.php and a $pdo from db.php.
// ============================================================

require_once __DIR__ . '/config.php';

/** The single settings row, creating it if somehow absent. */
function invoiceSettings(PDO $pdo): array
{
    $row = $pdo->query("SELECT * FROM invoice_settings WHERE id = 1")->fetch();
    if ($row) {
        return $row;
    }
    $pdo->exec("INSERT INTO invoice_settings (id, from_address, payment_instructions) VALUES (1, '', '')");
    return $pdo->query("SELECT * FROM invoice_settings WHERE id = 1")->fetch();
}

/**
 * Reserve the next invoice number, e.g. INV0226.
 *
 * The UPDATE ... then SELECT runs inside the caller's transaction where one
 * exists; the unique index on invoice_number is the real backstop against two
 * invoices claiming the same number.
 */
function nextInvoiceNumber(PDO $pdo): string
{
    $pdo->exec("UPDATE invoice_settings SET next_number = next_number + 1 WHERE id = 1");
    $s = invoiceSettings($pdo);
    $used = max(1, (int)$s['next_number'] - 1);
    return $s['number_prefix'] . str_pad((string)$used, (int)$s['number_padding'], '0', STR_PAD_LEFT);
}

/** Blank invoice pre-filled from the shop settings. Returns the new id. */
function createBlankInvoice(PDO $pdo, array $overrides = []): int
{
    $s = invoiceSettings($pdo);

    $data = array_merge([
        'invoice_number'       => nextInvoiceNumber($pdo),
        'order_id'             => null,
        'trade_user_id'        => 0,
        'status'               => 'draft',
        'issue_date'           => date('Y-m-d'),
        'due_terms'            => $s['default_terms'],
        // Three weeks is the house payment term, so a new invoice arrives with
        // a due date already on it. It stays editable — this is a starting
        // point, not a rule.
        'due_date'             => date('Y-m-d', strtotime('+3 weeks')),
        'currency'             => 'GBP',
        'from_name'            => $s['from_name'],
        'from_address'         => $s['from_address'],
        'from_phone'           => $s['from_phone'],
        'from_email'           => $s['from_email'],
        'from_website'         => $s['from_website'],
        'to_name'              => '',
        'to_address'           => '',
        'to_email'             => '',
        'to_phone'             => '',
        'to_vat_number'        => '',
        'payment_instructions' => $s['payment_instructions'],
        'notes'                => '',
        'vat_rate'             => $s['default_vat_rate'],
    ], $overrides);

    $cols = array_keys($data);
    $sql  = "INSERT INTO invoices (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ")
             VALUES (" . implode(',', array_map(fn($c) => ":$c", $cols)) . ")";
    $pdo->prepare($sql)->execute($data);

    return (int)$pdo->lastInsertId();
}

/**
 * Raise an invoice from a placed order, copying its lines across.
 *
 * Descriptions follow the house style seen on the real invoices: the product
 * name with the variant appended, e.g. "American Dry Fruit 500ml".
 */
function createInvoiceFromOrder(PDO $pdo, int $orderId): int
{
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id");
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }

    // Prefer the trade account's registered details for the bill-to block.
    $toName    = trim((string)($order['trade_business_name'] ?? '')) ?: $order['customer_name'];
    $toAddress = (string)$order['address'];
    $toVat     = (string)($order['vat_number'] ?? '');
    if ((int)$order['trade_user_id'] > 0) {
        $t = $pdo->prepare("SELECT * FROM trade_users WHERE id = :id");
        $t->execute(['id' => (int)$order['trade_user_id']]);
        if ($tu = $t->fetch()) {
            $toName    = $tu['business_name'];
            $toAddress = rtrim($tu['address']) . "\n" . $tu['postcode'];
            $toVat     = $tu['vat_number'];
        }
    }

    $vatRate = 0.0;
    if ((float)($order['vat_amount'] ?? 0) > 0) {
        $vatRate = TRADE_VAT_RATE;
    }

    $invoiceId = createBlankInvoice($pdo, [
        'order_id'      => $orderId,
        'trade_user_id' => (int)$order['trade_user_id'],
        'issue_date'    => date('Y-m-d', strtotime($order['created_at'])),
        'to_name'       => $toName,
        'to_address'    => $toAddress,
        'to_email'      => (string)($order['customer_email'] ?? ''),
        'to_phone'      => (string)($order['phone'] ?? ''),
        'to_vat_number' => $toVat,
        'vat_rate'      => $vatRate,
    ]);

    $items = json_decode($order['items_json'] ?? '', true) ?? [];
    $ins = $pdo->prepare(
        "INSERT INTO invoice_items (invoice_id, description, rate, qty, qty_unit, amount, sort_order)
         VALUES (:inv, :desc, :rate, :qty, :unit, :amount, :sort)"
    );

    $sort = 0;
    foreach ($items as $it) {
        $desc = trim((string)($it['name'] ?? 'Item'));
        if (!empty($it['variant_name'])) {
            $desc .= ' ' . $it['variant_name'];
        }
        $rate = (float)($it['price'] ?? 0);
        $qty  = (float)($it['quantity'] ?? 0);
        $ins->execute([
            'inv'    => $invoiceId,
            'desc'   => $desc,
            'rate'   => $rate,
            'qty'    => $qty,
            'unit'   => '',
            'amount' => round($rate * $qty, 2),
            'sort'   => ++$sort,
        ]);
    }

    // Carry the order's own discount and delivery onto the invoice.
    $pdo->prepare("UPDATE invoices SET discount = :d, delivery = :del WHERE id = :id")
        ->execute([
            'd'   => (float)($order['discount_amount'] ?? 0),
            'del' => (float)($order['delivery_charge'] ?? 0),
            'id'  => $invoiceId,
        ]);

    recalcInvoice($pdo, $invoiceId);
    return $invoiceId;
}

/**
 * Recompute every line amount, the subtotal, VAT and total from the stored items.
 * Called after any edit so the footer can never drift from the table.
 */
function recalcInvoice(PDO $pdo, int $invoiceId): void
{
    $items = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = :id");
    $items->execute(['id' => $invoiceId]);

    $subtotal = 0.0;
    $upd = $pdo->prepare("UPDATE invoice_items SET amount = :amt WHERE id = :id");
    foreach ($items->fetchAll() as $it) {
        $amount = round((float)$it['rate'] * (float)$it['qty'], 2);
        $upd->execute(['amt' => $amount, 'id' => (int)$it['id']]);
        $subtotal += $amount;
    }
    $subtotal = round($subtotal, 2);

    $inv = $pdo->prepare("SELECT discount, discount_type, discount_value, delivery, vat_rate FROM invoices WHERE id = :id");
    $inv->execute(['id' => $invoiceId]);
    $row = $inv->fetch() ?: ['discount' => 0, 'discount_type' => 'fixed', 'discount_value' => 0, 'delivery' => 0, 'vat_rate' => 0];

    // A percentage discount is recalculated against the CURRENT subtotal, so
    // editing a line keeps "10% off" meaning 10% rather than freezing the
    // cash figure it produced when it was first entered.
    $discount = ($row['discount_type'] === 'percent')
        ? round($subtotal * ((float)$row['discount_value'] / 100), 2)
        : (float)$row['discount_value'];
    $discount = max(0.0, min($discount, $subtotal));

    $base  = max(0.0, round($subtotal - $discount + (float)$row['delivery'], 2));
    $vat   = round($base * (float)$row['vat_rate'], 2);
    $total = round($base + $vat, 2);

    $pdo->prepare(
        "UPDATE invoices SET subtotal = :sub, discount = :dis, vat_amount = :vat, total = :tot WHERE id = :id"
    )->execute([
        'sub' => $subtotal,
        'dis' => $discount,
        'vat' => $vat,
        'tot' => $total,
        'id'  => $invoiceId,
    ]);

    syncInvoicePaymentState($pdo, $invoiceId);
}

/** Recompute amount_paid from the payments table and move the status with it. */
function syncInvoicePaymentState(PDO $pdo, int $invoiceId): void
{
    $p = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id = :id");
    $p->execute(['id' => $invoiceId]);
    $paid = round((float)$p->fetchColumn(), 2);

    $i = $pdo->prepare("SELECT total, status FROM invoices WHERE id = :id");
    $i->execute(['id' => $invoiceId]);
    $inv = $i->fetch();
    if (!$inv) {
        return;
    }

    $total  = (float)$inv['total'];
    $status = $inv['status'];

    // A voided invoice stays voided; a draft stays a draft until it is sent.
    if ($status !== 'void') {
        if ($paid <= 0) {
            $status = ($status === 'draft') ? 'draft' : 'sent';
        } elseif ($paid + 0.001 < $total) {
            $status = 'part_paid';
        } else {
            $status = 'paid';
        }
    }

    $pdo->prepare("UPDATE invoices SET amount_paid = :paid, status = :st WHERE id = :id")
        ->execute(['paid' => $paid, 'st' => $status, 'id' => $invoiceId]);
}

/**
 * Every sales rep / agent, newest-active first. Empty when the table has not
 * been migrated yet, so the invoice editor degrades to "no reps" rather than
 * dying on a server whose schema is behind.
 */
function invoiceSalesReps(PDO $pdo, bool $activeOnly = false): array
{
    try {
        $sql = "SELECT id, name, phone, email, active FROM sales_reps";
        if ($activeOnly) {
            $sql .= " WHERE active = 1";
        }
        $sql .= " ORDER BY active DESC, name ASC";
        return $pdo->query($sql)->fetchAll();
    } catch (PDOException $e) {
        error_log('invoiceSalesReps failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Commission earned on one invoice.
 *
 * Charged on the goods, never on the VAT: the VAT is the government's money
 * passing through, so paying a percentage of it would come straight out of
 * the shop's margin. Delivery is included because it is part of what the rep
 * sold. Returns 0 when no rate is set.
 */
function invoiceCommission(array $inv): float
{
    $pct = (float)($inv['commission_percent'] ?? 0);
    if ($pct <= 0) {
        return 0.0;
    }
    $exVat = (float)($inv['total'] ?? 0) - (float)($inv['vat_amount'] ?? 0);
    if ($exVat <= 0) {
        return 0.0;
    }
    return round($exVat * $pct / 100, 2);
}

/**
 * Settle an invoice whose source order was already paid.
 *
 * Raising an invoice from an order the customer paid at checkout used to leave
 * it reading UNPAID, so the invoice list showed money outstanding that had in
 * fact been received. This records the payment once, against the order's own
 * method, and leaves the status to syncInvoicePaymentState().
 *
 * Returns true only when it actually added a payment, so the caller can say so.
 * Deliberately does nothing for void invoices, invoices not raised from an
 * order, orders still unpaid, and invoices that already have a payment
 * recorded — re-saving must not keep stacking up duplicate payments.
 */
function invoiceSettleFromPaidOrder(PDO $pdo, int $invoiceId): bool
{
    try {
        $q = $pdo->prepare(
            "SELECT i.total, i.status, i.order_id, o.payment_status, o.payment_method
               FROM invoices i
               JOIN orders o ON o.id = i.order_id
              WHERE i.id = :id"
        );
        $q->execute(['id' => $invoiceId]);
        $row = $q->fetch();

        if (!$row || $row['status'] === 'void') {
            return false;
        }
        // 'Unpaid' is the only state that means no money has been taken.
        if (($row['payment_status'] ?? 'Unpaid') === 'Unpaid') {
            return false;
        }

        $total = round((float)$row['total'], 2);
        if ($total <= 0) {
            return false;
        }

        $existing = $pdo->prepare("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = :id");
        $existing->execute(['id' => $invoiceId]);
        if ((int)$existing->fetchColumn() > 0) {
            return false;
        }

        $method = match (true) {
            ($row['payment_method'] ?? '') === 'cash' || $row['payment_status'] === 'Cash' => 'Cash',
            $row['payment_status'] === 'Bank' => 'Bank Transfer',
            default => 'Card (online)',
        };

        $pdo->prepare(
            "INSERT INTO invoice_payments (invoice_id, paid_on, amount, method, reference)
             VALUES (:inv, :on, :amt, :method, :ref)"
        )->execute([
            'inv'    => $invoiceId,
            'on'     => date('Y-m-d'),
            'amt'    => $total,
            'method' => $method,
            'ref'    => 'Paid with the original order',
        ]);

        return true;
    } catch (PDOException $e) {
        error_log('invoiceSettleFromPaidOrder failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * The customer-facing link for an invoice, minting a token on first use.
 *
 * The token is what protects the document, so it is long and random. Using
 * the invoice id would let anyone change a number in the address bar and read
 * every invoice the shop has ever issued.
 *
 * Returns '' when SITE_URL is not configured — a half-built link in an email
 * is worse than no link at all.
 */
function invoicePublicUrl(PDO $pdo, array $inv): string
{
    if (!defined('SITE_URL') || SITE_URL === '') {
        return '';
    }

    $token = invoicePublicToken($pdo, $inv);
    if ($token === '') {
        return '';
    }

    return rtrim(SITE_URL, '/') . '/invoice.php?t=' . urlencode($token);
}

/**
 * The invoice's public token, minting one the first time it is asked for.
 *
 * Split out of invoicePublicUrl() so a caller that needs a link relative to
 * the current host — the partner's own account page — can still get the token
 * without being handed an absolute SITE_URL address.
 *
 * Minting on demand is the point. The token is not created when an invoice is
 * raised, or when it is marked sent; it appears the first time something asks
 * for the customer's link, which in practice means emailing or sharing it. An
 * invoice the shop raised and marked paid without ever emailing therefore has
 * no token at all, and any page that read the column directly and gave up when
 * it was empty showed the customer no way to open their own invoice.
 */
function invoicePublicToken(PDO $pdo, array $inv): string
{
    $token = trim((string)($inv['public_token'] ?? ''));
    if ($token !== '') {
        return $token;
    }

    $id = (int)($inv['id'] ?? 0);
    if ($id <= 0) {
        return '';
    }

    try {
        $token = bin2hex(random_bytes(24));
        $pdo->prepare("UPDATE invoices SET public_token = :t WHERE id = :id")
            ->execute(['t' => $token, 'id' => $id]);
    } catch (Throwable $e) {
        error_log('invoicePublicToken failed for invoice ' . $id . ': ' . $e->getMessage());
        return '';
    }

    return $token;
}

/**
 * A phone number in the form wa.me needs: digits only, country code included.
 *
 * UK numbers are stored the way people write them — "07823 606928",
 * "+44 7823 606928" — and wa.me accepts neither. A leading 0 is the national
 * trunk prefix and has to become 44, or WhatsApp opens a chat with nobody.
 */
function invoiceWhatsAppNumber(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if ($digits === '') {
        return '';
    }
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    if (str_starts_with($digits, '0')) {
        $digits = '44' . substr($digits, 1);      // UK national -> international
    }
    if (strlen($digits) < 10 || strlen($digits) > 15) {
        return '';                                 // not a phone number
    }
    return $digits;
}

/** Full invoice with its items and payments. Null when not found. */
function loadInvoice(PDO $pdo, int $invoiceId): ?array
{
    $s = $pdo->prepare("SELECT * FROM invoices WHERE id = :id");
    $s->execute(['id' => $invoiceId]);
    $inv = $s->fetch();
    if (!$inv) {
        return null;
    }

    $i = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = :id ORDER BY sort_order ASC, id ASC");
    $i->execute(['id' => $invoiceId]);
    $inv['items'] = $i->fetchAll();

    $p = $pdo->prepare("SELECT * FROM invoice_payments WHERE invoice_id = :id ORDER BY paid_on ASC, id ASC");
    $p->execute(['id' => $invoiceId]);
    $inv['payments'] = $p->fetchAll();

    $inv['balance_due'] = round((float)$inv['total'] - (float)$inv['amount_paid'], 2);
    return $inv;
}

/**
 * "Trade £3.50  ·  Retail £5.00" for one picker entry.
 *
 * Both prices are shown, not just the one this invoice will use, so whoever
 * is typing a rate can see the price they are NOT charging and catch a
 * trade-priced line on a retail invoice (or the reverse) before it reaches
 * the customer. Products with no separate wholesale price show one figure.
 */
function invoicePriceLabel(array $po): string
{
    $retail = (float)($po['retail'] ?? 0);
    $whole  = (float)($po['wholesale'] ?? 0);

    if ($whole > 0 && abs($whole - $retail) > 0.005) {
        return 'Trade £' . number_format($whole, 2) . '  ·  Retail £' . number_format($retail, 2);
    }
    return '£' . number_format($retail, 2);
}

/**
 * Every sellable line for the invoice product picker: each product, plus one
 * entry per variant. Trade invoices get wholesale pricing where it is set.
 *
 * @return array{label:string,price:float,retail:float,wholesale:float}[]
 */
function invoiceProductOptions(PDO $pdo, bool $tradePricing = false): array
{
    $out = [];
    try {
        $products = $pdo->query(
            "SELECT id, name, price, wholesale_price FROM products ORDER BY name ASC"
        )->fetchAll();

        $vStmt = $pdo->prepare(
            "SELECT name, price, wholesale_price FROM product_variants
              WHERE product_id = :pid ORDER BY sort_order ASC, id ASC"
        );

        foreach ($products as $p) {
            $vStmt->execute(['pid' => (int)$p['id']]);
            $variants = $vStmt->fetchAll();

            if ($variants) {
                // A product with sizes is only ever sold as a size, so list
                // the variants rather than the bare product.
                foreach ($variants as $v) {
                    $retail = (float)$v['price'];
                    $whole  = (float)$v['wholesale_price'];
                    $out[] = [
                        'label'     => $p['name'] . ' ' . $v['name'],
                        'retail'    => $retail,
                        'wholesale' => $whole,
                        'price'     => ($tradePricing && $whole > 0) ? $whole : $retail,
                    ];
                }
            } else {
                $retail = (float)$p['price'];
                $whole  = (float)$p['wholesale_price'];
                $out[] = [
                    'label'     => $p['name'],
                    'retail'    => $retail,
                    'wholesale' => $whole,
                    'price'     => ($tradePricing && $whole > 0) ? $whole : $retail,
                ];
            }
        }
    } catch (PDOException $e) {
        error_log('invoiceProductOptions failed: ' . $e->getMessage());
    }
    return $out;
}

/** Approved trade accounts, for the bill-to picker on a new invoice. */
function invoiceTradeCustomers(PDO $pdo): array
{
    try {
        return $pdo->query(
            "SELECT id, business_name, contact_name, email, phone, address, postcode, vat_number
               FROM trade_users WHERE status = 'approved' ORDER BY business_name ASC"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('invoiceTradeCustomers failed: ' . $e->getMessage());
        return [];
    }
}

/** Format a quantity the way the printed invoice does: "5" or "2.25 Litre". */
function formatInvoiceQty(float $qty, string $unit = ''): string
{
    $n = (fmod($qty, 1.0) === 0.0)
        ? number_format($qty, 0)
        : rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    return $unit !== '' ? $n . ' ' . $unit : $n;
}

/** Human label for a status. */
function invoiceStatusLabel(string $status): array
{
    return match ($status) {
        'paid'      => ['PAID',      '#ecfdf5', '#047857', '#a7f3d0'],
        'part_paid' => ['PART PAID', '#eff6ff', '#1d4ed8', '#bfdbfe'],
        'sent'      => ['SENT',      '#fffbeb', '#b45309', '#fde68a'],
        'void'      => ['VOID',      '#f3f4f6', '#6b7280', '#e5e7eb'],
        default     => ['DRAFT',     '#f9fafb', '#6b7280', '#e5e7eb'],
    };
}

/**
 * The CSS class carrying a status pill's colours, e.g. "is-paid".
 *
 * Preferred over the colours from invoiceStatusLabel(): those have to be
 * injected as a style attribute, and this project keeps all styling in the
 * stylesheets. Restyling a status is then a CSS edit, not a PHP one.
 */
function invoiceStatusClass(string $status): string
{
    return match ($status) {
        'paid'      => 'is-paid',
        'part_paid' => 'is-part-paid',
        'sent'      => 'is-sent',
        'void'      => 'is-void',
        default     => 'is-draft',
    };
}
