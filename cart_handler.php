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

require_once __DIR__ . '/db.php';

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

        // If variant specified, fetch and validate it
        // Check if trade user is logged in
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

        $summary = cartSummary();
        echo json_encode(['message' => 'Added to cart!'] + $summary);
        break;

    // ── REMOVE ───────────────────────────────────────────────────
    case 'remove':
        $cartKey = $_POST['cart_key'] ?? (string)((int)($_POST['product_id'] ?? 0));
        unset($_SESSION['cart'][$cartKey]);
        echo json_encode(cartSummary());
        break;

    // ── UPDATE QUANTITY ──────────────────────────────────────────
    case 'update':
        $cartKey = $_POST['cart_key'] ?? (string)((int)($_POST['product_id'] ?? 0));
        $qty     = (int)($_POST['quantity'] ?? 0);
        if ($qty <= 0) {
            unset($_SESSION['cart'][$cartKey]);
        } elseif (isset($_SESSION['cart'][$cartKey])) {
            $_SESSION['cart'][$cartKey]['quantity'] = $qty;
        }
        echo json_encode(cartSummary());
        break;

    // ── CLEAR ────────────────────────────────────────────────────
    case 'clear':
        $_SESSION['cart'] = [];
        echo json_encode(cartSummary());
        break;

    // ── GET ──────────────────────────────────────────────────────
    case 'get':
    default:
        echo json_encode(cartSummary());
        break;
}
