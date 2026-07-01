<?php
/**
 * api/sales.php ?" Sales CRUD REST endpoint.
 *
 * GET  ?' returns flat sale-item array (one row per line item, with inv/date/notes from parent)
 * POST body with 'action':
 *   action=record  { inv, date, notes, items: [{pid, name, cat, cd, un, qty, ppu}] }
 *   action=edit    { id, pid, old_qty, qty, ppu }
 *   action=delete  { id }  ?" also restores stock
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// "?"? GET: all sale line items "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
if ($method === 'GET') {
    requireCap('canViewSales');

    $rows = $db->query("
        SELECT
            si.id,
            s.invoice_no      AS inv,
            s.sale_date       AS date,
            s.notes,
            si.product_id     AS pid,
            p.board_type      AS name,
            c.name            AS cat,
            p.color_design    AS cd,
            p.unit            AS un,
            si.quantity       AS qty,
            si.price_per_unit AS ppu,
            si.total
        FROM   sale_items si
        JOIN   sales      s  ON s.id  = si.sale_id
        JOIN   products   p  ON p.id  = si.product_id
        JOIN   categories c  ON c.id  = p.category_id
        ORDER  BY s.sale_date DESC, s.id DESC, si.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Cast types for JS
    foreach ($rows as &$r) {
        $r['id']    = (int)   $r['id'];
        $r['pid']   = (int)   $r['pid'];
        $r['qty']   = (int)   $r['qty'];
        $r['ppu']   = (float) $r['ppu'];
        $r['total'] = (float) $r['total'];
    }
    echo json_encode(array_values($rows));
    exit;
}

// "?"? POST: mutations "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    // "?"? RECORD "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
    if ($action === 'record') {
        requireCap('canRecordSales');
        $inv   = trim($body['inv']   ?? '');
        $date  = trim($body['date']  ?? date('Y-m-d'));
        $notes = trim($body['notes'] ?? '');
        $items = $body['items'] ?? [];
        if (!$inv || empty($items)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invoice and items required']);
            exit;
        }

        $total = array_sum(array_map(fn($i) => $i['qty'] * $i['ppu'], $items));

        // Insert sale header
        $db->prepare("INSERT INTO sales (user_id, invoice_no, total_amount, notes, sale_date)
                      VALUES (?,?,?,?,?)")
           ->execute([$_SESSION['user_id'], $inv, $total, $notes, $date]);
        $saleId = (int) $db->lastInsertId();

        $newItems = [];
        foreach ($items as $item) {
            $pid = (int)   $item['pid'];
            $qty = (int)   $item['qty'];
            $ppu = (float) $item['ppu'];
            $lineTotal = $qty * $ppu;

            // Check stock
            $avail = (int) $db->query("SELECT current_stock FROM products WHERE id=$pid")->fetchColumn();
            if ($qty > $avail) {
                // Rollback head insert and abort
                $db->prepare("DELETE FROM sales WHERE id=?")->execute([$saleId]);
                http_response_code(409);
                echo json_encode(['error' => "Insufficient stock for product ID $pid (have $avail, want $qty)"]);
                exit;
            }

            // Insert line item
            $db->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, price_per_unit, total)
                          VALUES (?,?,?,?,?)")
               ->execute([$saleId, $pid, $qty, $ppu, $lineTotal]);
            $itemId = (int) $db->lastInsertId();

            // Deduct stock
            $db->prepare("UPDATE products SET current_stock = current_stock - ? WHERE id=?")
               ->execute([$qty, $pid]);

            logStockMovement($db, $pid, 'SALE', -$qty, "Sale $inv");

            $newItems[] = [
                'id'  => $itemId,
                'inv' => $inv, 'date' => $date, 'notes' => $notes,
                'pid' => $pid, 'name' => $item['name'], 'cat' => $item['cat'],
                'cd'  => $item['cd'] ?? '', 'un' => $item['un'] ?? 'pcs',
                'qty' => $qty, 'ppu' => $ppu, 'total' => $lineTotal,
            ];
        }
        logAudit($db, 'sale.record', 'sale', $saleId, "Recorded sale $inv - Total $total");
        echo json_encode(['ok' => true, 'sale_id' => $saleId, 'items' => $newItems]);
        exit;
    }

    // "?"? EDIT "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
    if ($action === 'edit') {
        requireCap('canRecordSales');
        $id     = (int)   ($body['id']      ?? 0);
        $pid    = (int)   ($body['pid']     ?? 0);
        $oldQty = (int)   ($body['old_qty'] ?? 0);
        $newQty = (int)   ($body['qty']     ?? 0);
        $ppu    = (float) ($body['ppu']     ?? 0);
        if (!$id || !$newQty) {
            http_response_code(400); echo json_encode(['error' => 'Invalid data']); exit;
        }
        $diff = $newQty - $oldQty; // positive = need more stock
        if ($diff > 0) {
            $avail = (int) $db->query("SELECT current_stock FROM products WHERE id=$pid")->fetchColumn();
            if ($diff > $avail) {
                http_response_code(409);
                echo json_encode(['error' => "Only $avail units available"]);
                exit;
            }
        }
        $db->prepare("UPDATE sale_items SET quantity=?, price_per_unit=?, total=? WHERE id=?")
           ->execute([$newQty, $ppu, $newQty * $ppu, $id]);
        if ($pid && $diff !== 0) {
            $db->prepare("UPDATE products SET current_stock = current_stock - ? WHERE id=?")
               ->execute([$diff, $pid]);
            logStockMovement($db, $pid, 'ADJUSTMENT', -$diff, "Sale item #$id edited");
        }
        // Update sale total
        $db->query("UPDATE sales s JOIN (SELECT sale_id, SUM(total) t FROM sale_items WHERE sale_id=(SELECT sale_id FROM sale_items WHERE id=$id)) x ON x.sale_id=s.id SET s.total_amount=x.t");
        logAudit($db, 'sale.edit', 'sale', $id, "Edited sale item #$id qty: $oldQty -> $newQty");
        echo json_encode(['ok' => true]);
        exit;
    }

    // "?"? DELETE "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
    if ($action === 'delete') {
        requireCap('canRecordSales');
        $id = (int) ($body['id'] ?? 0);
        // Get needed info before deleting
        $item = $db->prepare("SELECT si.product_id, si.quantity, s.invoice_no
            FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE si.id=?");
        $item->execute([$id]);
        $row = $item->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // Restore stock
            $db->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id=?")
               ->execute([(int)$row['quantity'], (int)$row['product_id']]);
            logStockMovement($db, (int)$row['product_id'], 'IN',
                (int)$row['quantity'], "Stock restored - sale item #$id deleted");
        }
        $db->prepare("DELETE FROM sale_items WHERE id=?")->execute([$id]);
        // Clean up empty sale headers
        $db->query("DELETE FROM sales WHERE id NOT IN (SELECT DISTINCT sale_id FROM sale_items)");
        logAudit($db, 'sale.delete', 'sale', $id, "Deleted sale item #$id (inv: {$row['invoice_no']})");
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);

