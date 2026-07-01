?<?php
/**
 * sales.php - Accessible by Admin and Staff.
 *
 * Features:
 *  - New sale form:
 *     Board Type (searchable select with all 7 types)
 *     Thickness + Size (auto-filled from product data)
 *     Quantity
 *     Price per piece (PHP ) - initialized from selling_price but EDITABLE
 *     Total (PHP ) auto-calculated
 *  - Sale items list inside a current session (client-side cart before final submit)
 *  - Recent sales history table with search and date filter
 *  - Prices stored as DECIMAL(10,2), displayed with PHP  symbol
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
requireLogin();
if (!($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_sale_pending')) {
  requireCap('canViewSales');
}

$db = getDB();
$user = currentUser();
$flash = null;

// -- Handle POST: record_sale ------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_sale') {
  // Expect JSON-encoded items array from JS
  $items = json_decode($_POST['items'] ?? '[]', true);
  $notes = trim($_POST['notes'] ?? '');
  $paymentType = trim($_POST['payment_type'] ?? '');
  $paymentRef = trim($_POST['payment_reference'] ?? '');
  $saleDate = $_POST['sale_date'] ?? date('Y-m-d');

  if (empty($items)) {
    $flash = ['type' => 'err', 'msg' => 'Add at least one item before submitting.'];
  } else {
    $total = 0;
    $errors = [];

    // Validate stock
    foreach ($items as $item) {
      $pid = (int) $item['product_id'];
      $qty = (int) $item['quantity'];
      $row = $db->prepare('SELECT board_type, current_stock FROM products WHERE id=?');
      $row->execute([$pid]);
      $prod = $row->fetch();
      if (!$prod) {
        $errors[] = "Invalid product ID $pid.";
        continue;
      }
      if ($qty > $prod['current_stock']) {
        $errors[] = "Insufficient stock for {$prod['board_type']}: only {$prod['current_stock']} pcs available.";
      }
      $total += round($qty * (float) $item['price_per_unit'], 2);
    }

    if ($errors) {
      $flash = ['type' => 'err', 'msg' => implode(' ', $errors)];
    } else {
      // Insert sale header
      $invoiceNo = trim($_POST['invoice_no'] ?? '');
      if (!$invoiceNo) {
        do {
          $invoiceNo = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
          $chk = $db->prepare("SELECT id FROM sales WHERE invoice_no=?");
          $chk->execute([$invoiceNo]);
        } while ($chk->fetch());
      }
      $sh = $db->prepare('INSERT INTO sales (user_id, invoice_no, total_amount, notes, sale_date, payment_type, payment_reference) VALUES (?,?,?,?,?,?,?)');
      $sh->execute([$user['id'], $invoiceNo, $total, $notes, $saleDate, $paymentType, $paymentRef]);
      $saleId = (int) $db->lastInsertId();

      // Insert items + deduct stock + log movements
      $si = $db->prepare('INSERT INTO sale_items (sale_id,product_id,quantity,price_per_unit,total) VALUES (?,?,?,?,?)');
      foreach ($items as $item) {
        $pid = (int) $item['product_id'];
        $qty = (int) $item['quantity'];
        $ppu = round((float) $item['price_per_unit'], 2);
        $lineT = round($qty * $ppu, 2);
        $si->execute([$saleId, $pid, $qty, $ppu, $lineT]);
        $db->prepare('UPDATE products SET current_stock = current_stock - ? WHERE id=?')
          ->execute([$qty, $pid]);
        logStockMovement($db, $pid, 'SALE', -$qty, "Sale #$saleId");
      }
      logAudit(
        $db,
        'sale.record',
        'sale',
        $saleId,
        "Recorded sale #{$invoiceNo} - Total: PHP " . number_format($total, 2)
      );
      $flash = ['type' => 'ok', 'msg' => "Invoice {$invoiceNo} recorded - Total: PHP " . number_format($total, 2)];
    }
  }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_sale_pending') {
  // From quotation.php
  $items = json_decode($_POST['items'] ?? '[]', true);
  $notes = trim($_POST['notes'] ?? 'Pending Order');
  $paymentType = trim($_POST['payment_type'] ?? '');
  $paymentRef = trim($_POST['payment_reference'] ?? '');
  $saleDate = date('Y-m-d');

  if (!empty($items)) {
    $errors = [];
    foreach ($items as $item) {
      $pid = (int) $item['product_id'];
      $qty = (int) $item['qty'];
      $row = $db->prepare('SELECT board_type, current_stock FROM products WHERE id=?');
      $row->execute([$pid]);
      $prod = $row->fetch();
      if (!$prod) {
        $errors[] = "Invalid product ID $pid.";
        continue;
      }
      if ($qty > $prod['current_stock']) {
        $errors[] = "Insufficient stock for {$prod['board_type']}: only {$prod['current_stock']} available.";
      }
    }

    if (!empty($errors)) {
      echo "Error: " . implode(' ', $errors);
      exit;
    }

    $total = 0;
    foreach ($items as $item) {
      $total += round(((int) $item['qty']) * ((float) $item['price']), 2);
    }

    // Gen Invoice
    do {
      $invoiceNo = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
      $chk = $db->prepare("SELECT id FROM sales WHERE invoice_no=?");
      $chk->execute([$invoiceNo]);
    } while ($chk->fetch());

    $sh = $db->prepare('INSERT INTO sales (user_id, invoice_no, total_amount, notes, sale_date, status, payment_type, payment_reference) VALUES (?,?,?,?,?,"PendingOrder",?,?)');
    $sh->execute([$user['id'], $invoiceNo, $total, $notes, $saleDate, $paymentType, $paymentRef]);
    $saleId = (int) $db->lastInsertId();

    $si = $db->prepare('INSERT INTO sale_items (sale_id,product_id,quantity,price_per_unit,total) VALUES (?,?,?,?,?)');
    foreach ($items as $item) {
      $pid = (int) $item['product_id'];
      $qty = (int) $item['qty'];
      $ppu = round((float) $item['price'], 2);
      $lineT = round($qty * $ppu, 2);
      $si->execute([$saleId, $pid, $qty, $ppu, $lineT]);

      // Deduct stock to reserve it
      $db->prepare('UPDATE products SET current_stock = current_stock - ? WHERE id=?')->execute([$qty, $pid]);
      logStockMovement($db, $pid, 'OUT', $qty, "Reserved for Order #$saleId");
    }
    logAudit($db, 'sale.record_pending', 'sale', $saleId, "Recorded pending order #{$invoiceNo}");
    echo "Success";
    exit;
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_sale') {
  $si_id = (int) $_POST['sale_item_id'];
  $newQty = (int) $_POST['edit_qty'];
  $newPrice = round((float) $_POST['edit_price'], 2);

  $row = $db->prepare('SELECT sale_id, product_id, quantity, price_per_unit, total FROM sale_items WHERE id=?');
  $row->execute([$si_id]);
  $old_si = $row->fetch();

  if ($old_si && $newQty > 0 && $newPrice >= 0) {
    $diffQty = $newQty - $old_si['quantity'];
    $newTotal = round($newQty * $newPrice, 2);
    $diffTotal = $newTotal - $old_si['total'];

    $prod = $db->prepare('SELECT current_stock, board_type FROM products WHERE id=?');
    $prod->execute([$old_si['product_id']]);
    $p = $prod->fetch();

    if ($diffQty > 0 && $diffQty > $p['current_stock']) {
      $flash = ['type' => 'err', 'msg' => 'Insufficient stock to increase quantity.'];
    } else {
      if ($diffQty != 0) {
        $db->prepare('UPDATE products SET current_stock = current_stock - ? WHERE id=?')->execute([$diffQty, $old_si['product_id']]);
      }
      $db->prepare('UPDATE sale_items SET quantity=?, price_per_unit=?, total=? WHERE id=?')->execute([$newQty, $newPrice, $newTotal, $si_id]);
      if ($diffTotal != 0) {
        $db->prepare('UPDATE sales SET total_amount = total_amount + ? WHERE id=?')->execute([$diffTotal, $old_si['sale_id']]);
      }
      $flash = ['type' => 'ok', 'msg' => 'Sale item updated successfully.'];
    }
  } else {
    $flash = ['type' => 'err', 'msg' => 'Invalid edit data.'];
  }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_void') {
  $si_id = (int) $_POST['sale_item_id'];
  $row = $db->prepare('SELECT sale_id FROM sale_items WHERE id=?');
  $row->execute([$si_id]);
  $sale_id = $row->fetchColumn();
  if ($sale_id) {
    $db->prepare("UPDATE sales SET status='VoidPending' WHERE id=?")->execute([$sale_id]);
    logAudit($db, 'sale.void_request', 'sale', $sale_id, "Requested void for sale #$sale_id");
    $flash = ['type' => 'ok', 'msg' => 'Void requested. Waiting for manager/administrator approval.'];
  }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_void') {
  if (userRole() !== 'Manager' && userRole() !== 'Administrator') {
    $flash = ['type' => 'err', 'msg' => 'Access restricted.'];
  } else {
    $sale_id = (int) $_POST['sale_id'];
    $void_type = $_POST['void_type'] === 'Returned' ? 'Returned' : 'Voided';

    $saleReq = $db->prepare("SELECT status FROM sales WHERE id=?");
    $saleReq->execute([$sale_id]);
    if ($saleReq->fetchColumn() === 'VoidPending') {
      // Restore stock
      $items = $db->prepare("SELECT product_id, quantity FROM sale_items WHERE sale_id=?");
      $items->execute([$sale_id]);
      foreach ($items->fetchAll() as $item) {
        $db->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id=?")->execute([$item['quantity'], $item['product_id']]);
        logStockMovement($db, $item['product_id'], 'ADJUSTMENT', $item['quantity'], "$void_type Sale #$sale_id");
      }
      $db->prepare("UPDATE sales SET status=? WHERE id=?")->execute([$void_type, $sale_id]);
      logAudit($db, 'sale.void_approve', 'sale', $sale_id, "Approved void ($void_type) for sale #$sale_id");
      $flash = ['type' => 'ok', 'msg' => "Sale officially $void_type and stock restored."];
    }
  }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject_void') {
  if (userRole() !== 'Manager' && userRole() !== 'Administrator') {
    $flash = ['type' => 'err', 'msg' => 'Access restricted.'];
  } else {
    $sale_id = (int) $_POST['sale_id'];
    $db->prepare("UPDATE sales SET status='Valid' WHERE id=?")->execute([$sale_id]);
    logAudit($db, 'sale.void_reject', 'sale', $sale_id, "Rejected void request for sale #$sale_id");
    $flash = ['type' => 'ok', 'msg' => 'Void request rejected. Sale remains valid.'];
  }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_pending') {
  $sale_id = (int) $_POST['sale_id'];
  $db->prepare("UPDATE sales SET status='Valid' WHERE id=?")->execute([$sale_id]);
  logAudit($db, 'sale.complete_pending', 'sale', $sale_id, "Completed pending order #$sale_id");
  $flash = ['type' => 'ok', 'msg' => 'Pending order completed and validated.'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_pending') {
  $sale_id = (int) $_POST['sale_id'];
  $saleReq = $db->prepare("SELECT status FROM sales WHERE id=?");
  $saleReq->execute([$sale_id]);
  if ($saleReq->fetchColumn() === 'PendingOrder') {
    // Restore reserved stock
    $items = $db->prepare("SELECT product_id, quantity FROM sale_items WHERE sale_id=?");
    $items->execute([$sale_id]);
    foreach ($items->fetchAll() as $item) {
      $db->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id=?")->execute([$item['quantity'], $item['product_id']]);
      logStockMovement($db, $item['product_id'], 'IN', $item['quantity'], "Restored from cancelled Order #$sale_id");
    }
    $db->prepare("UPDATE sales SET status='Voided' WHERE id=?")->execute([$sale_id]);
    logAudit($db, 'sale.cancel_pending', 'sale', $sale_id, "Cancelled pending order #$sale_id");
    $flash = ['type' => 'ok', 'msg' => 'Order cancelled and stock restored.'];
  }
}

// -- Fetch products ------------------------------------------------
$products = $db->query("
    SELECT p.id, p.board_type, c.name AS category_name, p.color_design, p.unit, p.selling_price, p.current_stock
    FROM products p
    JOIN categories c ON c.id = p.category_id
    ORDER BY p.board_type")->fetchAll();

// -- Recent sales --------------------------------------------------
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

$recentSql = $db->prepare("
    SELECT s.id, s.invoice_no, s.sale_date, s.created_at, s.status, u.full_name AS staff, u.role AS staff_role,
           s.payment_type, s.payment_reference,
           p.board_type, c.name AS category, p.color_design, p.unit,
           si.quantity, si.price_per_unit, si.total, si.id AS item_id, s.id AS sale_id
    FROM sales s
    JOIN sale_items si ON si.sale_id = s.id
    JOIN products p    ON p.id = si.product_id
    JOIN categories c  ON c.id = p.category_id
    LEFT JOIN users u  ON u.id = s.user_id
    WHERE s.sale_date BETWEEN ? AND ?
    ORDER BY s.created_at DESC LIMIT 200");
$recentSql->execute([$from, $to]);
$recentSales = $recentSql->fetchAll();

$totalRevenue = 0;
foreach ($recentSales as $r) {
  if ($r['status'] === 'Valid')
    $totalRevenue += $r['total'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1.0" />
  <title>Sales - StockBoard</title>
  <link rel="stylesheet" href="css/style.css?v=5" />
</head>

<body>
  <div class="layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <div class="topbar">
        <div>
          <div class="topbar-title">Sales</div>
          <div class="topbar-sub">Record board sales and view history</div>
        </div>
      </div>
      <div class="page-body">

        <?php if ($flash): ?>
          <div class="flash flash-<?= $flash['type'] ?>">
            <?= htmlspecialchars($flash['msg']) ?>
          </div>
        <?php endif; ?>

        <!-- -- NEW SALE FORM -- -->
        <div class="card mb-2">
          <div class="card-title">New Sale Entry</div>

          <form id="saleForm" method="post" action="sales.php">
            <input type="hidden" name="action" value="record_sale" />
            <input type="hidden" name="items" id="cartJSON" value="[]" />

            <div class="form-grid-2" style="align-items:end;">
              <div class="form-group">
                <label>Product Name *</label>
                <select id="prodSel" class="form-control" onchange="fillProduct()">
                  <option value="">- Select product -</option>
                  <?php foreach ($products as $p): ?>
                    <?php $availStr = $p['current_stock'] > 0 ? 'Yes' : 'No'; ?>
                    <option value="<?= $p['id'] ?>" data-pr="<?= $p['selling_price'] ?>"
                      data-st="<?= $p['current_stock'] ?>">
                      <?= htmlspecialchars($p['board_type']) ?> |
                      <?= htmlspecialchars($p['category_name']) ?> | Stock:
                      <?= $p['current_stock'] ?> | PHP
                      <?= number_format($p['selling_price'], 2) ?> | Available:
                      <?= $availStr ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

            </div>

            <div class="form-grid-3" style="align-items:end;">
              <div class="form-group">
                <label>Quantity * <span id="qtyUnit" class="muted" style="font-size:0.8rem;"></span></label>
                <input type="number" id="qty" class="form-control" min="1" placeholder="0"
                  oninput="calcTotal(); checkStockAjax();" />
                <div id="stockAjaxStatus" style="font-size:0.8rem; margin-top:4px;"></div>
              </div>
              <div class="form-group">
                <label>Price per unit (PHP ) <span
                    style="color:var(--accent-lt);font-size:.72rem;">(editable)</span></label>
                <input type="number" step="0.01" min="0" id="ppu" class="form-control" placeholder="0.00"
                  oninput="calcTotal()" />
              </div>
              <div class="form-group">
                <label>Line Total (PHP )</label>
                <div id="lineTotal" style="font-size:1.15rem;font-weight:700;color:#16a34a;padding:.52rem 0;">PHP 0.00
                </div>
              </div>
            </div>

            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem;">
              <button type="button" class="btn btn-success" onclick="addToCart()">+ Add to Sale</button>
              <span class="muted" style="font-size:.8rem;">Add multiple products before submitting.</span>
            </div>

            <!-- Cart table -->
            <div id="cartBox" class="items-box hidden">
              <table id="cartTbl">
                <thead>
                  <tr>
                    <th>Product Name</th>
                    <th>Color/Design</th>
                    <th>Qty</th>
                    <th>Unit</th>
                    <th>Price/unit (PHP )</th>
                    <th>Total (PHP )</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody id="cartBody"></tbody>
                <tfoot>
                  <tr style="font-weight:700;">
                    <td colspan="5" class="tr" style="padding:.6rem .9rem;">Grand Total:</td>
                    <td id="grandTotal" style="padding:.6rem .9rem;color:#16a34a;">PHP 0.00</td>
                    <td></td>
                  </tr>
                </tfoot>
              </table>
            </div>

            <div class="form-grid-3" style="margin-top:.75rem;">
              <div class="form-group">
                <label>Invoice No. <span class="muted" style="font-size:.72rem;">(auto-generated if
                    blank)</span></label>
                <input type="text" name="invoice_no" id="invoiceNo" class="form-control"
                  placeholder="e.g. INV-20260413-001" />
              </div>
              <div class="form-group">
                <label>Sale Date</label>
                <input type="date" name="sale_date" class="form-control" value="<?= date('Y-m-d') ?>" />
              </div>
              <div class="form-group">
                <label>Notes (optional)</label>
                <input type="text" name="notes" class="form-control" placeholder="e.g. Walk-in customer" />
              </div>
            </div>
            
            <div class="form-grid-2" style="margin-top:.75rem; align-items:end;">
              <div class="form-group">
                <label>Payment Type</label>
                <select name="payment_type" id="paymentType" class="form-control" required>
                  <option value="">- Select Type -</option>
                  <option value="Cash">Cash</option>
                  <option value="GCash">GCash</option>
                  <option value="Bank Transfer">Bank Transfer</option>
                  <option value="Check">Check</option>
                </select>
              </div>
              <div class="form-group">
                <label>Payment Ref. No.</label>
                <input type="text" name="payment_reference" id="paymentRef" class="form-control" placeholder="e.g. Ref No. or Check No." />
              </div>
            </div>

            <button type="button" class="btn btn-primary" id="submitBtn" onclick="confirmSale()" style="margin-top:1.5rem;">Submit Sale</button>
          </form>
        </div>

        <!-- -- RECENT SALES TABLE -- -->
        <div class="card">
          <div class="card-title">Recent Sales History</div>
          <form method="get" action="sales.php" class="toolbar mb-2">
            <div class="toolbar-left">
              <input type="text" id="saleSrch" class="search-box" placeholder="Search product / staff&hellip;" />
              <label class="muted" style="font-size:.78rem;">From</label>
              <input type="date" name="from" class="form-control" style="width:145px;"
                value="<?= htmlspecialchars($from) ?>" />
              <label class="muted" style="font-size:.78rem;">To</label>
              <input type="date" name="to" class="form-control" style="width:145px;"
                value="<?= htmlspecialchars($to) ?>" />
              <button type="submit" class="btn btn-ghost">Filter</button>
            </div>
            <div class="toolbar-right">
              <span class="muted" style="font-size:.82rem;">Total Revenue: <strong class="green">PHP
                  <?= number_format($totalRevenue, 2) ?>
                </strong></span>
            </div>
          </form>

          <div class="tbl-wrap">
            <table id="saleTbl">
              <thead>
                <tr>
                  <th>Invoice</th>
                  <th>Date</th>
                  <th>Product Name</th>
                  <th>Category</th>
                  <th>Color/Design</th>
                  <th>Qty</th>
                  <th>Unit</th>
                  <th>Price/unit (PHP )</th>
                  <th>Total (PHP )</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentSales as $r): ?>
                  <tr
                    data-srch="<?= strtolower(htmlspecialchars($r['invoice_no'] . ' ' . $r['board_type'] . ' ' . $r['category'] . ' ' . $r['color_design'] . ' ' . $r['staff'])) ?>">
                    <td class="muted fw7" style="font-size:.8rem;">
                      <?= htmlspecialchars($r['invoice_no'] ?: '#' . $r['id']) ?>
                      <div style="font-size:0.7rem; font-weight:normal; margin-top:2px;">By:
                        <?= htmlspecialchars($r['staff']) ?> (
                        <?= htmlspecialchars($r['staff_role'] ?? 'Unknown Role') ?>)
                      </div>
                      <?php if($r['payment_type']): ?>
                        <div style="font-size:0.7rem; font-weight:normal; margin-top:2px; color:#6366f1;">
                          <?= htmlspecialchars($r['payment_type']) ?> 
                          <?= $r['payment_reference'] ? ' - ' . htmlspecialchars($r['payment_reference']) : '' ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?= htmlspecialchars($r['sale_date']) ?>
                    </td>
                    <td class="fw7">
                      <?= htmlspecialchars($r['board_type']) ?>
                    </td>
                    <td><span class="muted" style="font-size:0.85rem">
                        <?= htmlspecialchars($r['category']) ?>
                      </span></td>
                    <td>
                      <?= htmlspecialchars($r['color_design'] ?: '-') ?>
                    </td>
                    <td>
                      <?= $r['quantity'] ?>
                    </td>
                    <td><span class="muted">
                        <?= htmlspecialchars($r['unit']) ?>
                      </span></td>
                    <td>PHP
                      <?= number_format($r['price_per_unit'], 2) ?>
                    </td>
                    <td class="green fw7">PHP
                      <?= number_format($r['total'], 2) ?>
                    </td>
                    <td>
                      <div style="display:flex; flex-wrap:wrap; gap:6px; align-items:center;">
                        <?php if ($r['status'] === 'Valid'): ?>
                          <button class="btn btn-ghost btn-sm"
                            onclick="openEditSale(<?= $r['item_id'] ?>, <?= $r['quantity'] ?>, <?= $r['price_per_unit'] ?>, '<?= addslashes($r['board_type']) ?>')">Edit</button>
                          <form method="post" style="display:inline"
                            onsubmit="return confirm('Request to void this item?')">
                            <input type="hidden" name="action" value="request_void" />
                            <input type="hidden" name="sale_item_id" value="<?= $r['item_id'] ?>" />
                            <button class="btn btn-warning btn-sm" type="submit">Void</button>
                          </form>
                        <?php elseif ($r['status'] === 'VoidPending'): ?>
                          <span class="badge" style="background:#f59e0b;color:#fff;">Pending Void Approval</span>
                          <?php if (userRole() === 'Manager' || userRole() === 'Administrator'): ?>
                            <form method="post" style="display:inline"
                              onsubmit="return confirm('Approve as VOID? Stock will be restored.')">
                              <input type="hidden" name="action" value="approve_void" />
                              <input type="hidden" name="void_type" value="Voided" />
                              <input type="hidden" name="sale_id" value="<?= $r['sale_id'] ?>" />
                              <button class="btn btn-danger btn-sm" type="submit">Confirm Void</button>
                            </form>
                            <form method="post" style="display:inline"
                              onsubmit="return confirm('Approve as RETURNED? Stock will be restored.')">
                              <input type="hidden" name="action" value="approve_void" />
                              <input type="hidden" name="void_type" value="Returned" />
                              <input type="hidden" name="sale_id" value="<?= $r['sale_id'] ?>" />
                              <button class="btn btn-warning btn-sm" type="submit">Confirm Return</button>
                            </form>
                            <form method="post" style="display:inline"
                              onsubmit="return confirm('Reject this void request? Sale will remain valid.')">
                              <input type="hidden" name="action" value="reject_void" />
                              <input type="hidden" name="sale_id" value="<?= $r['sale_id'] ?>" />
                              <button class="btn btn-ghost btn-sm" type="submit">Reject</button>
                            </form>
                          <?php endif; ?>
                        <?php elseif ($r['status'] === 'Returned'): ?>
                          <span class="badge" style="background:#8b5cf6;color:#fff;">Returned</span>
                        <?php elseif ($r['status'] === 'PendingOrder'): ?>
                          <span class="badge" style="background:#3b82f6;color:#fff;">Order Queued</span>
                          <form method="post" style="display:inline"
                            onsubmit="return confirm('Complete this order? Client paid/picked up.')">
                            <input type="hidden" name="action" value="complete_pending" />
                            <input type="hidden" name="sale_id" value="<?= $r['sale_id'] ?>" />
                            <button class="btn btn-success btn-sm" type="submit">Complete</button>
                          </form>
                          <form method="post" style="display:inline"
                            onsubmit="return confirm('Cancel order? Stock will be restored.')">
                            <input type="hidden" name="action" value="cancel_pending" />
                            <input type="hidden" name="sale_id" value="<?= $r['sale_id'] ?>" />
                            <button class="btn btn-ghost btn-sm" style="color:var(--danger);" type="submit">Cancel</button>
                          </form>
                        <?php else: ?>
                          <span class="badge" style="background:#ef4444;color:#fff;">Voided</span>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($recentSales)): ?>
                  <tr>
                    <td colspan="10" class="tc muted" style="padding:2rem;">No sales found for this period.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- Edit Sale Modal -->
  <div class="overlay" id="editSaleModal">
    <div class="modal" style="max-width:400px;">
      <div class="modal-header">
        <div class="modal-title">Edit Sale Item - <span id="esName"></span></div>
        <button class="modal-close"
          onclick="document.getElementById('editSaleModal').classList.remove('open')">X</button>
      </div>
      <form method="post" action="sales.php">
        <input type="hidden" name="action" value="edit_sale" />
        <input type="hidden" name="sale_item_id" id="esId" />
        <div class="form-grid-2">
          <div class="form-group">
            <label>Quantity (pcs) *</label>
            <input type="number" name="edit_qty" id="esQty" class="form-control" min="1" required />
          </div>
          <div class="form-group">
            <label>Price per piece (PHP ) *</label>
            <input type="number" step="0.01" min="0" name="edit_price" id="esPrice" class="form-control" required />
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost"
            onclick="document.getElementById('editSaleModal').classList.remove('open')">Cancel</button>
          <button type="submit" class="btn btn-success">Save Changes</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Confirmation Modal -->
  <div class="overlay" id="confirmModal">
    <div class="modal" style="max-width:400px; text-align:center;">
      <h3 style="margin-top:0; color:#fff;">Confirm Sale</h3>
      <p style="font-size:1.1rem; margin-bottom:0.5rem; color:#fff;">Total Amount: <strong id="modalTotal">PHP 0.00</strong></p>
      <p style="font-size:1rem; margin-bottom:1.5rem; color:#ddd;">Ref No: <strong id="modalRef"></strong> (<span id="modalType"></span>)</p>
      
      <div style="background:#fff; color:#000; border:1px solid #000; padding:1rem; font-weight:bold; font-size:0.9rem; margin-bottom:1rem; text-align:left;">
        IMPORTANT: This transaction will be added to your Pending Liability Ledger.
      </div>
      
      <div style="background:#fff; border:1px solid #ccc; padding:1rem; text-align:left; margin-bottom:1.5rem;">
        <label style="display:flex; gap:0.75rem; align-items:flex-start; cursor:pointer; margin:0;">
          <input type="checkbox" id="liabilityCheck" style="margin-top:0.2rem; transform:scale(1.2);" onchange="toggleModalBtn()" />
          <div>
            <strong style="color:#000; display:block; margin-bottom:0.2rem; font-size:0.95rem;">Declaration of Accountability</strong>
            <span style="color:#333; font-size:0.85rem; display:block;">I confirm that I have received the payment for this transaction and encoded a valid Reference Number. I assume full financial liability for this remittance until cleared.</span>
            <span style="color:#555; font-size:0.8rem; font-style:italic; margin-top:0.3rem; display:block;">(Kinukumpirma ko na natanggap ko ang bayad at ako ang mananagot sa perang ito hanggang sa ma-clear ng Manager.)</span>
          </div>
        </label>
      </div>
      
      <div style="display:flex; gap:1rem; justify-content:center;">
        <button class="btn btn-ghost" style="color:#000; border:1px solid #ccc;" onclick="document.getElementById('confirmModal').classList.remove('open')">Cancel</button>
        <button class="btn" id="modalConfirmBtn" style="background:#000; color:#fff; border:1px solid #000;" onclick="submitFinalForm()" disabled>Proceed & Book</button>
      </div>
    </div>
  </div>

  <script>
    // -- Product map from PHP -----------------------------------------
    const PRODUCTS = {
      <?php foreach ($products as $p): ?>
        "<?= $p['id'] ?>": {
        name: "<?= addslashes($p['board_type']) ?>",
          color: "<?= addslashes($p['color_design']) ?>",
            unit: "<?= addslashes($p['unit']) ?>",
              price: <?= $p['selling_price'] ?>,
                stock: <?= $p['current_stock'] ?>
          },
      <?php endforeach; ?>
    };

    let cart = [];   // { product_id, name, quantity, price_per_unit }

    function fillProduct() {
      const pid = document.getElementById('prodSel').value;
      if (pid && PRODUCTS[pid]) {
        const p = PRODUCTS[pid];
        document.getElementById('qtyUnit').textContent = '(' + p.unit + ')';
        document.getElementById('ppu').value = p.price.toFixed(2);
        document.getElementById('ppu').min = p.price.toFixed(2);
        calcTotal();
        checkStockAjax();
      } else {
        document.getElementById('qtyUnit').textContent = '';
        document.getElementById('ppu').value = '';
        document.getElementById('ppu').min = '0';
        document.getElementById('lineTotal').textContent = 'PHP 0.00';
        document.getElementById('stockAjaxStatus').innerHTML = '';
      }
    }

    function checkStockAjax() {
      const pid = document.getElementById('prodSel').value;
      const qty = parseInt(document.getElementById('qty').value) || 0;
      const statusEl = document.getElementById('stockAjaxStatus');

      if (!pid) {
        statusEl.innerHTML = '';
        return;
      }

      fetch(`api/check-stock.php?product_id=${pid}&qty=${qty}`)
        .then(res => res.json())
        .then(data => {
          if (data.error) {
            statusEl.innerHTML = `<span style="color:#ef4444">${data.error}</span>`;
          } else {
            const color = data.available ? '#22c55e' : '#ef4444';
            statusEl.innerHTML = `<span style="color:${color}; font-weight:bold;">Stock Available: ${data.message}</span>`;
          }
        })
        .catch(err => {
          console.error('Ajax Stock Check Error:', err);
        });
    }

    function calcTotal() {
      const q = parseFloat(document.getElementById('qty').value) || 0;
      const p = parseFloat(document.getElementById('ppu').value) || 0;
      document.getElementById('lineTotal').textContent = 'PHP ' + (q * p).toLocaleString('en-PH', { minimumFractionDigits: 2 });
    }

    function addToCart() {
      const pid = document.getElementById('prodSel').value;
      const qty = parseInt(document.getElementById('qty').value);
      const ppu = parseFloat(document.getElementById('ppu').value);
      if (!pid) { alert('Select a product.'); return; }
      if (!qty || qty < 1) { alert('Enter a valid quantity.'); return; }
      if (!ppu || ppu <= 0) { alert('Enter a valid price per piece.'); return; }
      const prod = PRODUCTS[pid];
      if (ppu < prod.price) { alert('Price per unit cannot be below the default selling price (PHP ' + prod.price.toFixed(2) + ').'); return; }
      if (qty > prod.stock) { alert('Only ' + prod.stock + ' pcs in stock for ' + prod.name); return; }

      // Update existing or push new
      const existing = cart.find(c => c.product_id === pid);
      if (existing) { existing.quantity += qty; existing.price_per_unit = ppu; }
      else cart.push({ product_id: pid, name: prod.name, color: prod.color, unit: prod.unit, quantity: qty, price_per_unit: ppu });

      renderCart();
      // Reset inputs
      document.getElementById('prodSel').value = '';
      document.getElementById('qtyUnit').textContent = '';
      document.getElementById('qty').value = '';
      document.getElementById('ppu').value = '';
      document.getElementById('lineTotal').textContent = 'PHP 0.00';
    }

    function removeFromCart(pid) {
      cart = cart.filter(c => c.product_id !== pid);
      renderCart();
    }

    function renderCart() {
      const box = document.getElementById('cartBox');
      const tbody = document.getElementById('cartBody');
      tbody.innerHTML = '';
      let grand = 0;
      cart.forEach(c => {
        const total = c.quantity * c.price_per_unit;
        grand += total;
        tbody.insertAdjacentHTML('beforeend', `
      <tr>
        <td class="fw7">${c.name}</td>
        <td>${c.color || '-'}</td>
        <td>${c.quantity}</td>
        <td><span class="muted">${c.unit}</span></td>
        <td>PHP ${c.price_per_unit.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
        <td class="green">PHP ${total.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
        <td><button class="btn btn-danger btn-sm" onclick="removeFromCart('${c.product_id}')">Remove</button></td>
      </tr>`);
      });
      document.getElementById('grandTotal').textContent = 'PHP ' + grand.toLocaleString('en-PH', { minimumFractionDigits: 2 });
      box.classList.toggle('hidden', cart.length === 0);
      document.getElementById('cartJSON').value = JSON.stringify(cart);
    }

    function toggleModalBtn() {
      const isChecked = document.getElementById('liabilityCheck').checked;
      document.getElementById('modalConfirmBtn').disabled = !isChecked;
    }

    function confirmSale() {
      if (cart.length === 0) { 
        alert('Add at least one item to the sale.'); 
        return; 
      }
      
      const paymentType = document.getElementById('paymentType').value.trim();
      const paymentRef = document.getElementById('paymentRef').value.trim();
      
      if (!paymentType) {
        alert('Payment Type is required.');
        return;
      }
      
      if (paymentType !== 'Cash' && !paymentRef) {
        alert('Reference Number is required for ' + paymentType + '.');
        return;
      }

      document.getElementById('cartJSON').value = JSON.stringify(cart);
      
      // Populate modal
      document.getElementById('modalTotal').textContent = document.getElementById('grandTotal').textContent;
      document.getElementById('modalRef').textContent = paymentRef;
      document.getElementById('modalType').textContent = paymentType;
      
      // Reset checkbox
      document.getElementById('liabilityCheck').checked = false;
      toggleModalBtn();

      // Show modal
      document.getElementById('confirmModal').classList.add('open');
    }

    function submitFinalForm() {
      const btn = document.getElementById('modalConfirmBtn');
      btn.textContent = 'Processing...';
      btn.disabled = true;
      document.getElementById('saleForm').submit();
    }

    // Recent sales search
    document.getElementById('saleSrch').addEventListener('input', function () {
      const q = this.value.toLowerCase();
      document.querySelectorAll('#saleTbl tbody tr').forEach(r => {
        r.style.display = (r.dataset.srch && r.dataset.srch.includes(q)) ? '' : 'none';
      });
    });

    function openEditSale(id, qty, price, name) {
      document.getElementById('esId').value = id;
      document.getElementById('esQty').value = qty;
      document.getElementById('esPrice').value = price;
      document.getElementById('esName').textContent = name;
      document.getElementById('editSaleModal').classList.add('open');
    }
  </script>
</body>

</html>
