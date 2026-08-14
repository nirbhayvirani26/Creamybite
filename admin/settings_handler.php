<?php
// ============================================================
//  Creamy Bite – Admin: Order Taking Handler
//  Action: toggle (audience = retail|trade, method = delivery|collection)
// ============================================================
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config.php';
require_once '../db.php';
require_once '../order_settings.php';

$action = $_POST['action'] ?? '';

if ($action === 'toggle') {
    $audience = trim($_POST['audience'] ?? '');
    $method   = trim($_POST['method']   ?? '');

    if (!in_array($audience, ORDER_AUDIENCES, true) || !in_array($method, ORDER_METHODS, true)) {
        echo json_encode(['success' => false, 'message' => 'Unknown setting']);
        exit;
    }

    $enabled = !isOrderMethodEnabled($pdo, $audience, $method);

    if (!setOrderMethodEnabled($pdo, $audience, $method, $enabled)) {
        echo json_encode(['success' => false, 'message' => 'Could not save setting']);
        exit;
    }

    echo json_encode([
        'success'  => true,
        'audience' => $audience,
        'method'   => $method,
        'enabled'  => $enabled,
        'message'  => ($enabled ? 'Now taking ' : 'Stopped taking ') . $method
                      . ' orders for ' . strtolower(orderAudienceLabel($audience)),
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
