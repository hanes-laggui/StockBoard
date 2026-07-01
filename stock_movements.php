?<?php
/**
 * stock_movements.php - Stock movement history.
 * Accessible by Manager, InventoryOfficer, WarehouseStaff.
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/stock_status.php';
requireCap('canViewMovements');

$db   = getDB();
$user = currentUser();
$flash = null;

// -- Quick Stock Adjustment POST (for WarehouseStaff) --------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stock_adjust') {
    if (!canAdjustStock()) {
        $flash = ['type' => 'err', 'msg' => 'Access denied.'];
    } else {
        $pid   = (int)($_POST['product_id'] ?? 0);
        $qty   = (int)($_POST['adj_qty']    ?? 0);
        $notes = trim($_POST['adj_notes']   ?? '');
        $dir   = ($_POST['adj_type'] === 'IN') ? 1 : -1;
        $mvType = ($_POST['adj_type'] === 'IN') ? 'IN' : 'OUT';

        if ($qty <= 0) {
            $flash = ['type' => 'err', 'msg' => 'Quantity must be positive.'];
        } else {
            $db->prepare('UPDATE products SET current_stock = current_stock + ? WHERE id=?')
               ->execute([$dir * $qty, $pid]);
            logStockMovement($db, $pid, 'ADJUSTMENT', $dir * $qty, $notes ?: 'Manual adjustment from movements page');
            logAudit($db, 'stock.adjust', 'product', $pid,
                "Stock {$_POST['adj_type']} {$qty} units via stock_movements page. Notes: $notes");
            $flash = ['type' => 'ok', 'msg' => "Stock adjusted ({$_POST['adj_type']} $qty)."];
        }
    }
}

// -- Filters -------------------------------------------------------
$fromDate  = $_GET['from']       ?? date('Y-m-01');
$toDate    = $_GET['to']         ?? date('Y-m-d');
$filterPid = $_GET['product_id'] ?? '';
$filterType = $_GET['type']      ?? '';

// Products for dropdown
$products = $db->query(
    'SELECT p.id, p.board_type, c.name AS category FROM products p
     JOIN categories c ON c.id = p.category_id ORDER BY p.board_type'
)->fetchAll();

// Build query
$params = [$fromDate, $toDate];
$where  = '';
if ($filterPid !== '') {
    $where   .= ' AND sm.product_id = ?';
    $params[] = $filterPid;
}
if ($filterType !== '') {
    $where   .= ' AND sm.type = ?';
    $params[] = $filterType;
}

$stmt = $db->prepare("
    SELECT sm.id, sm.type, sm.quantity, sm.notes, sm.created_at,
           p.board_type, p.color_design, p.unit, p.current_stock,
           c.name AS category,
           u.username, u.full_name
    FROM stock_movements sm
    JOIN products p ON p.id = sm.product_id
    JOIN categories c ON c.id = p.category_id
    JOIN users u ON u.id = sm.user_id
    WHERE DATE(sm.created_at) BETWEEN ? AND ? $where
    ORDER BY sm.created_at DESC
    LIMIT 1000
");
$stmt->execute($params);
$movements = $stmt->fetchAll();

// Summary counts
$typeCount = array_count_values(array_column($movements, 'type'));

// CSV export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="stock_movements_' . $fromDate . '_to_' . $toDate . '.csv"');
    $f = fopen('php://output', 'w');
    fputcsv($f, ['ID','Timestamp','Product','Category','Type','Quantity','Unit','Notes','User']);
    foreach ($movements as $r) {
        fputcsv($f, [
            $r['id'], $r['created_at'], $r['board_type'], $r['category'],
            $r['type'], $r['quantity'], $r['unit'], $r['notes'], $r['full_name'],
        ]);
    }
    fclose($f); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Stock Movements - StockBoard</title>
  <link rel="stylesheet" href="css/style.css?v=5"/>
  <style>
    .mv-badge { display:inline-block;padding:.2rem .55rem;border-radius:4px;font-size:.72rem;font-weight:700; }
    .mv-IN   { background:rgba(16,185,129,.12); color:#065f46; border:1px solid rgba(16,185,129,.3); }
    .mv-OUT  { background:rgba(220,38,38,.10);  color:#b91c1c; border:1px solid rgba(220,38,38,.25); }
    .mv-SALE { background:rgba(59,130,246,.10); color:#1d4ed8; border:1px solid rgba(105,189,49,.25); }
    .mv-ADJ  { background:rgba(245,158,11,.10); color:#92400e; border:1px solid rgba(245,158,11,.3); }
  </style>
</head>
<body>
<div class="layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <div>
        <div class="topbar-title">Stock Movements</div>
        <div class="topbar-sub">Complete history of stock in, out, adjustments & sales deductions</div>
      </div>
    </div>
    <div class="page-body">

      <?php if ($flash): ?>
        <div class="flash flash-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
      <?php endif; ?>

      <!-- QUICK ADJUST (for eligible roles) -->
      <?php if (canAdjustStock()): ?>
      <div class="card mb-2 no-print">
        <div class="card-title">Quick Stock Adjustment</div>
        <form method="post" action="stock_movements.php">
          <input type="hidden" name="action" value="stock_adjust"/>
          <div class="form-grid-3" style="align-items:end;">
            <div class="form-group">
              <label>Product *</label>
              <select name="product_id" class="form-control" required>
                <option value="">- Select product -</option>
                <?php foreach ($products as $p): ?>
                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['board_type']) ?> - <?= htmlspecialchars($p['category']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Type *</label>
              <select name="adj_type" class="form-control">
                <option value="IN">Stock IN (Receive)</option>
                <option value="OUT">Stock OUT (Issue/Return)</option>
              </select>
            </div>
            <div class="form-group">
              <label>Quantity *</label>
              <input type="number" name="adj_qty" class="form-control" min="1" placeholder="e.g. 20" required/>
            </div>
          </div>
          <div class="form-group">
            <label>Notes (optional)</label>
            <input type="text" name="adj_notes" class="form-control" placeholder="e.g. Received from supplier"/>
          </div>
          <button type="submit" class="btn btn-success">Apply Adjustment</button>
        </form>
      </div>
      <?php endif; ?>

      <!-- FILTER BAR -->
      <div class="card mb-2 no-print">
        <form method="get" action="stock_movements.php">
          <div class="toolbar" style="flex-wrap:wrap;gap:.5rem;">
            <div class="toolbar-left" style="flex-wrap:wrap;gap:.5rem;">
              <select name="product_id" class="filter-sel">
                <option value="">All Products</option>
                <?php foreach ($products as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $filterPid==$p['id']?'selected':'' ?>>
                  <?= htmlspecialchars($p['board_type']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <select name="type" class="filter-sel" onchange="this.form.submit()">
                <option value="">All Types</option>
                <option value="IN"         <?= $filterType==='IN'?'selected':'' ?>>Stock IN</option>
                <option value="OUT"        <?= $filterType==='OUT'?'selected':'' ?>>Stock OUT</option>
                <option value="ADJUSTMENT" <?= $filterType==='ADJUSTMENT'?'selected':'' ?>>Adjustment</option>
                <option value="SALE"       <?= $filterType==='SALE'?'selected':'' ?>>Sale Deduction</option>
              </select>
              <label class="muted" style="font-size:.78rem;">From</label>
              <input type="date" name="from" class="form-control" style="width:145px;" value="<?= htmlspecialchars($fromDate) ?>" onchange="this.form.submit()"/>
              <label class="muted" style="font-size:.78rem;">To</label>
              <input type="date" name="to" class="form-control" style="width:145px;" value="<?= htmlspecialchars($toDate) ?>" onchange="this.form.submit()"/>
              <button type="submit" class="btn btn-ghost">Filter</button>
            </div>
            <div class="toolbar-right">
              <button type="button" onclick="window.print()" class="btn btn-ghost">Export PDF</button>
              <a href="stock_movements.php?from=<?= $fromDate ?>&to=<?= $toDate ?>&product_id=<?= $filterPid ?>&type=<?= $filterType ?>&export=1"
                 class="btn btn-ghost">Export CSV</a>
            </div>
          </div>
        </form>
      </div>

      <!-- SUMMARY -->
      <div class="stats-row mb-2">
        <div class="stat-card">
          <div class="sc-icon"></div>
          <div><div class="sc-val green"><?= $typeCount['IN'] ?? 0 ?></div><div class="sc-lbl">Stock IN</div></div>
        </div>
        <div class="stat-card">
          <div class="sc-icon"></div>
          <div><div class="sc-val red"><?= $typeCount['OUT'] ?? 0 ?></div><div class="sc-lbl">Stock OUT</div></div>
        </div>
        <div class="stat-card">
          <div class="sc-icon"></div>
          <div><div class="sc-val amber"><?= $typeCount['ADJUSTMENT'] ?? 0 ?></div><div class="sc-lbl">Adjustments</div></div>
        </div>
        <div class="stat-card">
          <div class="sc-icon"></div>
          <div><div class="sc-val"><?= $typeCount['SALE'] ?? 0 ?></div><div class="sc-lbl">Sale Deductions</div></div>
        </div>
      </div>

      <!-- MOVEMENTS TABLE -->
      <div class="card">
        <div class="card-title">Movement History</div>

        <?php if (empty($movements)): ?>
          <p class="muted" style="font-size:.84rem;">No stock movements recorded for this period.</p>
        <?php else: ?>
        <div class="tbl-wrap">
          <table id="mvTbl">
            <thead>
              <tr>
                <th>#</th><th>Timestamp</th><th>Product</th><th>Category</th>
                <th>Type</th><th>Quantity</th><th>Notes</th><th>By</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($movements as $r):
                $badgeClass = match($r['type']) {
                  'IN'         => 'mv-IN',
                  'OUT'        => 'mv-OUT',
                  'SALE'       => 'mv-SALE',
                  'ADJUSTMENT' => 'mv-ADJ',
                  default      => 'mv-ADJ',
                };
                $qtyDisplay = ($r['quantity'] > 0 ? '+' : '') . $r['quantity'] . ' ' . $r['unit'];
                $qtyColor   = $r['quantity'] >= 0 ? 'green' : 'red';
              ?>
              <tr>
                <td class="muted"><?= $r['id'] ?></td>
                <td style="font-size:.8rem;white-space:nowrap;"><?= htmlspecialchars($r['created_at']) ?></td>
                <td>
                  <div class="fw7" style="font-size:.85rem;"><?= htmlspecialchars($r['board_type']) ?></div>
                  <div class="muted" style="font-size:.74rem;"><?= htmlspecialchars($r['color_design'] ?: '') ?></div>
                </td>
                <td class="muted" style="font-size:.8rem;"><?= htmlspecialchars($r['category']) ?></td>
                <td><span class="mv-badge <?= $badgeClass ?>"><?= $r['type'] ?></span></td>
                <td class="fw7 <?= $qtyColor ?>"><?= $qtyDisplay ?></td>
                <td style="font-size:.8rem;max-width:200px;word-break:break-word;"><?= htmlspecialchars($r['notes']) ?></td>
                <td style="font-size:.8rem;"><?= htmlspecialchars($r['full_name']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>
</body>
</html>

