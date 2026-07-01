<?php
/**
 * api/products.php ?" Product CRUD REST endpoint.
 *
 * GET  ?' returns all products as { id: { name, cat, cd, un, stock, cost, price, thr }, ? }
 * POST ?' JSON body with 'action' field:
 *   action=add    { data: {board_type, category_id, color_design, unit, cost_price, selling_price, current_stock, low_stock_threshold} }
 *   action=edit   { id, data: {...} }
 *   action=delete { id }
 *   action=adjust { id, delta, type, notes }
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();
$db  = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// "?"? GET: return all products "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
if ($method === 'GET') {
    requireCap('canViewInventory');

    $rows = $db->query("
        SELECT p.id, p.board_type, p.category_id, p.color_design,
               p.unit, p.cost_price, p.selling_price, p.current_stock,
               p.low_stock_threshold, c.name AS category_name
        FROM   products  p
        JOIN   categories c ON c.id = p.category_id
        WHERE  p.is_active = 1 AND c.is_active = 1
        ORDER  BY c.name, p.board_type
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Convert to keyed-by-id format with category_id included
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['id']] = [
            'name'   => $r['board_type'],
            'cat'    => $r['category_name'],
            'cat_id' => (int) $r['category_id'],
            'cd'     => $r['color_design'],
            'un'     => $r['unit'],
            'stock'  => (int)   $r['current_stock'],
            'cost'   => (float) $r['cost_price'],
            'price'  => (float) $r['selling_price'],
            'thr'    => (int)   $r['low_stock_threshold'],
        ];
    }
    echo json_encode($out);
    exit;
}

// "?"? POST: mutation actions "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    // "?"? ADD "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
    if ($action === 'add') {
        requireCap('canManageProducts');
        $d = $body['data'] ?? [];
        $catId = (int)($d['category_id'] ?? 0);
        if (!$catId) {
            http_response_code(400);
            echo json_encode(['error' => 'A valid category is required']);
            exit;
        }
        if (!trim($d['board_type'] ?? '')) {
            http_response_code(400);
            echo json_encode(['error' => 'Product name is required']);
            exit;
        }
        try {
            $stmt = $db->prepare("
                INSERT INTO products
                  (board_type, category_id, color_design,
                   unit, cost_price, selling_price, current_stock, low_stock_threshold)
                VALUES (?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                trim($d['board_type'] ?? ''),
                $catId,
                trim($d['color_design'] ?? ''),
                trim($d['unit']         ?? 'pcs'),
                (float)($d['cost_price']         ?? 0),
                (float)($d['selling_price']      ?? 0),
                (int)  ($d['current_stock']      ?? 0),
                (int)  ($d['low_stock_threshold']?? 5),
            ]);
            $newId = (int)$db->lastInsertId();
            logAudit($db, 'product.add', 'product', $newId,
                "Added: {$d['board_type']} / {$d['color_design']}");
            if ((int)($d['current_stock'] ?? 0) > 0) {
                logStockMovement($db, $newId, 'IN', (int)$d['current_stock'], 'Initial stock on product creation');
            }
            echo json_encode(['ok' => true, 'id' => $newId]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // "?"? EDIT "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
    if ($action === 'edit') {
        requireCap('canManageProducts');
        $id    = (int)($body['id'] ?? 0);
        $d     = $body['data'] ?? [];
        $catId = (int)($d['category_id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); exit; }
        if (!$catId) { http_response_code(400); echo json_encode(['error' => 'Category required']); exit; }
        try {
            $stmt = $db->prepare("
                UPDATE products SET
                  board_type=?, category_id=?,
                  color_design=?, unit=?, cost_price=?, selling_price=?,
                  low_stock_threshold=?
                WHERE id=?
            ");
            $stmt->execute([
                trim($d['board_type']   ?? ''),
                $catId,
                trim($d['color_design'] ?? ''),
                trim($d['unit']         ?? 'pcs'),
                (float)($d['cost_price']         ?? 0),
                (float)($d['selling_price']      ?? 0),
                (int)  ($d['low_stock_threshold']?? 5),
                $id,
            ]);
            logAudit($db, 'product.edit', 'product', $id,
                "Edited: {$d['board_type']} / {$d['color_design']}");
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // "?"? DELETE "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
    if ($action === 'delete') {
        requireCap('canManageProducts');
        $id = (int)($body['id'] ?? 0);
        try {
            $row = $db->prepare("SELECT board_type FROM products WHERE id=?");
            $row->execute([$id]);
            $name = ($row->fetchColumn() ?: "ID $id");
            $db->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
            logAudit($db, 'product.delete', 'product', $id, "Deleted: $name");
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // "?"? ADJUST STOCK "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
    if ($action === 'adjust') {
        requireCap('canAdjustStock');
        $id    = (int)  ($body['id']    ?? 0);
        $delta = (int)  ($body['delta'] ?? 0);
        $type  = in_array($body['type'] ?? '', ['IN','OUT','ADJUSTMENT'])
            ? $body['type'] : 'ADJUSTMENT';
        $notes = trim($body['notes'] ?? '');
        try {
            $stmt = $db->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id=?");
            $stmt->execute([$delta, $id]);
            logStockMovement($db, $id, $type, $delta, $notes);
            logAudit($db, 'stock.adjust', 'product', $id, "Stock $type $delta - $notes");
            $newStock = (int)$db->prepare("SELECT current_stock FROM products WHERE id=?")
                ->execute([$id]) && $db->query("SELECT current_stock FROM products WHERE id=$id")->fetchColumn();
            echo json_encode(['ok' => true, 'stock' => $newStock]);
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

