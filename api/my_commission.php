<?php
/**
 * api/my_commission.php
 * Returns the logged-in user's own commission data:
 *   - unpaid_total   : float
 *   - unpaid_items   : array  (sale-item breakdown for uncleared rows)
 *   - payouts        : array  (payout history with acknowledged_at)
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
requireCap('canReceiveCommission');

header('Content-Type: application/json');

$db     = getDB();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// "?"? 1. Unpaid commission total "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
$stmt = $db->prepare("
    SELECT COALESCE(SUM((si.price_per_unit - p.agent_price) * si.quantity), 0) AS unpaid_total
    FROM sale_items si
    JOIN sales    s ON s.id = si.sale_id
    JOIN products p ON p.id = si.product_id
    WHERE s.user_id = ?
      AND s.status  = 'Valid'
      AND si.commission_cleared = 0
");
$stmt->execute([$userId]);
$unpaidTotal = (float) $stmt->fetchColumn();

// "?"? 2. Unpaid breakdown items "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
$stmt2 = $db->prepare("
    SELECT
        s.invoice_no,
        s.sale_date,
        p.board_type,
        si.quantity,
        si.price_per_unit,
        p.agent_price,
        si.total
    FROM sale_items si
    JOIN sales    s ON s.id = si.sale_id
    JOIN products p ON p.id = si.product_id
    WHERE s.user_id = ?
      AND s.status  = 'Valid'
      AND si.commission_cleared = 0
    ORDER BY s.sale_date DESC, s.id DESC
");
$stmt2->execute([$userId]);
$unpaidItems = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// "?"? 3. Payout history (most recent 20) "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
$stmt3 = $db->prepare("
    SELECT
        acp.id,
        acp.amount,
        acp.note,
        acp.cleared_at,
        acp.acknowledged_at,
        cb.full_name AS cleared_by_name
    FROM agent_commission_payouts acp
    JOIN users cb ON cb.id = acp.cleared_by
    WHERE acp.agent_id = ?
    ORDER BY acp.cleared_at DESC
    LIMIT 20
");
$stmt3->execute([$userId]);
$payouts = $stmt3->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'unpaid_total' => $unpaidTotal,
    'unpaid_items' => $unpaidItems,
    'payouts'      => $payouts,
]);

