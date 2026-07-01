?<?php
/**
 * inventory.php - Admin only.
 *
 * Features:
 *  - Searchable/filterable product table with columns:
 *    Board Type, Category, Thickness, Size, Stock, Cost (PHP ), Price (PHP ), Status
 *  - Add / Edit product via modal (board_type, category, thickness, size,
 *    cost_price, selling_price, initial stock, low_stock_threshold)
 *  - Delete product
 *  - Stock IN / OUT manual adjustment
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/stock_status.php';
requireCap('canViewInventory');

$db = getDB();
$user = currentUser();
$flash = null;

// -- Handle POST ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  /* -- SAVE PRODUCT (add / edit) -- */
  if ($action === 'save_product') {
    if (!canManageProducts()) {
      $flash = ['type' => 'err', 'msg' => 'Access denied: product management requires Admin or Inventory Officer role.'];
    } else {
      $id = (int) ($_POST['product_id'] ?? 0);
      $board = trim($_POST['board_type'] ?? '');
      $cat_id = $_POST['category_id'] ?? '';
      if ($cat_id === 'new') {
        $newCat = trim($_POST['new_category_name'] ?? '');
        if ($newCat) {
          $stmt = $db->prepare('INSERT IGNORE INTO categories (name) VALUES (?)');
          $stmt->execute([$newCat]);
          $cat_id = $db->lastInsertId();
          if (!$cat_id) {
            $existing = $db->prepare('SELECT id FROM categories WHERE name=?');
            $existing->execute([$newCat]);
            $cat_id = $existing->fetchColumn();
          }
        } else {
          $cat_id = 0;
        }
      }
      $cat_id = (int) $cat_id;
      $color = trim($_POST['color_design'] ?? '');
      $unit = trim($_POST['unit'] ?? 'pcs');
      $cost = round((float) ($_POST['cost_price'] ?? 0), 2);
      $price = round((float) ($_POST['selling_price'] ?? 0), 2);
      $agentPrice = round((float) ($_POST['agent_price'] ?? 0), 2);
      $threshold = (int) ($_POST['low_stock_threshold'] ?? 5);
      $initStock = (int) ($_POST['initial_stock'] ?? 0);

      if (!$board || !$cat_id || !$unit) {
        $flash = ['type' => 'err', 'msg' => 'Product Name, Category, and Unit are required.'];
      } else {
        if ($id === 0) {
          $stmt = $db->prepare('INSERT INTO products
                        (board_type,category_id,color_design,unit,cost_price,selling_price,agent_price,current_stock,low_stock_threshold)
                        VALUES (?,?,?,?,?,?,?,?,?)');
          $stmt->execute([$board, $cat_id, $color, $unit, $cost, $price, $agentPrice, $initStock, $threshold]);
          $newId = (int) $db->lastInsertId();
          logAudit($db, 'product.add', 'product', $newId, "Added product: $board");
          if ($initStock > 0) {
            logStockMovement($db, $newId, 'IN', $initStock, 'Initial stock on product creation');
          }
          $flash = ['type' => 'ok', 'msg' => "\"$board\" added to inventory."];
        } else {
          $stmt = $db->prepare('UPDATE products SET
                        board_type=?,category_id=?,color_design=?,unit=?,cost_price=?,selling_price=?,agent_price=?,low_stock_threshold=?
                        WHERE id=?');
          $stmt->execute([$board, $cat_id, $color, $unit, $cost, $price, $agentPrice, $threshold, $id]);
          logAudit($db, 'product.edit', 'product', $id, "Edited product: $board");
          $flash = ['type' => 'ok', 'msg' => "\"$board\" updated."];
        }
      }
    }
  }

  /* -- DELETE -- */
  if ($action === 'delete') {
    if (!canManageProducts()) {
      $flash = ['type' => 'err', 'msg' => 'Access denied.'];
    } else {
      $id = (int) ($_POST['product_id'] ?? 0);
      $prod = $db->prepare('SELECT board_type FROM products WHERE id=?');
      $prod->execute([$id]);
      $prodName = $prod->fetchColumn() ?: 'unknown';
      try {
        $db->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
        logAudit($db, 'product.delete', 'product', $id, "Deleted product: $prodName");
        $flash = ['type' => 'ok', 'msg' => 'Product deleted.'];
      } catch (PDOException $e) {
        // Parent row constraint fails on sale_items => Soft Delete
        $db->prepare('UPDATE products SET is_active=0 WHERE id=?')->execute([$id]);
        logAudit($db, 'product.soft_delete', 'product', $id, "Hidden product (has sales): $prodName");
        $flash = ['type' => 'ok', 'msg' => 'Product has recorded sales. It was hidden instead of physically deleted to preserve revenue history.'];
      }
    }
  }

  /* -- STOCK ADJUSTMENT -- */
  if ($action === 'stock_adjust') {
    if (!canAdjustStock()) {
      $flash = ['type' => 'err', 'msg' => 'Access denied: stock adjustments require Inventory Officer, Warehouse Staff, Manager, or Admin role.'];
    } else {
      $pid = (int) ($_POST['product_id'] ?? 0);
      $qty = (int) ($_POST['adj_qty'] ?? 0);
      $notes = trim($_POST['adj_notes'] ?? '');
      $type = ($_POST['adj_type'] === 'IN') ? 1 : -1;
      if ($qty <= 0) {
        $flash = ['type' => 'err', 'msg' => 'Quantity must be positive.'];
      } else {
        $db->prepare('UPDATE products SET current_stock = current_stock + ? WHERE id=?')
          ->execute([$type * $qty, $pid]);
        logStockMovement($db, $pid, 'ADJUSTMENT', $type * $qty, $notes ?: 'Manual adjustment');
        logAudit(
          $db,
          'stock.adjust',
          'product',
          $pid,
          "Stock {$_POST['adj_type']} {$qty} units. Notes: $notes"
        );
        $flash = ['type' => 'ok', 'msg' => 'Stock adjusted (' . ($_POST['adj_type']) . " $qty)."];
      }
    }
  }
}

// -- Fetch data --------------------------------------------
$cats = $db->query('SELECT id,name FROM categories WHERE is_active=1 ORDER BY name')->fetchAll();
$statuses = getDynamicStockStatuses($db);
$rawProds = $db->query("
    SELECT p.id, p.board_type, c.name AS category, p.color_design, p.unit,
           p.cost_price, p.selling_price, p.agent_price, p.current_stock, p.low_stock_threshold, p.category_id
    FROM products p JOIN categories c ON c.id=p.category_id
    WHERE p.is_active = 1
    ORDER BY p.board_type
")->fetchAll();

// Merge dynamic status into each product row
$products = [];
foreach ($rawProds as $p) {
  $s = $statuses[$p['id']] ?? null;
  $p['dyn_risk'] = $s ? $s['risk'] : 'ok';
  $p['dyn_label'] = $s ? $s['status_label'] : ' OK';
  $p['dyn_badge'] = $s ? $s['badge_class'] : 'b-ok';
  $p['dyn_thr'] = $s ? $s['dynamic_threshold'] : $p['low_stock_threshold'];
  // For JS filter compatibility: map to simple Low/OK
  $p['status'] = in_array($p['dyn_risk'], ['critical', 'warning', 'low']) ? 'Low' : 'OK';
  $products[] = $p;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1.0" />
  <title>Inventory - StockBoard</title>
  <link rel="stylesheet" href="css/style.css?v=5" />
</head>

<body>
  <div class="layout">
      <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <div class="topbar">
        <div>
          <div class="topbar-title">Inventory</div>
          <div class="topbar-sub">Manage laminated board products and stock levels</div>
        </div>
      </div>
      <div class="page-body">

          <?php if ($flash): ?>
          <div class="flash flash-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
          <?php endif; ?>

        <!-- Toolbar -->
        <div class="toolbar">
          <div class="toolbar-left">
            <input type="text" id="srch" class="search-box" placeholder="Search product&hellip;" />
            <select id="catF" class="filter-sel">
              <option value="">All Categories</option>
                <?php foreach ($cats as $c): ?>
                <option><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
            </select>
            <select id="stF" class="filter-sel">
              <option value="">All Status</option>
              <option value="OK">OK</option>
              <option value="Low">Low Stock</option>
            </select>
          </div>
          <div class="toolbar-right">
              <?php if (canManageProducts()): ?>
              <button class="btn btn-primary" onclick="openAdd()">+ Add Product</button>
              <?php endif; ?>
          </div>
        </div>

        <!-- Product Table -->
        <div class="tbl-wrap">
          <table id="prodTbl">
            <thead>
              <tr>
                <th>Product Name</th>
                <th>Category</th>
                <th>Color/Design</th>
                <th>Unit</th>
                <th>Stock</th>
                <th>Cost (PHP )</th>
                <th>Price/Unit (PHP )</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p): ?>
                <tr data-name="<?= strtolower(htmlspecialchars($p['board_type'])) ?>"
                  data-cat="<?= htmlspecialchars($p['category']) ?>" data-st="<?= $p['status'] ?>">
                  <td class="fw7"><?= htmlspecialchars($p['board_type']) ?></td>
                  <td><?= htmlspecialchars($p['category']) ?></td>
                  <td><?= htmlspecialchars($p['color_design'] ?: '-') ?></td>
                  <td><span class="muted"><?= htmlspecialchars($p['unit']) ?></span></td>
                  <td><?= $p['current_stock'] ?></td>
                  <td>PHP <?= number_format($p['cost_price'], 2) ?></td>
                  <td>PHP <?= number_format($p['selling_price'], 2) ?></td>
                  <td><span
                      class="badge <?= htmlspecialchars($p['dyn_badge']) ?>"><?= htmlspecialchars(str_replace([' ', ' ', ' ', ' '], '', $p['dyn_label'])) ?></span>
                  </td>
                  <td>
                      <?php if (canManageProducts()): ?>
                      <button class="btn btn-ghost btn-sm" onclick="openEdit(
                    <?= $p['id'] ?>,'<?= addslashes($p['board_type']) ?>',<?= $p['category_id'] ?>,
                    '<?= addslashes($p['color_design']) ?>','<?= addslashes($p['unit']) ?>',
                    <?= $p['cost_price'] ?>,<?= $p['selling_price'] ?>,<?= $p['agent_price'] ?? 0 ?>,<?= $p['low_stock_threshold'] ?>
                  )">Edit</button>
                      <?php endif; ?>
                      <?php if (canAdjustStock()): ?>
                      <button class="btn btn-warning btn-sm"
                        onclick="openStock(<?= $p['id'] ?>,'<?= addslashes($p['board_type']) ?>')">Stock</button>
                      <?php endif; ?>
                      <?php if (canManageProducts()): ?>
                      <form method="post" style="display:inline" onsubmit="return confirm('Delete this product?')">
                        <input type="hidden" name="action" value="delete" />
                        <input type="hidden" name="product_id" value="<?= $p['id'] ?>" />
                        <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                      </form>
                      <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- -- ADD / EDIT MODAL ---------------------------------------- -->
  <div class="overlay" id="prodModal">
    <div class="modal" style="max-width:560px;">
      <div class="modal-header">
        <div class="modal-title" id="mTitle">Add Product</div>
        <button class="modal-close" onclick="closeModal('prodModal')">X</button>
      </div>
      <form method="post" action="inventory.php">
        <input type="hidden" name="action" value="save_product" />
        <input type="hidden" name="product_id" id="f_id" value="0" />

        <div class="form-group">
          <label>Product Name *</label>
          <input type="text" name="board_type" id="f_bt" class="form-control" placeholder="e.g. PETG High Gloss 18mm"
            required />
        </div>
        <div class="form-grid-2">
          <div class="form-group">
            <label>Category *</label>
            <select name="category_id" id="f_cat" class="form-control" required
              onchange="document.getElementById('f_new_cat').style.display = this.value === 'new' ? 'block' : 'none'; document.getElementById('f_new_cat').required = this.value === 'new';">
                <?php foreach ($cats as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              <option value="new"> + Add New Category</option>
            </select>
            <input type="text" name="new_category_name" id="f_new_cat" class="form-control"
              style="display:none;margin-top:.5rem;" placeholder="New Category Name" />
          </div>
          <div class="form-group">
            <label>Color/Design</label>
            <input type="text" name="color_design" id="f_cd" class="form-control" placeholder="e.g. Maple Wood" />
          </div>
        </div>
        <div class="form-grid-2">
          <div class="form-group">
            <label>Unit *</label>
            <select name="unit" id="f_un" class="form-control" required>
              <option value="pcs">Pieces (pcs)</option>
              <option value="meter">Meter (m)</option>
              <option value="box">Box</option>
              <option value="set">Set</option>
            </select>
          </div>
        </div>
        <div class="form-grid-3">
          <div class="form-group">
            <label>Cost Price (PHP ) *</label>
            <input type="number" step="0.01" min="0" name="cost_price" id="f_cp" class="form-control" placeholder="0.00"
              required />
          </div>
          <div class="form-group">
            <label>Selling Price (PHP ) *</label>
            <input type="number" step="0.01" min="0" name="selling_price" id="f_sp" class="form-control"
              placeholder="0.00" required />
          </div>
          <div class="form-group">
            <label>Agent Price (PHP) <span class="muted" style="font-size:.72rem;">(base for commission)</span></label>
            <input type="number" step="0.01" min="0" name="agent_price" id="f_ap" class="form-control"
              placeholder="0.00" />
          </div>
          <!-- threshold is auto-computed from sales; default fallback = 5 -->
          <input type="hidden" name="low_stock_threshold" id="f_tr" value="5" />
        </div>
        <div class="form-group" id="initStockRow">
          <label>Initial Stock (pieces)</label>
          <input type="number" min="0" name="initial_stock" id="f_is" class="form-control" value="0" />
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" onclick="closeModal('prodModal')">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Product</button>
        </div>
      </form>
    </div>
  </div>

  <!-- -- STOCK ADJUST MODAL --------------------------------------- -->
  <div class="overlay" id="stockModal">
    <div class="modal" style="max-width:400px;">
      <div class="modal-header">
        <div class="modal-title">Adjust Stock - <span id="stkName"></span></div>
        <button class="modal-close" onclick="closeModal('stockModal')">X</button>
      </div>
      <form method="post" action="inventory.php">
        <input type="hidden" name="action" value="stock_adjust" />
        <input type="hidden" name="product_id" id="stkId" />
        <div class="form-grid-2">
          <div class="form-group">
            <label>Type</label>
            <select name="adj_type" class="form-control">
              <option value="IN">Stock IN (Restock)</option>
              <option value="OUT">Stock OUT (Return/Loss)</option>
            </select>
          </div>
          <div class="form-group">
            <label>Quantity (pcs) *</label>
            <input type="number" name="adj_qty" class="form-control" min="1" required placeholder="e.g. 20" />
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" onclick="closeModal('stockModal')">Cancel</button>
          <button type="submit" class="btn btn-success">Apply</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    const cats = {  <?php foreach ($cats as $c): ?>"<?= $c['id'] ?>": "<?= addslashes($c['name']) ?>",  <?php endforeach; ?> };

    function openAdd() {
      document.getElementById('mTitle').textContent = 'Add Product';
      document.getElementById('f_cat').value = '1';
      document.getElementById('f_new_cat').style.display = 'none';
      document.getElementById('f_new_cat').required = false;
      document.getElementById('f_new_cat').value = '';
      document.getElementById('f_id').value = '0';
      document.getElementById('f_bt').value = '';
      document.getElementById('f_cd').value = '';
      document.getElementById('f_un').value = 'pcs';
      document.getElementById('f_cp').value = '';
      document.getElementById('f_sp').value = '';
      document.getElementById('f_ap').value = '0';
      document.getElementById('f_tr').value = '5';
      document.getElementById('f_is').value = '0';
      document.getElementById('initStockRow').style.display = '';
      document.getElementById('prodModal').classList.add('open');
    }
    function openEdit(id, bt, catId, cd, un, cp, sp, ap, tr) {
      document.getElementById('mTitle').textContent = 'Edit Product';
      document.getElementById('f_new_cat').style.display = 'none';
      document.getElementById('f_new_cat').required = false;
      document.getElementById('f_new_cat').value = '';
      document.getElementById('f_id').value = id;
      document.getElementById('f_bt').value = bt;
      document.getElementById('f_cat').value = catId;
      document.getElementById('f_cd').value = cd;
      document.getElementById('f_un').value = un;
      document.getElementById('f_cp').value = cp;
      document.getElementById('f_sp').value = sp;
      document.getElementById('f_ap').value = ap;
      document.getElementById('f_tr').value = tr;
      document.getElementById('initStockRow').style.display = 'none'; // hide on edit
      document.getElementById('prodModal').classList.add('open');
    }
    function openStock(id, name) {
      document.getElementById('stkId').value = id;
      document.getElementById('stkName').textContent = name;
      document.getElementById('stockModal').classList.add('open');
    }
    function closeModal(id) { document.getElementById(id).classList.remove('open'); }
    document.querySelectorAll('.overlay').forEach(el =>
      el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); }));

    // -- Search / filter ----------------------------------------------
    (function () {
      const rows = document.querySelectorAll('#prodTbl tbody tr');
      function filter() {
        const q = document.getElementById('srch').value.toLowerCase();
        const cf = document.getElementById('catF').value;
        const sf = document.getElementById('stF').value;
        rows.forEach(r => {
          const ok = (!q || r.dataset.name.includes(q))
            && (!cf || r.dataset.cat === cf)
            && (!sf || r.dataset.st === sf);
          r.style.display = ok ? '' : 'none';
        });
      }
      document.getElementById('srch').addEventListener('input', filter);
      document.getElementById('catF').addEventListener('change', filter);
      document.getElementById('stF').addEventListener('change', filter);
    })();
  </script>
</body>

</html>
