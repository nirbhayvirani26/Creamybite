<?php
// ============================================================
//  Creamy Bite – Cart AJAX Handler (with Variant Support)
//  POST actions: add | remove | update | clear | get
//  Cart key format:
//    - No variant:  "42"
//    - With variant: "42:7"  (product_id:variant_id)
// ============================================================
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/trade_cart.php';
require_once __DIR__ . '/includes/stock.php';

// A revoked trade account must stop getting wholesale prices immediately.
tradeSessionRevalidate($pdo);

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'get';

// ── Helper: build cart summary ────────────────────────────────
function cartSummary(): array {
    $items = $_SESSION['cart'];
    $count = 0;
    $total = 0.0;
    foreach ($items as $item) {
        $count += $item['quantity'];
        $total += $item['price'] * $item['quantity'];
    }
    return [
        'items'          => array_values($items),
        'cart_count'     => $count,
        'cart_total'     => number_format($total, 2),
        'cart_total_raw' => $total,
        'success'        => true,
    ];
}

switch ($action) {

    // ── ADD ──────────────────────────────────────────────────────
    case 'add':
        $productId = (int)($_POST['product_id'] ?? 0);
        $variantId = (int)($_POST['variant_id'] ?? 0);

        if ($productId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid product']);
            exit;
        }

        // Fetch product
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id AND available = 1");
        $stmt->execute(['id' => $productId]);
        $product = $stmt->fetch();
        if (!$product) {
            echo json_encode(['success' => false, 'message' => 'Product not found']);
            exit;
        }

        // Trade-only products are refused for retail visitors even when the
        // product id is posted directly — hiding them in the listing is not
        // by itself a control.
        if (!empty($product['trade_only']) && empty($_SESSION['trade_user'])) {
            echo json_encode(['success' => false, 'message' => 'This product is available to trade account holders only.']);
            exit;
        }

        // If variant specified, fetch and validate it
        // Check if trade user is logged in
        $variantName    = null;   // stays null for products sold without sizes
        $isTradeUser    = !empty($_SESSION['trade_user']);
        $wholesalePrice = (float)($product['wholesale_price'] ?? 0);
        $price          = ($isTradeUser && $wholesalePrice > 0) ? $wholesalePrice : (float)$product['price'];

        if ($variantId > 0) {
            $vStmt = $pdo->prepare("SELECT * FROM product_variants WHERE id = :vid AND product_id = :pid AND available = 1");
            $vStmt->execute(['vid' => $variantId, 'pid' => $productId]);
            $variant = $vStmt->fetch();
            if (!$variant) {
                echo json_encode(['success' => false, 'message' => 'Variant not found or unavailable']);
                exit;
            }
            $variantName = $variant['name'];
            $vWholesale  = (float)($variant['wholesale_price'] ?? 0);
            $price       = ($isTradeUser && $vWholesale > 0) ? $vWholesale : (float)$variant['price'];
        }

        // Cart key
        $cartKey = $variantId > 0 ? "{$productId}:{$variantId}" : (string)$productId;

        // Don't let the basket exceed what is actually in stock. Variants draw
        // on the parent product's stock, so count every line for this product.
        $availableUnits = stockAvailableFor($pdo, $productId);
        $alreadyInCart  = 0;
        foreach ($_SESSION['cart'] as $line) {
            if ((int)($line['product_id'] ?? 0) === $productId) {
                $alreadyInCart += (int)($line['quantity'] ?? 0);
            }
        }
        if ($alreadyInCart + 1 > $availableUnits) {
            echo json_encode([
                'success' => false,
                'message' => $availableUnits <= 0
                    ? $product['name'] . ' has sold out.'
                    : 'Only ' . $availableUnits . ' of ' . $product['name'] . ' left in stock.',
            ]);
            exit;
        }

        if (isset($_SESSION['cart'][$cartKey])) {
            $_SESSION['cart'][$cartKey]['quantity']++;
        } else {
            $_SESSION['cart'][$cartKey] = [
                'cart_key'    => $cartKey,
                'product_id'  => $productId,
                'variant_id'  => $variantId > 0 ? $variantId : null,
                'variant_name'=> $variantName,
                'name'        => $product['name'],
                'emoji'       => $product['emoji'],
                'image'       => $product['image'] ?? '',
                'price'       => $price,
                'quantity'    => 1,
            ];
        }

        tradeCartSave($pdo);
        $summary = cartSummary();
        echo json_encode(['message' => 'Added to cart!'] + $summary);
        break;

    // ── REMOVE ───────────────────────────────────────────────────
    case 'remove':
        $cartKey = $_POST['cart_key'] ?? (string)((int)($_POST['product_id'] ?? 0));
        unset($_SESSION['cart'][$cartKey]);
        tradeCartSave($pdo);
        echo json_encode(cartSummary());
        break;

    // ── UPDATE QUANTITY ──────────────────────────────────────────
    case 'update':
        $cartKey = $_POST['cart_key'] ?? (string)((int)($_POST['product_id'] ?? 0));
        $qty     = (int)($_POST['quantity'] ?? 0);

        if ($qty <= 0) {
            unset($_SESSION['cart'][$cartKey]);
        } elseif (isset($_SESSION['cart'][$cartKey])) {
            // Apply the same stock cap add-to-cart uses. The +/− buttons post
            // straight here, so without this a customer could hold + and take
            // the quantity far past what is actually in the freezer.
            $line      = $_SESSION['cart'][$cartKey];
            $productId = (int)($line['product_id'] ?? 0);
            $available = stockAvailableFor($pdo, $productId);

            // Units of this product already committed on OTHER cart lines
            // (a different size of the same product draws on the same stock).
            // Compare as strings: PHP turns a numeric array key like "8" into
            // int 8, so a strict !== against the posted string "8" counted
            // this very line as an "other" line and made the last unit of
            // every product unsellable.
            $otherLines = 0;
            foreach ($_SESSION['cart'] as $k => $l) {
                if ((string)$k !== (string)$cartKey && (int)($l['product_id'] ?? 0) === $productId) {
                    $otherLines += (int)($l['quantity'] ?? 0);
                }
            }

            $maxForThisLine = max(0, $available - $otherLines);
            if ($qty > $maxForThisLine) {
                $qty = $maxForThisLine;
                $capped = true;
            }

            if ($qty <= 0) {
                unset($_SESSION['cart'][$cartKey]);
            } else {
                $_SESSION['cart'][$cartKey]['quantity'] = $qty;
            }
        }

        tradeCartSave($pdo);
        $summary = cartSummary();
        if (!empty($capped)) {
            $summary['message'] = 'Only ' . $qty . ' left in stock — quantity adjusted.';
            $summary['capped']  = true;
        }
        echo json_encode($summary);
        break;

    // ── CLEAR ────────────────────────────────────────────────────
    case 'clear':
        $_SESSION['cart'] = [];
        tradeCartClear($pdo);
        echo json_encode(cartSummary());
        break;

    // ── GET ──────────────────────────────────────────────────────
    case 'get':
    default:
        echo json_encode(cartSummary());
        break;
}
