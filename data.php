<?php
// ============================================================
//  Sweet Scoops – Product Data Layer
// ============================================================
require_once __DIR__ . '/db.php';

$products = [];
try {
    $stmt = $pdo->query("SELECT * FROM products WHERE available = 1 ORDER BY id ASC");
    while ($row = $stmt->fetch()) {
        $products[$row['id']] = $row;
    }
} catch (PDOException $e) {
    error_log("Product fetch error: " . $e->getMessage());
}
