<?php
/**
 * api/categories.php ?" Category CRUD REST endpoint.
 *
 * GET  ?' [{id, name, description, product_count}, ?]
 * POST ?' action=add    { name, description }
 *         action=edit   { id, name, description }
 *         action=delete { id }
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $rows = $db->query("
        SELECT c.id, c.name, c.description,
               COUNT(p.id) AS product_count
        FROM   categories c
        LEFT   JOIN products p ON p.category_id = c.id AND p.is_active = 1
        WHERE  c.is_active = 1
        GROUP  BY c.id
        ORDER  BY c.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['product_count'] = (int)$r['product_count']; }
    echo json_encode($rows);
    exit;
}

if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!$body) {
        http_response_code(400); 
        echo json_encode(['error' => 'Invalid JSON input']);
        exit;
    }
    $action = $body['action'] ?? '';

    if ($action === 'add') {
        requireCap('canManageCategories');
        $name = strtoupper(trim($body['name'] ?? ''));
        $desc = trim($body['description'] ?? '');
        if (!$name) { http_response_code(400); echo json_encode(['error' => 'Name required']); exit; }
        $db->prepare("INSERT INTO categories (name, description, is_active) VALUES (?,?,1)")->execute([$name, $desc]);
        $newId = (int) $db->lastInsertId();
        logAudit($db, 'category.add', 'category', $newId, "Added: $name");
        echo json_encode(['ok' => true, 'id' => $newId]);
        exit;
    }

    if ($action === 'edit') {
        requireCap('canManageCategories');
        $id   = (int) ($body['id'] ?? 0);
        $name = strtoupper(trim($body['name'] ?? ''));
        $desc = trim($body['description'] ?? '');
        $db->prepare("UPDATE categories SET name=?, description=? WHERE id=?")->execute([$name, $desc, $id]);
        logAudit($db, 'category.edit', 'category', $id, "Edited: $name");
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        requireCap('canManageCategories');
        $id = (int) ($body['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); exit; }

        // 1. Check for Active Products
        $activeCnt = (int) $db->query("SELECT COUNT(*) FROM products WHERE category_id=$id AND is_active = 1")->fetchColumn();
        if ($activeCnt > 0) {
            http_response_code(409);
            echo json_encode(['error' => "Cannot remove - category still has $activeCnt active product(s). Remove them from inventory first."]);
            exit;
        }

        try {
            // 2. Try Hard Delete
            $nameRow = $db->query("SELECT name FROM categories WHERE id=$id")->fetch();
            if (!$nameRow) { http_response_code(404); echo json_encode(['error' => 'Category not found']); exit; }
            $name = $nameRow['name'];

            $db->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
            logAudit($db, 'category.delete', 'category', $id, "Physically deleted category: $name");
            echo json_encode(['ok' => true, 'mode' => 'hard']);
        } catch (PDOException $e) {
            // 3. Fallback to Soft Delete if constraint fails
            if ($e->getCode() == '23000') { // Integrity constraint violation
                $db->prepare("UPDATE categories SET is_active=0 WHERE id=?")->execute([$id]);
                logAudit($db, 'category.soft_delete', 'category', $id, "Hydrated/Hidden category (has historical items): $name");
                echo json_encode(['ok' => true, 'mode' => 'soft', 'msg' => 'Category has historical data (sales or products). It has been hidden instead of physically deleted.']);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
            }
        }
        exit;
    }

    http_response_code(400); echo json_encode(['error' => 'Unknown action']); exit;
}

http_response_code(405); echo json_encode(['error' => 'Method not allowed']);

