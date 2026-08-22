<?php
// ============================================================
//  Creamy Bite – Taking orders: the trade half
//
//  The shop already has two switches, delivery and collection, held in
//  store_settings and read through cbOrderingOpen(). They apply to whoever is
//  checking out. This file adds the same pair again for trade accounts, so a
//  wholesale round can be stopped without closing the public shop, or the
//  other way round.
//
//  Four switches in total, then:
//
//      delivery_open              collection_open              ← normal customers
//      trade_delivery_open        trade_collection_open        ← trade accounts
//
//  Deliberately additive. Nothing here reads or writes the two original
//  columns, and cbOrderingOpen() is left alone, so the existing switches
//  behave exactly as they did.
//
//  Everything defaults to open, and any database trouble is treated as open —
//  a shop that is quietly not taking orders is far worse than one that takes
//  an order it has to apologise for.
// ============================================================

const CB_TRADE_ORDER_METHODS = ['delivery', 'collection'];

/** Column holding the switch for one method. */
function cbTradeOrderingColumn(string $method): string
{
    return 'trade_' . $method . '_open';
}

/** Column holding the owner's closed message for one method. */
function cbTradeOrderingNoteColumn(string $method): string
{
    return 'trade_' . $method . '_closed_note';
}

/**
 * Add the four columns if this database has not got them yet.
 *
 * Written the same way checkout_handler.php adds its own missing columns:
 * ask information_schema, then ALTER. It runs once per request and never
 * throws — a server that cannot be altered still serves the shop.
 */
function cbTradeOrderingReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $columns = [
        'trade_delivery_open'          => "TINYINT(1) NOT NULL DEFAULT 1",
        'trade_collection_open'        => "TINYINT(1) NOT NULL DEFAULT 1",
        'trade_delivery_closed_note'   => "VARCHAR(255) NOT NULL DEFAULT ''",
        'trade_collection_closed_note' => "VARCHAR(255) NOT NULL DEFAULT ''",
    ];

    // ASKS, it does not alter. This used to add the columns itself with an
    // ALTER TABLE the first time it ran, which works on a laptop but needs
    // ALTER rights at request time — shared hosting often refuses, and the
    // failure is silent: the switches just never save and nobody is told why.
    // The columns now come from admin/migrations/update_db.php with every
    // other schema change. Until that has been run this simply reports "not
    // ready", and cbTradeOrderingOpen() answers OPEN, so a shop that has not
    // migrated yet keeps taking trade orders rather than quietly stopping.
    try {
        $check = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'store_settings' AND COLUMN_NAME = ?"
        );
        $ready = true;
        foreach (array_keys($columns) as $name) {
            $check->execute([$name]);
            if (!(int)$check->fetchColumn()) {
                $ready = false;
                break;
            }
        }
    } catch (PDOException $e) {
        error_log('Trade ordering columns unavailable: ' . $e->getMessage());
        $ready = false;
    }

    return $ready;
}

/** Is this method currently being accepted from trade accounts? */
function cbTradeOrderingOpen(PDO $pdo, string $method): bool
{
    if (!in_array($method, CB_TRADE_ORDER_METHODS, true)) {
        return true;
    }
    if (!cbTradeOrderingReady($pdo)) {
        return true;                       // fail open
    }

    try {
        $col = cbTradeOrderingColumn($method);
        $val = $pdo->query("SELECT `{$col}` FROM `store_settings` ORDER BY `id` LIMIT 1")->fetchColumn();
        return $val === false ? true : ((int)$val === 1);
    } catch (PDOException $e) {
        error_log('Trade ordering read failed: ' . $e->getMessage());
        return true;
    }
}

/** Are trade accounts able to order at all, either way? */
function cbAnyTradeOrderingOpen(PDO $pdo): bool
{
    foreach (CB_TRADE_ORDER_METHODS as $method) {
        if (cbTradeOrderingOpen($pdo, $method)) {
            return true;
        }
    }
    return false;
}

/** The owner's own closed message, exactly as typed — '' when they wrote none. */
function cbTradeOrderingRawNote(PDO $pdo, string $method): string
{
    if (!in_array($method, CB_TRADE_ORDER_METHODS, true) || !cbTradeOrderingReady($pdo)) {
        return '';
    }
    try {
        $col = cbTradeOrderingNoteColumn($method);
        $val = $pdo->query("SELECT `{$col}` FROM `store_settings` ORDER BY `id` LIMIT 1")->fetchColumn();
        return $val === false ? '' : trim((string)$val);
    } catch (PDOException $e) {
        return '';
    }
}

/**
 * What a trade customer should be told, falling back to a sentence that fits —
 * including whether the other way of ordering is still open to them.
 */
function cbTradeOrderingClosedNote(PDO $pdo, string $method): string
{
    $own = cbTradeOrderingRawNote($pdo, $method);
    if ($own !== '') {
        return $own;
    }

    $other     = $method === 'delivery' ? 'collection' : 'delivery';
    $otherOpen = cbTradeOrderingOpen($pdo, $other);
    $word      = $method === 'delivery' ? 'wholesale delivery' : 'warehouse collection';

    return $otherOpen
        ? 'We have paused ' . $word . ' for trade accounts at the moment — '
          . ($other === 'delivery' ? 'wholesale delivery' : 'warehouse collection') . ' is still running.'
        : 'We have paused trade orders at the moment. Please get in touch and we will sort something out.';
}

/** Flip one switch. False when the column is not there to write to. */
function cbSetTradeOrdering(PDO $pdo, string $method, bool $open): bool
{
    if (!in_array($method, CB_TRADE_ORDER_METHODS, true) || !cbTradeOrderingReady($pdo)) {
        return false;
    }
    try {
        $col = cbTradeOrderingColumn($method);
        $val = $open ? 1 : 0;

        // Targeted at the settings row WHATEVER its id. The old write said
        // WHERE id = 1 and returned execute()'s own answer, which is true for
        // a statement that ran perfectly and matched nothing — so on a
        // database whose settings row is not id 1, the switch reported
        // "Now taking trade delivery orders again" and changed nothing at all.
        //
        // rowCount() cannot stand in for success either: MySQL counts rows
        // CHANGED, not matched, so switching something on that is already on
        // returns 0 and would read as a failure. The only honest test is to
        // write and then look.
        $pdo->prepare("UPDATE `store_settings` SET `{$col}` = :v ORDER BY `id` LIMIT 1")
            ->execute(['v' => $val]);

        $now = $pdo->query("SELECT `{$col}` FROM `store_settings` ORDER BY `id` LIMIT 1")->fetchColumn();
        return $now !== false && (int)$now === $val;
    } catch (PDOException $e) {
        error_log('Trade ordering write failed: ' . $e->getMessage());
        return false;
    }
}

/** Save the owner's closed message for one method. */
function cbSetTradeOrderingNote(PDO $pdo, string $method, string $note): bool
{
    if (!in_array($method, CB_TRADE_ORDER_METHODS, true) || !cbTradeOrderingReady($pdo)) {
        return false;
    }
    try {
        $col  = cbTradeOrderingNoteColumn($method);
        $text = mb_substr(trim($note), 0, 255);

        // Same treatment as the switch above: aim at the row that is there,
        // then read it back rather than trusting the statement's own opinion.
        $pdo->prepare("UPDATE `store_settings` SET `{$col}` = :n ORDER BY `id` LIMIT 1")
            ->execute(['n' => $text]);

        $now = $pdo->query("SELECT `{$col}` FROM `store_settings` ORDER BY `id` LIMIT 1")->fetchColumn();
        return $now !== false && (string)$now === $text;
    } catch (PDOException $e) {
        error_log('Trade ordering note write failed: ' . $e->getMessage());
        return false;
    }
}
