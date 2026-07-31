<?php
// ============================================================
//  Creamy Bite – Stock control
//
//  WHEN STOCK MOVES
//    Stock is committed when the order is PLACED, not when it is marked
//    Delivered. Reserving at placement is the only thing that stops two
//    customers buying the last tub — anything later is just bookkeeping.
//    Cancelling an order gives the stock back.
//
//  COUNTERS
//    total_stock  = what was put into stock
//    stock_qty    = what is left to sell        <- decremented on sale
//    sold_online  = sold through the website    <- incremented on sale
//    sold_offline = sold in person (admin)
//    damage_stock = written off
//    so total_stock = stock_qty + sold_online + sold_offline + damage_stock
//
//  VARIANTS
//    product_variants has no stock columns of its own, so a variant sale
//    draws down its parent product by the quantity ordered. A 1L and a
//    500ml therefore each consume one unit of the parent's stock. That is
//    what the current schema supports; per-variant stock would need its
//    own columns.
//
//  Only products with track_stock = 1 are affected. Everything else is
//  treated as always available.
// ============================================================

/**
 * Roll a list of order items up into product_id => total quantity.
 * Variants collapse onto their parent product.
 */
function stockRequirements(array $items): array
{
    $need = [];
    foreach ($items as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['quantity'] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $need[$pid] = ($need[$pid] ?? 0) + $qty;
        }
    }
    return $need;
}

/**
 * Which requirements cannot be met?
 *
 * @param bool $lock take row locks (only valid inside a transaction) so a
 *                   concurrent checkout cannot slip between check and deduct.
 * @return array list of human-readable shortage messages; empty means OK.
 */
function stockShortages(PDO $pdo, array $need, bool $lock = false): array
{
    if (empty($need)) {
        return [];
    }

    $sql = "SELECT id, name, track_stock, stock_qty, available FROM products WHERE id = :id"
         . ($lock ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);

    $shortages = [];
    foreach ($need as $pid => $qty) {
        $stmt->execute(['id' => $pid]);
        $p = $stmt->fetch();

        if (!$p) {
            $shortages[] = 'One of the items in your basket is no longer available.';
            continue;
        }
        if ((int)$p['available'] !== 1) {
            $shortages[] = $p['name'] . ' is no longer available.';
            continue;
        }
        if ((int)$p['track_stock'] !== 1) {
            continue;   // not stock-tracked, always sellable
        }
        $left = (int)$p['stock_qty'];
        if ($left < $qty) {
            $shortages[] = $left <= 0
                ? $p['name'] . ' has sold out.'
                : $p['name'] . ' — only ' . $left . ' left, you asked for ' . $qty . '.';
        }
    }
    return $shortages;
}

/** How many of one product can still be sold. PHP_INT_MAX when untracked. */
function stockAvailableFor(PDO $pdo, int $productId): int
{
    $stmt = $pdo->prepare("SELECT track_stock, stock_qty FROM products WHERE id = :id");
    $stmt->execute(['id' => $productId]);
    $p = $stmt->fetch();
    if (!$p) {
        return 0;
    }
    if ((int)$p['track_stock'] !== 1) {
        return PHP_INT_MAX;
    }
    return max(0, (int)$p['stock_qty']);
}

/** Commit stock for a placed order. */
function deductStock(PDO $pdo, array $need): void
{
    $stmt = $pdo->prepare(
        "UPDATE products
            SET stock_qty   = GREATEST(0, stock_qty - :qty),
                sold_online = sold_online + :qty2
          WHERE id = :id AND track_stock = 1"
    );
    foreach ($need as $pid => $qty) {
        $stmt->execute(['qty' => $qty, 'qty2' => $qty, 'id' => $pid]);
    }
}

/** Give stock back — an order was cancelled. */
function restoreStock(PDO $pdo, array $need): void
{
    $stmt = $pdo->prepare(
        "UPDATE products
            SET stock_qty   = stock_qty + :qty,
                sold_online = GREATEST(0, sold_online - :qty2)
          WHERE id = :id AND track_stock = 1"
    );
    foreach ($need as $pid => $qty) {
        $stmt->execute(['qty' => $qty, 'qty2' => $qty, 'id' => $pid]);
    }
}
