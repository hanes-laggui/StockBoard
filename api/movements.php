<?php
/**
 * api/movements.php ?" Read stock movements from MySQL.
 *
 * GET ?' [{id, product_id, product_name, type, quantity, notes, created_at, username}, ?]
 *
 * Used by stock_movements.html in server mode to show real movement history.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();
requireCap('canViewMovements');

$db = getDB();

$rows = $db->query("
    SELECT
        sm.id,
        sm.product_id,
        p.board_type       AS product_name,
        p.color_design     AS color_design,
        sm.type,
        sm.quantity,
        sm.notes,
        sm.created_at,
        u.username,
        u.full_name
    FROM   stock_movements sm
    JOIN   products  p ON p.id = sm.product_id
    JOIN   users     u ON u.id = sm.user_id
    ORDER  BY sm.created_at DESC
    LIMIT  500
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
    $r['id']         = (int) $r['id'];
    $r['product_id'] = (int) $r['product_id'];
    $r['quantity']   = (int) $r['quantity'];
}

echo json_encode(array_values($rows));

