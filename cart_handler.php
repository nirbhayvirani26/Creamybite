<?php
// ============================================================
//  Creamy Bite – Cart AJAX Handler (with Variant Support)
//  POST actions: add | remove | update | clear | get
//  Cart key format:
//    - No variant:  "42"
//    - With variant: "42:7"  (product_id:variant_id)
// ============================================================
require_once __DIR__ . '/includes/session.php';
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

/**
 * How many units one press of +/− moves this line by.
 *
 * Trade customers buy by the case: we do not break a case open to send a
 * single tub, so a 500ml that cases in eights goes into a trade basket eight
 * at a time and comes out eight at a time. Retail customers buy singles and
 * always step by one.
 *
 * Enforced here rather than in the page's JavaScript because the buttons post
 * a plain quantity — anyone can post whatever number they like, and a rule
 * that only exists in the browser is not a rule. The step is also sent back
 * to the page so the +/− buttons move by a whole case instead of by one and
 * appearing to do nothing.
 */
function cbCaseStep(array $product, ?array $variant, bool $isTradeUser): int
{
    if (!$isTradeUser) {
        return 1;
    }
    // The size's own case wins over the product's: a 5L catering tub and a
    // 500ml tub of the same flavour do not case the same way.
    $qty = (int)($variant['case_qty'] ?? 0);
    if ($qty <= 0) {
        $qty = (int)($product['case_qty'] ?? 0);
    }
    return $qty > 0 ? $qty : 1;
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
        $variant        = null;
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

        // One case, not one tub, for a trade basket.
        $step = cbCaseStep($product, $variant, $isTradeUser);

        // Cart key
        $cartKey = $variantId > 0 ? "{$productId}:{$variantId}" : (string)$productId;

        // Don't let the basket exceed what is actually in stock. Each size has
        // its own stock, so only the lines for THIS size count against it — a
        // basket holding six 1L tubs must not eat into what is left of the
        // 500ml.
        $availableUnits = stockAvailableFor($pdo, $productId, $variantId);
        $stockLabel     = $product['name'] . ($variantName !== null ? ' (' . $variantName . ')' : '');
        $alreadyInCart  = 0;
        foreach ($_SESSION['cart'] as $line) {
            if ((int)($line['product_id'] ?? 0) === $productId
                && (int)($line['variant_id'] ?? 0) === $variantId) {
                $alreadyInCart += (int)($line['quantity'] ?? 0);
            }
        }
        // A whole case has to fit, not just one tub — half a case is not
        // something we can send, so refuse rather than part-fill it.
        if ($alreadyInCart + $step > $availableUnits) {
            echo json_encode([
                'success' => false,
                'message' => $availableUnits <= 0
                    ? $stockLabel . ' has sold out.'
                    : ($step > 1
                        ? 'Not enough stock for a full case of ' . $stockLabel
                          . ' — ' . max(0, $availableUnits - $alreadyInCart) . ' left, a case is ' . $step . '.'
                        : 'Only ' . $availableUnits . ' of ' . $stockLabel . ' left in stock.'),
            ]);
            exit;
        }

        if (isset($_SESSION['cart'][$cartKey])) {
            $_SESSION['cart'][$cartKey]['quantity'] += $step;
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
                'quantity'    => $step,
                // Sent back so the +/− buttons move by a whole case. Stored on
                // the line so the cart can label it "1 case (8 × 500ml)"
                // without re-querying the product for every render.
                'case_qty'    => $step,
            ];
        }
        // Keep the step current on lines saved before this rule existed, and
        // for a basket carried over from before the customer signed in.
        $_SESSION['cart'][$cartKey]['case_qty'] = $step;

        tradeCartSave($pdo);
        $summary = cartSummary();
        $msg = $step > 1
            ? 'Added 1 case (' . $step . ' × ' . ($variantName ?: $product['name']) . ')'
            : 'Added to cart!';
        echo json_encode(['message' => $msg] + $summary);
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
            $variantId = (int)($line['variant_id'] ?? 0);
            $available = stockAvailableFor($pdo, $productId, $variantId);

            // Units of THIS SIZE already committed on other cart lines. Sizes
            // hold their own stock now, so a 1L line places no claim on the
            // 500ml and must not be counted here.
            //
            // Compare the keys as strings: PHP turns a numeric array key like
            // "8" into int 8, so a strict !== against the posted string "8"
            // counted this very line as an "other" line and made the last unit
            // of every product unsellable.
            $otherLines = 0;
            foreach ($_SESSION['cart'] as $k => $l) {
                if ((string)$k !== (string)$cartKey
                    && (int)($l['product_id'] ?? 0) === $productId
                    && (int)($l['variant_id'] ?? 0) === $variantId) {
                    $otherLines += (int)($l['quantity'] ?? 0);
                }
            }

            // Trade lines move a case at a time. The posted number is rounded
            // to a whole number of cases so a hand-crafted request for 3 of an
            // 8-per-case item cannot get through — and dropping below one full
            // case removes the line rather than leaving a part case behind.
            $step = max(1, (int)($line['case_qty'] ?? 1));
            if ($step > 1 && $qty > 0) {
                $rounded = (int)(round($qty / $step) * $step);
                if ($rounded < $step) {
                    $rounded = $step;
                }
                if ($rounded !== $qty) {
                    $qty = $rounded;
                    $caseAdjusted = true;
                }
            }

            $maxForThisLine = max(0, $available - $otherLines);
            if ($qty > $maxForThisLine) {
                // Round DOWN to the last whole case that still fits, so the cap
                // never leaves a part case in the basket.
                $qty = $step > 1
                    ? (int)(floor($maxForThisLine / $step) * $step)
                    : $maxForThisLine;
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
            $summary['message'] = $qty > 0
                ? 'Only ' . $qty . ' left in stock — quantity adjusted.'
                : 'Not enough stock left for a full case.';
            $summary['capped']  = true;
        } elseif (!empty($caseAdjusted)) {
            $summary['message'] = 'Trade orders go out in full cases — rounded to ' . $qty . '.';
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
