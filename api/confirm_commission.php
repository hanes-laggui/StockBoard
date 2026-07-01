<?php
/**
 * api/confirm_commission.php
 * POST: sets acknowledged_at = NOW() on a specific payout belonging to the logged-in user.
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
requireCap('canReceiveCommission');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$db       = getDB();
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$payoutId = (int) ($_POST['payout_id'] ?? 0);

if (!$payoutId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing payout_id']);
    exit;
}

// Verify the payout belongs to this user and is not yet acknowledged
$stmt = $db->prepare("SELECT id, acknowledged_at FROM agent_commission_payouts WHERE id = ? AND agent_id = ?");
$stmt->execute([$payoutId, $userId]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Payout not found or does not belong to you']);
    exit;
}

if ($row['acknowledged_at']) {
    echo json_encode(['ok' => true, 'already_acknowledged' => true]);
    exit;
}

$db->prepare("UPDATE agent_commission_payouts SET acknowledged_at = NOW() WHERE id = ?")
   ->execute([$payoutId]);

logAudit($db, 'commission.acknowledged', 'commission_payout', $payoutId,
    "User #{$userId} acknowledged receipt of payout #{$payoutId}");

echo json_encode(['ok' => true, 'acknowledged_at' => date('Y-m-d H:i:s')]);

