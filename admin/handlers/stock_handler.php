<?php
// ============================================================
//  Creamy Bite – Admin: Stock Handler
//  Actions: increment_stock (add stock, damage, offline)
// ============================================================
$GLOBALS['ADMIN_GUARD_JSON'] = true;   // reply in JSON, not a redirect
require_once __DIR__ . '/../_guard.php';
header('Content-Type: application/json');
csrfCheckJson();
require_once __DIR__ . '/../_permissions.php';
adminRequire('stock');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

// ── Helper: column existence check ────────────────────────
function columnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $st->execute([$table, $column]);
        return (int)$st->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// ── Helper: fetch current stock row ───────────────────────
function fetchStockRow(PDO $pdo, int $productId): array {
    $hasTotal   = columnExists($pdo, 'products', 'total_stock');
    $hasDamage  = columnExists($pdo, 'products', 'damage_stock');
    $hasOffline = columnExists($pdo, 'products', 'sold_offline');
    $hasOnline  = columnExists($pdo, 'products', 'sold_online');

    $parts = [
        "IFNULL(stock_qty, 0) AS stock_qty",
        $hasTotal   ? "IFNULL(total_stock, 0) AS total_stock"   : "IFNULL(stock_qty, 0) AS total_stock",
        $hasDamage  ? "IFNULL(damage_stock, 0) AS damage_stock" : "0 AS damage_stock",
        $hasOffline ? "IFNULL(sold_offline, 0) AS sold_offline" : "0 AS sold_offline",
        $hasOnline  ? "IFNULL(sold_online, 0) AS sold_online"   : "0 AS sold_online",
    ];

    $st = $pdo->prepare("SELECT " . implode(', ', $parts) . " FROM products WHERE id = :id");
    $st->execute(['id' => $productId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $ts  = (int)($row['total_stock']  ?? 0);
    $dmg = (int)($row['damage_stock'] ?? 0);
    $off = (int)($row['sold_offline'] ?? 0);
    $sol = (int)($row['sold_online']  ?? 0);

    return [
        'total_stock'  => $ts,
        'damage_stock' => $dmg,
        'sold_offline' => $off,
        'sold_online'  => $sol,
        'in_stock'     => max(0, $ts - $dmg - $off - $sol),
    ];
}

$action = trim($_POST['action'] ?? '');

// ── Increment stock fields ─────────────────────────────────
// Adds the given quantities to the running totals.
// add_qty       → increments total_stock (Grand Total)
// damage_qty    → increments damage_stock
// offline_qty   → increments sold_offline
if ($action === 'increment_stock') {
    $productId  = (int)($_POST['product_id']  ?? 0);
    $addQty     = max(0, (int)($_POST['add_qty']     ?? 0));
    $damageQty  = max(0, (int)($_POST['damage_qty']  ?? 0));
    $offlineQty = max(0, (int)($_POST['offline_qty'] ?? 0));

    if ($productId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
        exit;
    }
    if ($addQty === 0 && $damageQty === 0 && $offlineQty === 0) {
        echo json_encode(['success' => false, 'message' => 'Enter at least one quantity.']);
        exit;
    }

    // Check required columns exist
    if (!columnExists($pdo, 'products', 'total_stock')) {
        echo json_encode(['success' => false, 'message' => 'Please run setup_stock.php first to enable stock tracking.']);
        exit;
    }

    try {
        $parts = [];
        $params = ['id' => $productId];

        if ($addQty > 0) {
            $parts[]          = '`total_stock` = `total_stock` + :add_qty';
            $params['add_qty'] = $addQty;
        }
        if ($damageQty > 0 && columnExists($pdo, 'products', 'damage_stock')) {
            $parts[]             = '`damage_stock` = `damage_stock` + :damage_qty';
            $params['damage_qty'] = $damageQty;
        }
        if ($offlineQty > 0 && columnExists($pdo, 'products', 'sold_offline')) {
            $parts[]              = '`sold_offline` = `sold_offline` + :offline_qty';
            $params['offline_qty'] = $offlineQty;
        }

        if (!empty($parts)) {
            $pdo->prepare("UPDATE products SET " . implode(', ', $parts) . " WHERE id = :id")
                ->execute($params);
        }

        // Keep stock_qty in step with the running totals.
        //
        // This handler only ever moved total_stock / damage_stock /
        // sold_offline, while the storefront sells from stock_qty — so adding
        // stock in the admin panel left the shop still showing sold out, and
        // writing off damage or recording an offline sale never reduced what
        // customers could buy.
        //
        //   stock_qty = total_stock - damage_stock - sold_offline - sold_online
        $pdo->prepare(
            "UPDATE products
                SET stock_qty = GREATEST(0, total_stock - damage_stock - sold_offline - sold_online)
              WHERE id = :id"
        )->execute(['id' => $productId]);

        $row = fetchStockRow($pdo, $productId);
        echo json_encode(array_merge(['success' => true], $row));

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
