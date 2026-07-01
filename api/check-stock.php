<?php
/**
 * api/check-stock.php
 * Checks available stock for a product.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (!isset($_GET['product_id'])) {
    echo json_encode(['error' => 'Missing product_id']);
    exit;
}

$db = getDB();
$pid = (int)$_GET['product_id'];
$qty = isset($_GET['qty']) ? (int)$_GET['qty'] : 1;

$stmt = $db->prepare("SELECT current_stock, unit FROM products WHERE id = ?");
$stmt->execute([$pid]);
$prod = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prod) {
    echo json_encode(['error' => 'Product not found']);
    exit;
}

$stock = (int)$prod['current_stock'];
$available = $stock >= $qty;

echo json_encode([
    'product_id' => $pid,
    'current_stock' => $stock,
    'requested_qty' => $qty,
    'available' => $available,
    'unit' => $prod['unit'],
    'message' => $available ? "Yes ({$stock} in stock)" : "No (Only {$stock} in stock)"
]);

