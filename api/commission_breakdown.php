<?php
/**
 * api/commission_breakdown.php
 * Returns JSON: per-sale-item commission breakdown for a given agent + date range.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireCap('canViewCommissions');

header('Content-Type: application/json');

$db      = getDB();
$agentId = (int) ($_GET['agent_id'] ?? 0);
$from    = $_GET['from'] ?? date('Y-m-01');
$to      = $_GET['to']   ?? date('Y-m-d');

if (!$agentId) {
    echo json_encode(['error' => 'Missing agent_id']);
    exit;
}

$stmt = $db->prepare("
    SELECT
        s.invoice_no,
        s.sale_date,
        p.board_type,
        si.quantity,
        si.price_per_unit,
        p.agent_price,
        si.total,
        si.id AS item_id
    FROM sale_items si
    JOIN sales s    ON s.id = si.sale_id
    JOIN products p ON p.id = si.product_id
    WHERE s.user_id = ?
      AND s.status  = 'Valid'
      AND si.commission_cleared = 0
      AND s.sale_date BETWEEN ? AND ?
    ORDER BY s.sale_date DESC, s.id DESC
");
$stmt->execute([$agentId, $from, $to]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['items' => $items]);

