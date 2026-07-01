<?php
/**
 * api/change_password.php ?" Allows a logged-in user to change their own password.
 *
 * POST { current_password, new_password }
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$currentPw  = $body['current_password'] ?? '';
$newPw      = $body['new_password']     ?? '';

if (!$currentPw || !$newPw) {
    http_response_code(400);
    echo json_encode(['error' => 'Both current and new password are required']);
    exit;
}
if (strlen($newPw) < 6) {
    http_response_code(400);
    echo json_encode(['error' => 'New password must be at least 6 characters']);
    exit;
}

$db = getDB();
$userId = (int)$_SESSION['user_id'];

// Fetch current hash
$stmt = $db->prepare("SELECT password FROM users WHERE id=?");
$stmt->execute([$userId]);
$hash = $stmt->fetchColumn();

if (!$hash || !password_verify($currentPw, $hash)) {
    http_response_code(403);
    echo json_encode(['error' => 'Current password is incorrect']);
    exit;
}

// Update with new bcrypt hash
$newHash = password_hash($newPw, PASSWORD_BCRYPT);
try {
    $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([$newHash, $userId]);
    logAudit($db, 'user.pw_change', 'user', $userId, 'Password changed via profile page');
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

