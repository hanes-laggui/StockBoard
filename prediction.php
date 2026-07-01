?<?php
/**
 * prediction.php - Administrator, Manager, and Inventory Officer.
 *
 * Stock Prediction using 30-day Moving Average for laminated boards.
 *
 * Algorithm (per board type):
 *   Step 1: SUM(si.quantity) over the last 30 days from sale_items  sales.
 *   Step 2: Average Daily Sales = total_units_sold / 30
 *   Step 3: Suggested Restock Qty = max(0, ceil(avg_daily * 30) - current_stock)
 *           (maintains a 30-day buffer)
 *
 * Risk levels:
 *   Critical: days_left <= 7
 *   Warning : days_left <= 14
 *   OK      : > 14 or no recent sales
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/stock_status.php';
requireCap('canViewPredictions');

$db       = getDB();
$days     = max(7, min(30, (int)($_GET['days'] ?? 30)));
$statuses = getDynamicStockStatuses($db);

// -- Fetch prediction data -----------------------------------------
$stmt = $db->prepare("
    SELECT
        p.id, p.board_type, c.name AS category,
        p.current_stock, p.low_stock_threshold,
        COALESCE(SUM(sold.qty), 0) AS units_sold
    FROM products p
    JOIN categories c ON c.id = p.category_id
    LEFT JOIN (
        SELECT si.product_id, SUM(si.quantity) as qty
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        WHERE s.sale_date >= CURDATE() - INTERVAL ? DAY
          AND s.status = 'Valid'
        GROUP BY si.product_id
    ) sold ON sold.product_id = p.id
    GROUP BY p.id, p.board_type, c.name,
             p.current_stock, p.low_stock_threshold
    ORDER BY p.board_type
");
$stmt->execute([$days]);
$raw = $stmt->fetchAll();

// -- Compute derived fields -------------
$rows = [];
foreach ($raw as $r) {
    $avgDaily  = round($r['units_sold'] / $days, 2);
    $daysLeft  = ($avgDaily > 0) ? round($r['current_stock'] / $avgDaily, 1) : null;
    $dynThr    = (int)$r['low_stock_threshold'];

    if ($daysLeft === null) {
        $risk = 'none';
        $badgeCls = '';
        $statusText = ' No recent sales';
    } elseif ($daysLeft <= 7) {
        $risk = 'critical';
        $badgeCls = 'b-low';
        $statusText = " Stock out soon";
    } elseif ($daysLeft <= 14) {
        $risk = 'warning';
        $badgeCls = 'b-warn';
        $statusText = " Reorder soon";
    } else {
        $risk = 'ok';
        $badgeCls = 'b-ok';
        $statusText = " OK";
    }

    $restock = ($avgDaily > 0) ? max(0, (int)ceil($avgDaily * 30 - $r['current_stock'])) : 0;

    $rows[] = array_merge($r, [
        'avg_daily'         => $avgDaily,
        'days_left'         => $daysLeft,
        'restock_qty'       => $restock,
        'risk'              => $risk,
        'badge_class'       => $badgeCls,
        'status_text'       => $statusText,
        'dynamic_threshold' => $dynThr,
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Stock Prediction - StockBoard</title>
  <link rel="stylesheet" href="css/style.css?v=5"/>
</head>
<body>
<div class="layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <div><div class="topbar-title">Stock Prediction</div><div class="topbar-sub">30-day moving average - estimated runout per board type</div></div>
    </div>
    <div class="page-body">

      <!-- EXPLAINER -->
      <div class="card mb-2" style="font-size:.84rem;line-height:1.75;">
        <div class="card-title">Prediction Method - Moving Average</div>
        <ol style="padding-left:1.4rem;margin-top:.3rem;">
          <li><strong>Avg Daily Sales</strong> = Total Units Sold / Training Days</li>
          <li><strong>Restock Qty</strong> = (Avg Daily Sales * 30) - Current Stock <em>(30-day buffer)</em></li>
        </ol>
      </div>

      <!-- PERIOD & SEARCH -->
      <div class="toolbar mb-2">
        <div class="toolbar-left">
          <label class="muted" style="font-size:.8rem;">Training period:</label>
          <form method="get" action="prediction.php" style="display:flex;gap:.4rem;align-items:center;">
            <select name="days" class="filter-sel" onchange="this.form.submit()">
              <?php foreach ([7, 30] as $d): ?>
                <option value="<?= $d ?>" <?= $days==$d?'selected':'' ?>>Last <?= $d ?> days</option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
        <div class="toolbar-right">
          <input type="text" id="predSrch" class="search-box" placeholder="Search board type&hellip;"/>
        </div>
      </div>

      <!-- PREDICTION TABLE -->
      <div class="tbl-wrap">
        <table id="predTbl">
          <thead>
            <tr>
              <th>Board Type</th><th>Category</th>
              <th>Avg Daily Sales</th><th>Current Stock</th><th>Threshold</th>
              <th>Restock Qty</th><th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              $rc = match($r['risk']) {
                'critical' => 'pred-crit',
                'warning'  => 'pred-warn',
                default    => '',
              };
            ?>
              <tr class="<?= $rc ?>" data-name="<?= strtolower(htmlspecialchars($r['board_type'])) ?>">
                <td class="fw7"><?= htmlspecialchars($r['board_type']) ?></td>
                <td><?= htmlspecialchars($r['category']) ?></td>
                <td><?= $r['avg_daily'] ?> <?= htmlspecialchars($r['unit'] ?? 'pcs') ?>/day</td>
                <td><?= $r['current_stock'] ?></td>
                <td class="muted"><?= $r['dynamic_threshold'] ?></td>
                <td>
                  <?php if ($r['restock_qty'] > 0): ?>
                    <strong class="amber"><?= $r['restock_qty'] ?> pcs</strong>
                  <?php else: ?>
                    <span class="muted">Sufficient</span>
                  <?php endif; ?>
                </td>
                <td><span class="badge <?= htmlspecialchars($r['badge_class']) ?>"><?= htmlspecialchars($r['status_text']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- EXAMPLE CALCULATION -->
      <div class="card mt-2" style="font-size:.82rem;color:var(--text-muted);">
        <div class="card-title">Example Calculation (30-day period)</div>
        <p><em>PETG High Gloss 18mm</em> - Current Stock: 18 pcs | Units Sold (30 days): 21</p>
        <pre style="margin:.5rem 0;padding:.7rem;background:var(--bg);border-radius:6px;font-size:.8rem;color:var(--text);overflow-x:auto;">
Avg Daily Sales = 21 / 30          = 0.70 pcs/day
Restock Qty     = (0.70 * 30) - 18 = 3 pcs recommended</pre>
      </div>

    </div>
  </div>
</div>
<script>
document.getElementById('predSrch').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('#predTbl tbody tr').forEach(r =>
    r.style.display = r.dataset.name.includes(q) ? '' : 'none');
});
</script>
</body>
</html>

