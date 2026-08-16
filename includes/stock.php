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
//  WHERE THE NUMBERS LIVE
//    On the SIZE, when a flavour has sizes. A 500ml and a 1L are different
//    things in the freezer and they sell at different rates; counting them
//    into one pool meant the shop could take an order for a size that ran
//    out days ago. Every counter above exists on product_variants too, and
//    for a flavour with sizes those rows are the truth.
//
//    The matching columns on products are kept as the SUM of the sizes —
//    see cbStockResyncProduct(). Reports, the storefront and the product
//    grid go on reading products.stock_qty and go on getting a true figure,
//    now meaning "all sizes together" rather than "units, of some size".
//
//    A flavour with no sizes keeps its stock on the product row, as before.
//
//  KEYS
//    Requirements are keyed "productId:variantId", variantId 0 meaning the
//    product itself — the same shape as the cart key. Orders placed before
//    sizes carried stock decode to ":0" and so still settle against the
//    product, which is how they were taken.
//
//  Only products with track_stock = 1 are affected. Everything else is
//  treated as always available.
// ============================================================

/** The requirements key for one product/size pair. */
function cbStockKey(int $productId, int $variantId = 0): string
{
    return $productId . ':' . max(0, $variantId);
}

/** Split a requirements key back into [productId, variantId]. */
function cbStockSplitKey(string $key): array
{
    $parts = explode(':', $key, 2);
    return [(int)($parts[0] ?? 0), (int)($parts[1] ?? 0)];
}

/**
 * Roll a list of order items up into "productId:variantId" => total quantity.
 *
 * Two lines of the same size merge; two sizes of the same flavour stay apart,
 * which is the whole point.
 */
function stockRequirements(array $items): array
{
    $need = [];
    foreach ($items as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        $vid = (int)($item['variant_id'] ?? 0);
        $qty = (int)($item['quantity'] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $key = cbStockKey($pid, $vid);
            $need[$key] = ($need[$key] ?? 0) + $qty;
        }
    }
    return $need;
}

/**
 * Bring a product's counters back in line with the sum of its sizes.
 *
 * Guarded by EXISTS: without it, a flavour sold without sizes would have its
 * own stock overwritten with the sum of nothing and quietly go to zero.
 */
function cbStockResyncProduct(PDO $pdo, int $productId): void
{
    if ($productId <= 0) {
        return;
    }
    $pdo->prepare(
        "UPDATE products p
            SET p.total_stock  = (SELECT COALESCE(SUM(v.total_stock),  0) FROM product_variants v WHERE v.product_id = p.id),
                p.stock_qty    = (SELECT COALESCE(SUM(v.stock_qty),    0) FROM product_variants v WHERE v.product_id = p.id),
                p.damage_stock = (SELECT COALESCE(SUM(v.damage_stock), 0) FROM product_variants v WHERE v.product_id = p.id),
                p.sold_offline = (SELECT COALESCE(SUM(v.sold_offline), 0) FROM product_variants v WHERE v.product_id = p.id),
                p.sold_online  = (SELECT COALESCE(SUM(v.sold_online),  0) FROM product_variants v WHERE v.product_id = p.id)
          WHERE p.id = :id
            AND EXISTS (SELECT 1 FROM product_variants v2 WHERE v2.product_id = p.id)"
    )->execute(['id' => $productId]);
}

/** Does this database hold stock per size yet? Asks once; never alters. */
function cbStockVariantsReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $ready = (int)$pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'product_variants' AND COLUMN_NAME = 'stock_qty'"
        )->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('variant stock check failed: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
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

    // Locked in a fixed order. Two checkouts holding the same two flavours in
    // opposite orders will otherwise each wait on the row the other has, and
    // the pair deadlocks; sorting means everyone queues the same way round.
    if ($lock) {
        ksort($need, SORT_STRING);
    }

    $byVariant = cbStockVariantsReady($pdo);
    $suffix    = $lock ? ' FOR UPDATE' : '';

    $pStmt = $pdo->prepare(
        "SELECT id, name, track_stock, stock_qty, available FROM products WHERE id = :id" . $suffix
    );
    $vStmt = $byVariant ? $pdo->prepare(
        "SELECT p.name AS product_name, p.track_stock, p.available AS product_available,
                v.name AS variant_name, v.available AS variant_available, v.stock_qty
           FROM product_variants v
           JOIN products p ON p.id = v.product_id
          WHERE v.id = :vid AND v.product_id = :pid" . $suffix
    ) : null;

    $shortages = [];
    foreach ($need as $key => $qty) {
        [$pid, $vid] = cbStockSplitKey((string)$key);

        // ── A size ───────────────────────────────────────────
        if ($vid > 0 && $vStmt !== null) {
            $vStmt->execute(['vid' => $vid, 'pid' => $pid]);
            $v = $vStmt->fetch();

            if (!$v) {
                $shortages[] = 'One of the items in your basket is no longer available.';
                continue;
            }
            $label = $v['product_name'] . ' (' . $v['variant_name'] . ')';
            if ((int)$v['product_available'] !== 1 || (int)$v['variant_available'] !== 1) {
                $shortages[] = $label . ' is no longer available.';
                continue;
            }
            if ((int)$v['track_stock'] !== 1) {
                continue;   // not stock-tracked, always sellable
            }
            $left = (int)$v['stock_qty'];
            if ($left < $qty) {
                $shortages[] = $left <= 0
                    ? $label . ' has sold out.'
                    : $label . ' — only ' . $left . ' left, you asked for ' . $qty . '.';
            }
            continue;
        }

        // ── A flavour with no sizes ──────────────────────────
        $pStmt->execute(['id' => $pid]);
        $p = $pStmt->fetch();

        if (!$p) {
            $shortages[] = 'One of the items in your basket is no longer available.';
            continue;
        }
        if ((int)$p['available'] !== 1) {
            $shortages[] = $p['name'] . ' is no longer available.';
            continue;
        }
        if ((int)$p['track_stock'] !== 1) {
            continue;
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

/**
 * How many of one product — or one of its sizes — can still be sold.
 * PHP_INT_MAX when the flavour is not stock-tracked.
 */
function stockAvailableFor(PDO $pdo, int $productId, int $variantId = 0): int
{
    if ($variantId > 0 && cbStockVariantsReady($pdo)) {
        $stmt = $pdo->prepare(
            "SELECT p.track_stock, v.stock_qty
               FROM product_variants v
               JOIN products p ON p.id = v.product_id
              WHERE v.id = :vid AND v.product_id = :pid"
        );
        $stmt->execute(['vid' => $variantId, 'pid' => $productId]);
        $v = $stmt->fetch();
        if (!$v) {
            return 0;
        }
        return (int)$v['track_stock'] !== 1 ? PHP_INT_MAX : max(0, (int)$v['stock_qty']);
    }

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
    cbStockApply($pdo, $need, -1);
}

/** Give stock back — an order was cancelled, or an item removed. */
function restoreStock(PDO $pdo, array $need): void
{
    cbStockApply($pdo, $need, +1);
}

/**
 * Move stock in one direction for a whole basket.
 *
 * $direction is -1 to sell and +1 to give back; the two are exact mirrors, so
 * writing them once means cancelling an order can never disagree with placing
 * it. Both floor at zero: an oversold count is bad, a negative one is worse.
 *
 * A size recorded on an order placed BEFORE sizes carried stock has its units
 * returned to that size rather than to the flavour at large. That is the right
 * shelf — the customer did buy a 500ml — and because the flavour's figure is
 * the sum of its sizes, its total lands exactly where it would have anyway.
 */
function cbStockApply(PDO $pdo, array $need, int $direction): void
{
    if (empty($need)) {
        return;
    }
    ksort($need, SORT_STRING);   // same fixed order as the locking check

    $byVariant = cbStockVariantsReady($pdo);

    $pStmt = $pdo->prepare(
        $direction < 0
            ? "UPDATE products
                  SET stock_qty   = GREATEST(0, stock_qty - :qty),
                      sold_online = sold_online + :qty2
                WHERE id = :id AND track_stock = 1"
            : "UPDATE products
                  SET stock_qty   = stock_qty + :qty,
                      sold_online = GREATEST(0, sold_online - :qty2)
                WHERE id = :id AND track_stock = 1"
    );

    $vStmt = $byVariant ? $pdo->prepare(
        $direction < 0
            ? "UPDATE product_variants v
                 JOIN products p ON p.id = v.product_id
                  SET v.stock_qty   = GREATEST(0, v.stock_qty - :qty),
                      v.sold_online = v.sold_online + :qty2
                WHERE v.id = :vid AND v.product_id = :pid AND p.track_stock = 1"
            : "UPDATE product_variants v
                 JOIN products p ON p.id = v.product_id
                  SET v.stock_qty   = v.stock_qty + :qty,
                      v.sold_online = GREATEST(0, v.sold_online - :qty2)
                WHERE v.id = :vid AND v.product_id = :pid AND p.track_stock = 1"
    ) : null;

    $touched = [];
    foreach ($need as $key => $qty) {
        [$pid, $vid] = cbStockSplitKey((string)$key);
        if ($pid <= 0 || $qty <= 0) {
            continue;
        }

        if ($vid > 0 && $vStmt !== null) {
            $vStmt->execute(['qty' => $qty, 'qty2' => $qty, 'vid' => $vid, 'pid' => $pid]);
            if ($vStmt->rowCount() > 0) {
                $touched[$pid] = true;
                continue;
            }
            // The size is gone, or the flavour is untracked. If the flavour
            // still has other sizes its figure is a sum and writing to it
            // would be undone by the next resync, so there is nowhere honest
            // to put this — say so rather than lose it in silence.
            if (cbStockHasVariants($pdo, $pid)) {
                error_log("stock: size {$vid} of product {$pid} could not be adjusted by "
                          . ($direction * $qty) . ' — size missing or flavour untracked');
                continue;
            }
        }

        $pStmt->execute(['qty' => $qty, 'qty2' => $qty, 'id' => $pid]);
    }

    foreach (array_keys($touched) as $pid) {
        cbStockResyncProduct($pdo, (int)$pid);
    }
}

/** Does this flavour have sizes? Cached per request — asked in a loop. */
function cbStockHasVariants(PDO $pdo, int $productId): bool
{
    static $cache = [];
    if (isset($cache[$productId])) {
        return $cache[$productId];
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM product_variants WHERE product_id = :id");
    $st->execute(['id' => $productId]);
    return $cache[$productId] = (int)$st->fetchColumn() > 0;
}
