<?php
/**
 * api/users.php ?" User CRUD REST endpoint.
 *
 * GET  ?' [{id, username, full_name, role, is_active, is_pending, created_at}, ?]
 * POST ?' action=add    { uname, fname, role, active, password }
 *         action=edit   { id, uname, fname, role, active, ?password }
 *         action=toggle { id, active }
 *         action=approve { id, role }   ?" approve pending self-registration
 *         action=delete { id }
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();
requireCap('canManageUsers');

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// "?"? GET: list all users "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
if ($method === 'GET') {
    $rows = $db->query("
        SELECT id, username, full_name, role, is_active, is_pending, created_at
        FROM   users
        ORDER  BY is_pending DESC, role, username
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id']         = (int)  $r['id'];
        $r['is_active']  = (bool) $r['is_active'];
        $r['is_pending'] = (bool) $r['is_pending'];
    }
    echo json_encode(array_values($rows));
    exit;
}

// "?"? POST: mutations "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    // "?"? ADD "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
    if ($action === 'add') {
        $uname    = trim($body['uname']    ?? '');
        $fname    = trim($body['fname']    ?? '');
        $role     = $body['role']          ?? 'SalesCashier';
        $active   = (int) ($body['active'] ?? 1);
        $password = $body['password']      ?? '';

        if (!$uname || !$fname) {
            http_response_code(400);
            echo json_encode(['error' => 'Username and full name required']);
            exit;
        }
        if (strlen($password) < 6) {
            http_response_code(400);
            echo json_encode(['error' => 'Password must be at least 6 characters']);
            exit;
        }

        $validRoles = ['Administrator','Manager','OnlineAgent','SalesCashier','InventoryOfficer'];
        if (!in_array($role, $validRoles)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid role']);
            exit;
        }

        // Check username uniqueness
        $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE username=?");
        $chk->execute([$uname]);
        if ((int)$chk->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['error' => "Username \"$uname\" already exists"]);
            exit;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        try {
            $db->prepare("INSERT INTO users (username, password, full_name, role, is_active)
                          VALUES (?,?,?,?,?)")
               ->execute([$uname, $hash, $fname, $role, $active]);
            $newId = (int)$db->lastInsertId();
            logAudit($db, 'user.add', 'user', $newId, "Added user: $uname ($role)");
            echo json_encode(['ok' => true, 'id' => $newId]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // "?"? EDIT "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
    if ($action === 'edit') {
        $id     = (int)  ($body['id']     ?? 0);
        $uname  = trim($body['uname']     ?? '');
        $fname  = trim($body['fname']     ?? '');
        $role   = $body['role']           ?? '';
        $active = (int)  ($body['active'] ?? 1);
        $pw     = $body['password']       ?? '';

        if (!$id || !$uname || !$fname) {
            http_response_code(400);
            echo json_encode(['error' => 'ID, username, and name required']);
            exit;
        }
        try {
            $db->prepare("UPDATE users SET username=?, full_name=?, role=?, is_active=? WHERE id=?")
               ->execute([$uname, $fname, $role, $active, $id]);
            if ($pw && strlen($pw) >= 6) {
                $hash = password_hash($pw, PASSWORD_BCRYPT);
                $db->prepare("UPDATE users SET password=? WHERE id=?")
                   ->execute([$hash, $id]);
            }
            logAudit($db, 'user.edit', 'user', $id, "Edited user: $uname ($role)");
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // "?"? TOGGLE ACTIVE "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
    if ($action === 'toggle') {
        $id     = (int) ($body['id']     ?? 0);
        $active = (int) ($body['active'] ?? 0);
        // Prevent self-deactivation
        if ($id === (int)($_SESSION['user_id'] ?? 0) && $active === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Cannot deactivate your own account']);
            exit;
        }
        try {
            $db->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$active, $id]);
            logAudit($db, $active ? 'user.activated' : 'user.deactivated', 'user', $id, "User $id set active=$active");
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // "?"? APPROVE (pending self-registration) "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
    if ($action === 'approve') {
        $id   = (int) ($body['id']   ?? 0);
        $role = trim($body['role']   ?? '');
        $validRoles = ['Administrator','Manager','OnlineAgent','SalesCashier','InventoryOfficer'];
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'User ID required']);
            exit;
        }
        if (!in_array($role, $validRoles, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'A valid role must be selected to approve']);
            exit;
        }
        try {
            $row = $db->prepare("SELECT username FROM users WHERE id=? AND is_pending=1");
            $row->execute([$id]);
            $uname = $row->fetchColumn();
            if (!$uname) {
                http_response_code(404);
                echo json_encode(['error' => 'Pending user not found']);
                exit;
            }
            $db->prepare("UPDATE users SET role=?, is_active=1, is_pending=0 WHERE id=?")
               ->execute([$role, $id]);
            logAudit($db, 'user.approved', 'user', $id, "Approved registration for: $uname, assigned role: $role");
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // "?"? DELETE "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
    if ($action === 'delete') {
        $id = (int)($body['id'] ?? 0);
        // Prevent self-delete
        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            http_response_code(403);
            echo json_encode(['error' => 'Cannot delete your own account']);
            exit;
        }
        try {
            $row = $db->prepare("SELECT username FROM users WHERE id=?");
            $row->execute([$id]);
            $uname = $row->fetchColumn() ?: "ID $id";
            $db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
            logAudit($db, 'user.delete', 'user', $id, "Deleted user: $uname");
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);

