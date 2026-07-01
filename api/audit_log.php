<?php
/**
 * api/audit_log.php ?" Returns audit_log entries from MySQL as JSON.
 *
 * GET  ?' [{id, action, target_type, target_id, detail, ip_address, created_at, username, full_name, role}, ?]
 * POST ?' action=clear ?" deletes all audit log entries (ITSupport / Administrator only)
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();
requireCap('canViewAuditLog');

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $rows = $db->query("
        SELECT
            al.id,
            al.action,
            al.target_type  AS targetType,
            al.target_id    AS targetId,
            al.detail,
            al.ip_address,
            al.created_at,
            u.username,
            u.full_name,
            u.role
        FROM   audit_log al
        JOIN   users     u  ON u.id = al.user_id
        ORDER  BY al.created_at DESC
        LIMIT  2000
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['targetId'] = $r['targetId'] ? (int) $r['targetId'] : null;
    }

    echo json_encode(array_values($rows));
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'clear') {
        // Only Administrator / ITSupport can clear the entire audit log
        requireCap('canManageUsers');
        $db->query("DELETE FROM audit_log");
        logAudit($db, 'audit_log.clear', 'audit_log', 0, 'Audit log cleared by ' . ($_SESSION['username'] ?? '?'));
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);

