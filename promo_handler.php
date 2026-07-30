<?php
// ============================================================
//  Creamy Bite – Promo Code Handler
//  GET  action=validate  code=XXX  cart_total=XX.XX  → JSON
//  POST action=create/update/delete (admin only)
// ============================================================
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$action = $_REQUEST['action'] ?? '';

// ── PUBLIC: Validate a promo code ─────────────────────────────
if ($action === 'validate') {
    $code       = strtoupper(trim($_GET['code'] ?? $_POST['code'] ?? ''));
    $cartTotal  = (float)($_GET['cart_total'] ?? $_POST['cart_total'] ?? 0);

    if (!empty($_SESSION['trade_user'])) {
        echo json_encode(['success' => false, 'message' => 'Promo codes cannot be applied to wholesale trade accounts.']);
        exit;
    }

    if ($code === '') {
        echo json_encode(['success' => false, 'message' => 'Please enter a promo code.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM promo_codes WHERE code = :code AND active = 1");
    $stmt->execute(['code' => $code]);
    $promo = $stmt->fetch();

    if (!$promo) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired promo code.']);
        exit;
    }

    // Check expiry
    if (!empty($promo['expires_at']) && strtotime($promo['expires_at']) < strtotime('today')) {
        echo json_encode(['success' => false, 'message' => 'This promo code has expired.']);
        exit;
    }

    // Check usage limit
    if (!is_null($promo['max_uses']) && $promo['uses_count'] >= $promo['max_uses']) {
        echo json_encode(['success' => false, 'message' => 'This promo code has reached its usage limit.']);
        exit;
    }

    // Check minimum order
    if ($cartTotal < (float)$promo['min_order']) {
        echo json_encode([
            'success' => false,
            'message' => 'Minimum order of £' . number_format($promo['min_order'], 2) . ' required for this code.',
        ]);
        exit;
    }

    // Calculate discount
    if ($promo['discount_type'] === 'percentage') {
        $discountAmount = round($cartTotal * ($promo['discount_value'] / 100), 2);
        $discountLabel  = (int)$promo['discount_value'] . '% off';
    } else {
        $discountAmount = min((float)$promo['discount_value'], $cartTotal);
        $discountLabel  = '£' . number_format($promo['discount_value'], 2) . ' off';
    }

    $newTotal = max(0, $cartTotal - $discountAmount);

    // Store in session
    $_SESSION['promo'] = [
        'id'              => $promo['id'],
        'code'            => $promo['code'],
        'discount_type'   => $promo['discount_type'],
        'discount_value'  => $promo['discount_value'],
        'discount_amount' => $discountAmount,
        'label'           => $discountLabel,
    ];

    echo json_encode([
        'success'         => true,
        'code'            => $promo['code'],
        'description'     => $promo['description'],
        'discount_amount' => number_format($discountAmount, 2),
        'discount_label'  => $discountLabel,
        'new_total'       => number_format($newTotal, 2),
        'min_order'       => (float)$promo['min_order'],   // for live frontend validation
        'message'         => '🎉 Promo applied! ' . $discountLabel,
    ]);
    exit;
}

// ── PUBLIC: Remove promo ──────────────────────────────────────
if ($action === 'remove') {
    unset($_SESSION['promo']);
    echo json_encode(['success' => true]);
    exit;
}

// ── ADMIN ONLY below this line ────────────────────────────────
if (empty($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Not authorised']);
    exit;
}

// ── ADMIN: Create promo ───────────────────────────────────────
if ($action === 'create') {
    $code        = strtoupper(trim($_POST['code']           ?? ''));
    $description = trim($_POST['description']               ?? '');
    $type        = $_POST['discount_type']                  ?? 'percentage';
    $value       = (float)($_POST['discount_value']         ?? 0);
    $minOrder    = (float)($_POST['min_order']              ?? 0);
    $maxUses     = $_POST['max_uses'] !== '' ? (int)$_POST['max_uses'] : null;
    $active      = isset($_POST['active']) ? 1 : 0;
    $expires     = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

    if ($code === '' || $value <= 0) {
        echo json_encode(['success' => false, 'message' => 'Code and discount value are required.']);
        exit;
    }
    try {
        $pdo->prepare("INSERT INTO promo_codes (code,description,discount_type,discount_value,min_order,max_uses,active,expires_at)
                       VALUES (:code,:desc,:type,:value,:min,:max,:active,:expires)")
            ->execute(['code'=>$code,'desc'=>$description,'type'=>$type,'value'=>$value,'min'=>$minOrder,'max'=>$maxUses,'active'=>$active,'expires'=>$expires]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'code' => $code]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Code already exists or DB error.']);
    }
    exit;
}

// ── ADMIN: Update promo ───────────────────────────────────────
if ($action === 'update') {
    $id          = (int)($_POST['id']                       ?? 0);
    $description = trim($_POST['description']               ?? '');
    $type        = $_POST['discount_type']                  ?? 'percentage';
    $value       = (float)($_POST['discount_value']         ?? 0);
    $minOrder    = (float)($_POST['min_order']              ?? 0);
    $maxUses     = ($_POST['max_uses'] ?? '') !== '' ? (int)$_POST['max_uses'] : null;
    $active      = isset($_POST['active']) ? 1 : 0;
    $expires     = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

    $pdo->prepare("UPDATE promo_codes SET description=:desc,discount_type=:type,discount_value=:value,min_order=:min,max_uses=:max,active=:active,expires_at=:expires WHERE id=:id")
        ->execute(['desc'=>$description,'type'=>$type,'value'=>$value,'min'=>$minOrder,'max'=>$maxUses,'active'=>$active,'expires'=>$expires,'id'=>$id]);
    echo json_encode(['success' => true]);
    exit;
}

// ── ADMIN: Toggle active ──────────────────────────────────────
if ($action === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE promo_codes SET active = 1 - active WHERE id = :id")->execute(['id' => $id]);
    $newState = (int)$pdo->prepare("SELECT active FROM promo_codes WHERE id=:id")->execute(['id'=>$id]);
    $row = $pdo->query("SELECT active FROM promo_codes WHERE id=$id")->fetch();
    echo json_encode(['success' => true, 'active' => (int)$row['active']]);
    exit;
}

// ── ADMIN: Delete promo ───────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM promo_codes WHERE id = :id")->execute(['id' => $id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
