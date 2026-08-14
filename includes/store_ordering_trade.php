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

    try {
        $check = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'store_settings' AND COLUMN_NAME = ?"
        );
        foreach ($columns as $name => $ddl) {
            $check->execute([$name]);
            if (!(int)$check->fetchColumn()) {
                $pdo->exec("ALTER TABLE `store_settings` ADD COLUMN `{$name}` {$ddl}");
            }
        }
        $ready = true;
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
        $val = $pdo->query("SELECT `{$col}` FROM `store_settings` WHERE `id` = 1")->fetchColumn();
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
        $val = $pdo->query("SELECT `{$col}` FROM `store_settings` WHERE `id` = 1")->fetchColumn();
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
        return $pdo->prepare("UPDATE `store_settings` SET `{$col}` = :v WHERE `id` = 1")
                   ->execute(['v' => $open ? 1 : 0]);
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
        $col = cbTradeOrderingNoteColumn($method);
        return $pdo->prepare("UPDATE `store_settings` SET `{$col}` = :n WHERE `id` = 1")
                   ->execute(['n' => mb_substr(trim($note), 0, 255)]);
    } catch (PDOException $e) {
        error_log('Trade ordering note write failed: ' . $e->getMessage());
        return false;
    }
}
