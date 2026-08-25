<?php
// ============================================================
//  Creamy Bite – Persistent Cart for Trade B2B Customers
//
//  While browsing, the basket lives in $_SESSION['cart'] for everyone.
//  For a logged-in trade customer we additionally mirror it into the
//  trade_carts table, so it survives logging out, closing the browser,
//  or the PHP session expiring.
//
//  The stored basket is emptied only when the trade customer:
//    - places the order      (checkout_handler.php)
//    - removes the last item (cart_handler.php: remove / update qty 0)
//    - clears the cart       (cart_handler.php: clear)
//
//  Requires an open session and a $pdo from db.php.
// ============================================================

/** Id of the logged-in trade customer, or 0 when it is a retail visitor. */
function tradeCartUserId(): int
{
    return (int)($_SESSION['trade_user']['id'] ?? 0);
}

/**
 * Re-confirm that the trade session is still valid.
 *
 * Approval used to be checked only at login, so revoking an account had no
 * effect until that partner happened to log out — they carried on buying at
 * wholesale, skipping the retail delivery radius, in a session that could
 * last indefinitely. Call this on any page that grants trade privileges.
 *
 * Also refreshes vat_number, so a VAT number changed in one tab is reflected
 * in the pricing used by another.
 *
 * @return bool true if still a valid trade session
 */
function tradeSessionRevalidate(PDO $pdo): bool
{
    $uid = tradeCartUserId();
    if ($uid <= 0) {
        return false;
    }

    // Re-hit the database at most once a minute; this runs on every request.
    $now  = time();
    $last = (int)($_SESSION['trade_checked_at'] ?? 0);
    if ($now - $last < 60) {
        return true;
    }

    try {
        $stmt = $pdo->prepare("SELECT status, vat_number, business_name FROM trade_users WHERE id = :id");
        $stmt->execute(['id' => $uid]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        // A database hiccup must not log a legitimate partner out.
        error_log('Trade revalidation failed: ' . $e->getMessage());
        return true;
    }

    if (!$row || $row['status'] !== 'approved') {
        // Access withdrawn. Drop the trade session and the wholesale-priced
        // basket with it — those prices are no longer theirs to have.
        unset($_SESSION['trade_user'], $_SESSION['trade_checked_at']);
        $_SESSION['cart'] = [];
        return false;
    }

    $_SESSION['trade_user']['vat_number']    = $row['vat_number'];
    $_SESSION['trade_user']['business_name'] = $row['business_name'];
    $_SESSION['trade_checked_at']            = $now;
    return true;
}

/**
 * Mirror the current session cart into the database.
 * No-op for retail visitors. An empty cart deletes the stored row, so
 * "removed the last item" and "cleared the cart" both persist correctly.
 */
function tradeCartSave(PDO $pdo): void
{
    $uid = tradeCartUserId();
    if ($uid <= 0) {
        return;
    }

    $cart = $_SESSION['cart'] ?? [];
    if (empty($cart)) {
        tradeCartClear($pdo, $uid);
        return;
    }

    try {
        $pdo->prepare(
            "INSERT INTO trade_carts (trade_user_id, cart_json) VALUES (:uid, :json)
             ON DUPLICATE KEY UPDATE cart_json = VALUES(cart_json)"
        )->execute([
            'uid'  => $uid,
            'json' => json_encode($cart, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (PDOException $e) {
        // Persistence is best-effort: never break the shopping flow over it.
        error_log('tradeCartSave failed: ' . $e->getMessage());
    }
}

/** Delete the stored basket for a trade customer. */
function tradeCartClear(PDO $pdo, ?int $uid = null): void
{
    $uid = $uid ?? tradeCartUserId();
    if ($uid <= 0) {
        return;
    }

    try {
        $pdo->prepare("DELETE FROM trade_carts WHERE trade_user_id = :uid")
            ->execute(['uid' => $uid]);
    } catch (PDOException $e) {
        error_log('tradeCartClear failed: ' . $e->getMessage());
    }
}

/**
 * Restore a trade customer's saved basket into $_SESSION['cart'] at login.
 *
 * Anything already in the session cart (added before logging in) is kept and
 * merged, with quantities added together. Every line is then re-validated
 * against the current catalogue and re-priced at the customer's wholesale
 * rate, so a basket saved weeks ago never resurrects a stale price or a
 * product that has since been withdrawn.
 */
function tradeCartRestore(PDO $pdo, int $uid): void
{
    if ($uid <= 0) {
        return;
    }

    $stored = [];
    try {
        $stmt = $pdo->prepare("SELECT cart_json FROM trade_carts WHERE trade_user_id = :uid");
        $stmt->execute(['uid' => $uid]);
        $json = $stmt->fetchColumn();
        if ($json) {
            $stored = json_decode($json, true) ?: [];
        }
    } catch (PDOException $e) {
        error_log('tradeCartRestore failed: ' . $e->getMessage());
        return;
    }

    // Merge saved basket with whatever they added before logging in.
    $merged = $_SESSION['cart'] ?? [];
    foreach ($stored as $key => $item) {
        if (isset($merged[$key])) {
            $merged[$key]['quantity'] += (int)($item['quantity'] ?? 0);
        } else {
            $merged[$key] = $item;
        }
    }

    $before = count($merged);
    $_SESSION['cart'] = tradeCartReprice($pdo, $merged);
    $after  = count($_SESSION['cart']);

    // Tell them what went missing. The repriced basket is saved straight
    // back over the stored one, so a line dropped here is gone for good —
    // doing that silently means a partner can lose part of an order they
    // built weeks ago and never know.
    if ($after < $before) {
        $lost = $before - $after;
        $_SESSION['cart_notice'] = $lost === 1
            ? 'One item from your saved basket is no longer available and has been removed.'
            : $lost . ' items from your saved basket are no longer available and have been removed.';
    }

    tradeCartSave($pdo);
}

/**
 * Re-validate and re-price every line at wholesale rates.
 * Drops products and variants that are gone or no longer available.
 */
function tradeCartReprice(PDO $pdo, array $cart): array
{
    if (empty($cart)) {
        return [];
    }

    $pStmt = $pdo->prepare("SELECT * FROM products WHERE id = :id AND available = 1");
    $vStmt = $pdo->prepare("SELECT * FROM product_variants WHERE id = :vid AND product_id = :pid AND available = 1");

    $clean = [];
    foreach ($cart as $key => $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $variantId = (int)($item['variant_id'] ?? 0);
        $quantity  = max(1, (int)($item['quantity'] ?? 1));
        if ($productId <= 0) {
            continue;
        }

        try {
            $pStmt->execute(['id' => $productId]);
            $product = $pStmt->fetch();
            if (!$product) {
                continue; // withdrawn or deleted
            }

            $variantName = null;
            $price       = (float)$product['price'];
            $wholesale   = (float)($product['wholesale_price'] ?? 0);
            if ($wholesale > 0) {
                $price = $wholesale;
            }

            if ($variantId > 0) {
                $vStmt->execute(['vid' => $variantId, 'pid' => $productId]);
                $variant = $vStmt->fetch();
                if (!$variant) {
                    continue; // size no longer offered
                }
                $variantName = $variant['name'];
                $vWholesale  = (float)($variant['wholesale_price'] ?? 0);
                $price       = $vWholesale > 0 ? $vWholesale : (float)$variant['price'];
            }

            $clean[$key] = [
                'cart_key'     => $key,
                'product_id'   => $productId,
                'variant_id'   => $variantId > 0 ? $variantId : null,
                'variant_name' => $variantName,
                'name'         => $product['name'],
                // Kept in step with cart_handler.php so a category offer
                // matches a trade basket too — this function rebuilds a saved
                // basket from scratch, and a line missing its category would
                // quietly stop matching after the partner logged back in.
                'category'     => (string)($product['category'] ?? ''),
                'emoji'        => $product['emoji'],
                'image'        => $product['image'] ?? '',
                'price'        => $price,
                'quantity'     => $quantity,
            ];
        } catch (PDOException $e) {
            error_log('tradeCartReprice failed for product ' . $productId . ': ' . $e->getMessage());
        }
    }

    return $clean;
}
