<?php
// ============================================================
//  Creamy Bite – VAT & Accounting domain layer
//
//  All of the arithmetic and every database write for the accounting module
//  lives here, so the pages stay presentation-only and the same rules apply
//  whether a figure is reached from the dashboard, a report or an export.
//
//  ── Three rules this file exists to hold ───────────────────
//
//  1. Nothing happens unless the business is VAT registered.
//     acctVatEnabled() gates the module. An unregistered business must not
//     charge VAT, must not have a return prepared, and must not be shown
//     figures implying either.
//
//  2. Net and VAT are stored, never derived.
//     Recomputing VAT from a gross figure and a percentage reintroduces a
//     rounding difference on every read, and those pennies are precisely what
//     makes a VAT return fail to reconcile against the ledger.
//
//  3. Zero-rated, exempt and outside-scope are three different things.
//     They all charge £0 VAT and they behave differently on a return: zero
//     rated and exempt sales belong in Box 6, outside-scope ones do not.
//     `kind` on vat_rates carries that distinction; the rate alone cannot.
// ============================================================

if (defined('CB_ACCOUNTING_LOADED')) {
    return;
}
define('CB_ACCOUNTING_LOADED', true);

require_once __DIR__ . '/config.php';

// ── Settings ────────────────────────────────────────────────

/** The one settings row, or sensible defaults on a database without it yet. */
function acctSettings(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $fallback = [
        'id' => 1, 'is_registered' => 0, 'business_name' => SHOP_NAME, 'vat_number' => '',
        'scheme' => 'standard', 'flat_rate_pct' => 0.0, 'frequency' => 'quarterly',
        'period_start' => date('Y-04-01'), 'default_rate_id' => 0,
    ];
    try {
        $row = $pdo->query("SELECT * FROM vat_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $cache = $row ?: $fallback;
    } catch (PDOException $e) {
        // Table arrives with the migration. Returning defaults means the tab
        // renders a "run the migration" notice rather than a fatal error.
        $cache = $fallback;
    }
    return $cache;
}

/** Is the module live? Everything downstream checks this first. */
function acctVatEnabled(PDO $pdo): bool
{
    return !empty(acctSettings($pdo)['is_registered']);
}

/** Has the schema been installed? */
function acctInstalled(PDO $pdo): bool
{
    try {
        $pdo->query("SELECT 1 FROM vat_settings LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/** All VAT rates, active first. */
function acctRates(PDO $pdo, bool $activeOnly = true): array
{
    try {
        $sql = "SELECT * FROM vat_rates" . ($activeOnly ? " WHERE active = 1" : "") . " ORDER BY sort_order, id";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// ── VAT arithmetic ──────────────────────────────────────────

/**
 * VAT on a net amount, rounded to the penny.
 *
 * Rounded here and stored, rather than left to the caller, so that the same
 * £19.99 at 20% is £4.00 in the ledger, on the invoice and in Box 1. A figure
 * rounded independently in three places disagrees with itself.
 */
function acctVatOnNet(float $net, float $rate): float
{
    return round($net * $rate, 2);
}

/**
 * Split a VAT-inclusive amount into net and VAT.
 *
 * Retail prices are quoted gross, so this is the direction most sales need.
 * The VAT is derived first and the net taken as the remainder, which
 * guarantees net + vat == gross exactly. Doing it the other way round leaves
 * a penny adrift on roughly one price in six.
 */
function acctSplitGross(float $gross, float $rate): array
{
    if ($rate <= 0) {
        return ['net' => round($gross, 2), 'vat' => 0.00];
    }
    $vat = round($gross * $rate / (1 + $rate), 2);
    return ['net' => round($gross - $vat, 2), 'vat' => $vat];
}

// ── VAT periods ─────────────────────────────────────────────

/**
 * The VAT period containing $onDate, as [from, to].
 *
 * Counted forward from the configured start date rather than from the
 * calendar year, because a VAT quarter rarely aligns with one — a business
 * registered in May files Feb/May/Aug/Nov, not Jan/Apr/Jul/Oct.
 */
function acctPeriodFor(PDO $pdo, string $onDate = ''): array
{
    $s        = acctSettings($pdo);
    $onDate   = $onDate !== '' ? $onDate : date('Y-m-d');
    $start    = $s['period_start'] ?: date('Y-04-01');
    $months   = ['monthly' => 1, 'quarterly' => 3, 'annual' => 12][$s['frequency']] ?? 3;

    $startTs = strtotime($start);
    $onTs    = strtotime($onDate);

    // Before the scheme began there is no period to report.
    if ($onTs < $startTs) {
        return ['from' => $start, 'to' => date('Y-m-d', strtotime($start . ' +' . ($months) . ' months -1 day'))];
    }

    // Walk forward in whole periods. A loop rather than modular arithmetic
    // because month lengths differ and "+3 months" from 31 January is not a
    // fixed number of days.
    $from = $startTs;
    $guard = 0;
    while ($guard++ < 600) {
        $next = strtotime(date('Y-m-d', $from) . ' +' . $months . ' months');
        if ($onTs < $next) {
            return [
                'from' => date('Y-m-d', $from),
                'to'   => date('Y-m-d', strtotime('-1 day', $next)),
            ];
        }
        $from = $next;
    }
    return ['from' => date('Y-m-d', $from), 'to' => $onDate];
}

/** Human label for a period, e.g. "1 Apr 2026 – 30 Jun 2026". */
function acctPeriodLabel(array $p): string
{
    return date('j M Y', strtotime($p['from'])) . ' – ' . date('j M Y', strtotime($p['to']));
}

/** When the return for a period is due: one calendar month and 7 days after it ends. */
function acctPeriodDue(array $p): string
{
    return date('Y-m-d', strtotime($p['to'] . ' +1 month +7 days'));
}

// ── Figures pulled from the rest of the system ──────────────

/**
 * Sales in a period, split into net and VAT.
 *
 * Cancelled orders are excluded everywhere money is counted: the row keeps
 * its Paid status but the money was refunded or never taken.
 *
 * Under the cash scheme VAT falls due when the customer pays, not when the
 * order is raised, so the date column and the filter both change. That is the
 * whole point of the scheme and getting it wrong misstates every return.
 */
function acctSalesTotals(PDO $pdo, string $from, string $to): array
{
    $s      = acctSettings($pdo);
    $isCash = ($s['scheme'] === 'cash');

    // On the cash scheme only settled orders count, and they count on the day
    // they were settled. There is no separate paid_on column on orders, so
    // created_at is the best available proxy and is flagged as such to the
    // user on the return page rather than hidden.
    $where = $isCash
        ? "payment_status IN ('Paid','Cash','Bank') AND status <> 'Cancelled'"
        : "status <> 'Cancelled'";

    $sql = "SELECT
                COUNT(*)                       AS n,
                COALESCE(SUM(total_price), 0)  AS gross,
                COALESCE(SUM(vat_amount), 0)   AS vat,
                COALESCE(SUM(delivery_charge), 0) AS delivery
            FROM orders
            WHERE DATE(created_at) BETWEEN :f AND :t AND {$where}";

    try {
        $st = $pdo->prepare($sql);
        $st->execute(['f' => $from, 't' => $to]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        $r = [];
    }

    $gross = (float)($r['gross'] ?? 0);
    $vat   = (float)($r['vat'] ?? 0);

    // A refunded order still shows its full original value above — nothing
    // in the query knows a refund happened. Subtract it here, once, so every
    // caller (VAT boxes, the dashboard, the P&L) is corrected automatically
    // rather than each having to remember to do it separately.
    $refund = acctRefundTotals($pdo, $from, $to);
    $gross -= $refund['gross'];
    $vat   -= $refund['vat'];

    return [
        'count'    => (int)($r['n'] ?? 0),
        'gross'    => round($gross, 2),
        'vat'      => round($vat, 2),
        'net'      => round($gross - $vat, 2),
        'delivery' => round((float)($r['delivery'] ?? 0), 2),
        'refunded'     => $refund['gross'],
        'refunded_vat' => $refund['vat'],
        'refunded_net' => $refund['net'],
        'basis'    => $isCash ? 'cash' : 'accrual',
    ];
}

/**
 * Refunds issued in a period, split proportionally into net/VAT using each
 * refunded order's own recorded rate. A flat 20% would be wrong for a retail
 * order (which never carried VAT to begin with) and right only by
 * coincidence for a trade one.
 *
 * Matches HMRC's own treatment: a refund/credit note adjusts output VAT in
 * the period the refund was GIVEN, not by restating the original sale's
 * period — so this is keyed on order_refunds.created_at, not orders.created_at.
 */
function acctRefundTotals(PDO $pdo, string $from, string $to): array
{
    try {
        // A cancelled order's revenue was never in acctSalesTotals' `gross`
        // to begin with (it's excluded outright) — including its refund here
        // too would subtract money that was never added.
        $st = $pdo->prepare(
            "SELECT r.amount, o.total_price, o.vat_amount
               FROM order_refunds r
               JOIN orders o ON o.id = r.order_id AND o.status <> 'Cancelled'
              WHERE DATE(r.created_at) BETWEEN :f AND :t"
        );
        $st->execute(['f' => $from, 't' => $to]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['gross' => 0.0, 'vat' => 0.0, 'net' => 0.0, 'count' => 0];
    }

    $gross = 0.0; $vat = 0.0;
    foreach ($rows as $r) {
        $refundAmt  = (float)$r['amount'];
        $orderTotal = (float)$r['total_price'];
        $gross += $refundAmt;
        if ($orderTotal > 0) {
            $vat += (float)$r['vat_amount'] * min(1.0, $refundAmt / $orderTotal);
        }
    }
    return ['gross' => round($gross, 2), 'vat' => round($vat, 2),
            'net' => round($gross - $vat, 2), 'count' => count($rows)];
}

/** Standalone invoices that are not tied to an order, so nothing is counted twice. */
function acctInvoiceTotals(PDO $pdo, string $from, string $to): array
{
    $s      = acctSettings($pdo);
    $isCash = ($s['scheme'] === 'cash');
    $where  = $isCash ? "AND i.status = 'paid'" : "AND i.status <> 'void'";

    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(i.total), 0)      AS gross,
                    COALESCE(SUM(i.vat_amount), 0) AS vat
               FROM invoices i
              WHERE DATE(i.issue_date) BETWEEN :f AND :t
                AND (i.order_id IS NULL OR i.order_id = 0)
                {$where}"
        );
        $st->execute(['f' => $from, 't' => $to]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        $r = [];
    }
    $gross = (float)($r['gross'] ?? 0);
    $vat   = (float)($r['vat'] ?? 0);
    return ['count' => (int)($r['n'] ?? 0), 'gross' => round($gross, 2),
            'vat' => round($vat, 2), 'net' => round($gross - $vat, 2)];
}

/**
 * Purchases and expenses in a period.
 *
 * Only VAT marked recoverable reaches Box 4. Input VAT on entertainment and
 * most cars is charged but cannot be reclaimed — it belongs in the cost, not
 * in the reclaim, and treating it otherwise overstates the refund.
 */
function acctPurchaseTotals(PDO $pdo, string $from, string $to): array
{
    $out = ['net' => 0.0, 'vat' => 0.0, 'vat_blocked' => 0.0, 'count' => 0];

    foreach ([['purchase_invoices', 'invoice_date'], ['expenses', 'expense_date']] as [$table, $dateCol]) {
        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*) AS n,
                        COALESCE(SUM(net), 0) AS net,
                        COALESCE(SUM(CASE WHEN recoverable = 1 THEN vat_amount ELSE 0 END), 0) AS vat_ok,
                        COALESCE(SUM(CASE WHEN recoverable = 0 THEN vat_amount ELSE 0 END), 0) AS vat_no
                   FROM `{$table}` WHERE `{$dateCol}` BETWEEN :f AND :t"
            );
            $st->execute(['f' => $from, 't' => $to]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['count']       += (int)($r['n'] ?? 0);
            $out['net']         += (float)($r['net'] ?? 0);
            $out['vat']         += (float)($r['vat_ok'] ?? 0);
            $out['vat_blocked'] += (float)($r['vat_no'] ?? 0);
        } catch (PDOException $e) {
            // Table not migrated yet — contributes nothing.
        }
    }

    return array_map(static fn($v) => is_float($v) ? round($v, 2) : $v, $out);
}

// ── The VAT return ──────────────────────────────────────────

/**
 * Compute the nine boxes for a period, with the workings behind each.
 *
 * Returns box values AND a 'workings' array, because a figure a business
 * cannot explain to HMRC is not much use — every box carries the components
 * that produced it.
 *
 * Boxes 2, 8 and 9 concern EC acquisitions and dispatches. This shop sells
 * within the UK, so they are zero and labelled as such rather than silently
 * omitted; a business that starts selling to the EU needs to see they exist.
 */
function acctComputeReturn(PDO $pdo, string $from, string $to): array
{
    $s        = acctSettings($pdo);
    $sales    = acctSalesTotals($pdo, $from, $to);
    $invoices = acctInvoiceTotals($pdo, $from, $to);
    $buys     = acctPurchaseTotals($pdo, $from, $to);

    $outputVat = round($sales['vat'] + $invoices['vat'], 2);
    $salesNet  = round($sales['net'] + $invoices['net'], 2);

    $workings = [
        1 => [
            ['Orders (' . $sales['count'] . ')', round($sales['vat'] + $sales['refunded_vat'], 2)],
            ['Standalone invoices (' . $invoices['count'] . ')', $invoices['vat']],
        ],
        4 => [
            ['Purchase invoices & expenses (' . $buys['count'] . ')', $buys['vat']],
        ],
        6 => [
            ['Order sales excluding VAT', round($sales['net'] + $sales['refunded_net'], 2)],
            ['Invoice sales excluding VAT', $invoices['net']],
        ],
        7 => [
            ['Purchases & expenses excluding VAT', $buys['net']],
        ],
    ];

    // Shown as its own line rather than folded silently into the "Orders"
    // figure above, so a refund's effect on the return is something an
    // admin can actually see, not just trust happened.
    if ($sales['refunded'] > 0) {
        $workings[1][] = ['Refunds issued', -$sales['refunded_vat']];
        $workings[6][] = ['Refunds issued (excluding VAT)', -$sales['refunded_net']];
    }

    if ($buys['vat_blocked'] > 0) {
        $workings[4][] = ['Non-recoverable VAT excluded', -$buys['vat_blocked']];
    }

    // ── Flat Rate Scheme ────────────────────────────────────
    // Under FRS the business pays a fixed percentage of VAT-INCLUSIVE
    // turnover and reclaims nothing on ordinary purchases. Box 1 is therefore
    // not the VAT charged, and Box 4 is normally nil. Computing FRS the
    // standard way is one of the commonest ways to overpay HMRC.
    $isFlatRate = ($s['scheme'] === 'flat_rate');
    if ($isFlatRate) {
        $pct        = (float)$s['flat_rate_pct'] / 100;
        $frsTurnover = round($sales['gross'] + $invoices['gross'], 2);
        $box1       = round($frsTurnover * $pct, 2);
        $box4       = 0.00;
        $workings[1] = [
            ['VAT-inclusive turnover', $frsTurnover],
            ['Flat rate ' . rtrim(rtrim(number_format((float)$s['flat_rate_pct'], 2), '0'), '.') . '%', $box1],
        ];
        $workings[4] = [
            ['Flat Rate Scheme reclaims nothing on ordinary purchases', 0.00],
        ];
    } else {
        $box1 = $outputVat;
        $box4 = $buys['vat'];
    }

    $box2 = 0.00;                       // EC acquisitions — UK-only shop
    $box3 = round($box1 + $box2, 2);
    $box5 = round($box3 - $box4, 2);
    $box6 = $isFlatRate ? round($sales['gross'] + $invoices['gross'], 2) : $salesNet;
    $box7 = $isFlatRate ? 0.00 : $buys['net'];
    $box8 = 0.00;
    $box9 = 0.00;

    return [
        'from' => $from, 'to' => $to,
        'scheme' => $s['scheme'], 'basis' => $sales['basis'],
        'boxes' => [
            1 => $box1, 2 => $box2, 3 => $box3, 4 => $box4, 5 => $box5,
            6 => $box6, 7 => $box7, 8 => $box8, 9 => $box9,
        ],
        'workings' => $workings,
        'source'   => ['sales' => $sales, 'invoices' => $invoices, 'purchases' => $buys],
    ];
}

/** The nine box descriptions, exactly as HMRC words them. */
function acctBoxLabels(): array
{
    return [
        1 => 'VAT due in this period on sales and other outputs',
        2 => 'VAT due in this period on acquisitions from EU member states',
        3 => 'Total VAT due (Box 1 + Box 2)',
        4 => 'VAT reclaimed on purchases and other inputs',
        5 => 'Net VAT to pay to HMRC or reclaim (Box 3 − Box 4)',
        6 => 'Total value of sales excluding VAT',
        7 => 'Total value of purchases excluding VAT',
        8 => 'Total value of supplies of goods to EU member states',
        9 => 'Total value of acquisitions of goods from EU member states',
    ];
}

// ── Double entry ────────────────────────────────────────────

/** Look up an account id by its code. */
function acctAccountId(PDO $pdo, string $code): int
{
    static $map = [];
    if (isset($map[$code])) {
        return $map[$code];
    }
    try {
        $st = $pdo->prepare("SELECT id FROM ledger_accounts WHERE code = ?");
        $st->execute([$code]);
        return $map[$code] = (int)($st->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Post a balanced journal entry.
 *
 * $lines is a list of ['code' => '4000', 'debit' => 0.00, 'credit' => 12.34,
 * 'description' => '…'].
 *
 * Refuses anything that does not balance. A ledger that accepts an unbalanced
 * entry stops being a ledger — every report built on it is then wrong in a way
 * that is very hard to find later, so this fails loudly at the point of entry
 * instead.
 *
 * @throws InvalidArgumentException when debits and credits differ, or an
 *         account code does not exist.
 */
function acctPostJournal(PDO $pdo, string $date, string $memo, array $lines,
                         string $source = 'manual', int $sourceId = 0,
                         string $reference = '', string $user = ''): int
{
    $debit = $credit = 0.0;
    foreach ($lines as $l) {
        $debit  += round((float)($l['debit'] ?? 0), 2);
        $credit += round((float)($l['credit'] ?? 0), 2);
    }
    $debit  = round($debit, 2);
    $credit = round($credit, 2);

    if (abs($debit - $credit) > 0.001) {
        throw new InvalidArgumentException(
            sprintf('Journal does not balance: debits %.2f, credits %.2f', $debit, $credit)
        );
    }
    if ($debit === 0.0) {
        throw new InvalidArgumentException('Journal has no value.');
    }

    $resolved = [];
    foreach ($lines as $l) {
        $id = acctAccountId($pdo, (string)$l['code']);
        if ($id === 0) {
            throw new InvalidArgumentException('Unknown account code: ' . $l['code']);
        }
        $resolved[] = [$id, round((float)($l['debit'] ?? 0), 2), round((float)($l['credit'] ?? 0), 2),
                       (string)($l['description'] ?? '')];
    }

    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }
    try {
        $st = $pdo->prepare(
            "INSERT INTO journal_entries (entry_date, reference, memo, source, source_id, created_by)
             VALUES (?,?,?,?,?,?)"
        );
        $st->execute([$date, $reference, $memo, $source, $sourceId, $user]);
        $entryId = (int)$pdo->lastInsertId();

        $ls = $pdo->prepare(
            "INSERT INTO journal_lines (entry_id, account_id, debit, credit, description) VALUES (?,?,?,?,?)"
        );
        foreach ($resolved as $r) {
            $ls->execute([$entryId, $r[0], $r[1], $r[2], $r[3]]);
        }
        if ($own) { $pdo->commit(); }
        return $entryId;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/** Trial balance for a period: every account with its debit and credit totals. */
function acctTrialBalance(PDO $pdo, string $from, string $to): array
{
    try {
        $st = $pdo->prepare(
            "SELECT a.code, a.name, a.type,
                    COALESCE(SUM(l.debit), 0)  AS debit,
                    COALESCE(SUM(l.credit), 0) AS credit
               FROM ledger_accounts a
          LEFT JOIN journal_lines l   ON l.account_id = a.id
          LEFT JOIN journal_entries e ON e.id = l.entry_id
                                     AND e.entry_date BETWEEN :f AND :t
              WHERE a.active = 1
           GROUP BY a.id, a.code, a.name, a.type
             HAVING debit <> 0 OR credit <> 0
           ORDER BY a.code"
        );
        $st->execute(['f' => $from, 't' => $to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// ── Audit ───────────────────────────────────────────────────

/**
 * Record who changed what.
 *
 * Never throws: an audit write must not be able to take down the action it is
 * recording. A failure here is logged to the PHP error log instead.
 */
function acctAudit(PDO $pdo, string $action, string $entity = '', int $entityId = 0,
                   $oldValue = null, $newValue = null): void
{
    try {
        $user = $_SESSION['admin_staff_name'] ?? ($_SESSION['admin_username'] ?? (defined('ADMIN_USERNAME') ? ADMIN_USERNAME : 'admin'));
        $st = $pdo->prepare(
            "INSERT INTO accounting_audit (user_name, action, entity, entity_id, old_value, new_value, ip_address)
             VALUES (?,?,?,?,?,?,?)"
        );
        $st->execute([
            mb_substr((string)$user, 0, 120),
            mb_substr($action, 0, 80),
            mb_substr($entity, 0, 60),
            $entityId,
            $oldValue === null ? null : (is_scalar($oldValue) ? (string)$oldValue : json_encode($oldValue)),
            $newValue === null ? null : (is_scalar($newValue) ? (string)$newValue : json_encode($newValue)),
            mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
        ]);
    } catch (Throwable $e) {
        error_log('Accounting audit write failed: ' . $e->getMessage());
    }
}

/** The expense categories offered, grouped for the picker. */
function acctExpenseCategories(): array
{
    return [
        'Premises'    => ['Rent', 'Utilities', 'Insurance', 'Cleaning', 'Repairs'],
        'Stock'       => ['Ingredients', 'Milk', 'Cream', 'Chocolate', 'Sugar', 'Fruit', 'Packaging'],
        'Operations'  => ['Equipment', 'Vehicle', 'Fuel', 'Office Supplies'],
        'People'      => ['Wages', 'Professional Fees'],
        'Growth'      => ['Marketing'],
        'Other'       => ['Miscellaneous'],
    ];
}

/** Flat list of every category name. */
function acctExpenseCategoryList(): array
{
    $out = [];
    foreach (acctExpenseCategories() as $group) {
        foreach ($group as $c) { $out[] = $c; }
    }
    return $out;
}

/** Which ledger account an expense category posts to. */
function acctCategoryAccount(string $category): string
{
    $map = [
        'Rent' => '6010', 'Utilities' => '6000', 'Insurance' => '6020', 'Cleaning' => '6050',
        'Repairs' => '6090', 'Ingredients' => '6070', 'Milk' => '6070', 'Cream' => '6070',
        'Chocolate' => '6070', 'Sugar' => '6070', 'Fruit' => '6070', 'Packaging' => '6060',
        'Equipment' => '1400', 'Vehicle' => '6030', 'Fuel' => '6030', 'Office Supplies' => '6040',
        'Wages' => '6110', 'Professional Fees' => '6100', 'Marketing' => '6080',
    ];
    return $map[$category] ?? '6900';   // Miscellaneous
}

// ── Profit & Loss ───────────────────────────────────────────

/** Reverse lookup: which group (Premises/Stock/Operations/...) a category belongs to. */
function acctExpenseCategoryGroup(string $category): string
{
    foreach (acctExpenseCategories() as $group => $items) {
        if (in_array($category, $items, true)) {
            return $group;
        }
    }
    return 'Other';
}

/**
 * Revenue, cost of sales, gross and net profit for a period.
 *
 * There is no per-product cost field anywhere in this app — wholesale_price
 * is what a trade customer pays, not what a tub costs to make — so a true
 * COGS figure cannot be built from products. The Stock category group
 * (ingredients, packaging, etc.) is the closest honest substitute for cost
 * of sales; everything else recorded is treated as an operating expense.
 */
function acctProfitLoss(PDO $pdo, string $from, string $to): array
{
    $sales    = acctSalesTotals($pdo, $from, $to);      // already refund-corrected
    $invoices = acctInvoiceTotals($pdo, $from, $to);
    $revenue  = round($sales['net'] + $invoices['net'], 2);

    // Group every expense + purchase line (net only — VAT is HMRC's money,
    // not a real cost to the business) by category group, from both tables
    // at once, the same way acctPurchaseTotals() already unions them.
    $byGroup = [];
    foreach ([['expenses', 'expense_date'], ['purchase_invoices', 'invoice_date']] as [$table, $dateCol]) {
        try {
            $st = $pdo->prepare("SELECT category, net FROM `{$table}` WHERE `{$dateCol}` BETWEEN :f AND :t");
            $st->execute(['f' => $from, 't' => $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $g = acctExpenseCategoryGroup((string)$r['category']);
                $byGroup[$g] = ($byGroup[$g] ?? 0.0) + (float)$r['net'];
            }
        } catch (PDOException $e) {
            // Table not migrated yet — contributes nothing.
        }
    }

    $costOfSales = round($byGroup['Stock'] ?? 0.0, 2);
    $grossProfit = round($revenue - $costOfSales, 2);
    $opexGroups  = array_diff_key($byGroup, ['Stock' => true]);
    ksort($opexGroups);
    $totalOpex   = round(array_sum($opexGroups), 2);
    $netProfit   = round($grossProfit - $totalOpex, 2);

    return [
        'from' => $from, 'to' => $to,
        'revenue'       => $revenue,
        'cost_of_sales' => $costOfSales,
        'gross_profit'  => $grossProfit,
        'gross_margin'  => $revenue > 0 ? round($grossProfit / $revenue * 100, 1) : 0.0,
        'opex'          => array_map(static fn($v) => round($v, 2), $opexGroups),
        'total_opex'    => $totalOpex,
        'net_profit'    => $netProfit,
        'net_margin'    => $revenue > 0 ? round($netProfit / $revenue * 100, 1) : 0.0,
        'refunded'      => $sales['refunded'],
    ];
}
