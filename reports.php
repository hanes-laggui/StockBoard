?<?php
/**
 * reports.php - Manager and Administrator only.
 *
 * Generates Daily, Weekly, and Monthly sales reports for laminated boards.
 * Shows a table: Date | Board Type | Category | Total Quantity | Total Amount (PHP ).
 * Two charts: line/bar for trend, pie for category breakdown.
 * Export as CSV.
 *
 * Period grouping:
 *   daily   -> DATE_FORMAT(sale_date,'%Y-%m-%d')
 *   weekly  -> YEARWEEK(sale_date,1)
 *   monthly -> DATE_FORMAT(sale_date,'%Y-%m')
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
requireCap('canViewReports');

$db = getDB();

// -- Filter params -------------------------------------------------
$tab  = in_array($_GET['tab'] ?? '', ['daily','weekly','monthly','yearly']) ? $_GET['tab'] : 'monthly';
$catF = $_GET['category_id'] ?? '';

// Priority 1: Exact date range from GET params
$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';

// Priority 2: Auto-calculate based on tab if exact dates aren't provided
if (!$from || !$to) {
    if ($tab === 'daily') {
        $from = date('Y-m-d', strtotime('-30 days'));
        $to   = date('Y-m-d');
    } elseif ($tab === 'weekly') {
        $from = date('Y-m-d', strtotime('-12 weeks'));
        $to   = date('Y-m-d');
    } elseif ($tab === 'yearly') {
        $from = date('Y-01-01', strtotime('-5 years'));
        $to   = date('Y-12-31');
    } else {
        // Monthly (Default)
        $from = date('Y-m-01', strtotime('-12 months'));
        $to   = date('Y-m-d');
    }
}

$fmtMap = ['daily'=>'%Y-%m-%d','weekly'=>'%x Week %v','monthly'=>'%Y-%m','yearly'=>'%Y'];
$fmt    = $fmtMap[$tab];

// Fetch categories for the filter
$cats = $db->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

$params = [$fmt, $from, $to];
$catSql = '';
if ($catF) {
    $catSql = ' AND c.id = ?';
    $params[] = $catF;
}

// -- Detail table: board-level -------------------------------------
$detail = $db->prepare("
    SELECT DATE_FORMAT(s.sale_date, ?) AS period,
           s.invoice_no,
           p.board_type, p.color_design, c.name AS category,
           SUM(si.quantity) AS total_qty,
           SUM(si.total) AS total_amount,
           SUM(si.total) - SUM(p.cost_price * si.quantity) AS net_amount,
           AVG(si.price_per_unit) AS avg_price
    FROM sale_items si
    JOIN products p    ON p.id  = si.product_id
    JOIN categories c  ON c.id  = p.category_id
    JOIN sales s       ON s.id  = si.sale_id
    WHERE s.sale_date BETWEEN ? AND ? AND s.status = 'Valid' {$catSql}
    GROUP BY period, s.invoice_no, p.id, p.board_type, p.color_design, c.name
    ORDER BY period ASC, total_amount DESC");
$detail->execute($params);
$detailRows = $detail->fetchAll();

// -- Trend data and category breakdowns are loaded via JS fetch --

// -- Summary -------------------------------------------------------
$sumRev  = array_sum(array_column($detailRows,'total_amount'));
$sumNet  = array_sum(array_column($detailRows,'net_amount'));
$sumQty  = array_sum(array_column($detailRows,'total_qty'));

// -- CSV Export ----------------------------------------------------
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="report_'.$tab.'_'.$from.'_'.$to.'.csv"');
    $f = fopen('php://output','w');
    fputcsv($f,['Period','Board Type','Category','Total Qty','Gross Amount (PHP)','Net Amount (PHP)']);
    foreach ($detailRows as $r) fputcsv($f,[$r['period'],$r['board_type'],$r['category'],$r['total_qty'],number_format($r['total_amount'],2),number_format($r['net_amount'],2)]);
    fclose($f); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Reports - StockBoard</title>
  <link rel="stylesheet" href="css/style.css?v=5"/>
</head>
<body>
<div class="layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <div><div class="topbar-title">Sales Reports</div><div class="topbar-sub">Analyze board sales performance and trends</div></div>
    </div>
    <div class="page-body">

      <!-- FILTER BAR -->
      <div class="card mb-2">
        <form method="get" action="reports.php">
          <div class="toolbar">
            <div class="toolbar-left">
              <select name="category_id" class="filter-sel" onchange="this.form.submit()">
                <option value="">All Categories</option>
                <?php foreach ($cats as $c): ?>
                  <option value="<?= $c['id'] ?>" <?= $catF == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="date-group" style="display:inline-flex; align-items:center; gap:.5rem;">
                <label class="muted" style="font-size:.75rem; font-weight:600;">FROM</label>
                <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="filter-sel" onchange="this.form.submit()" />
                <label class="muted" style="font-size:.75rem; font-weight:600;">TO</label>
                <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="filter-sel" onchange="this.form.submit()" />
              </div>
              <input type="hidden" name="tab" value="<?= $tab ?>" />
            </div>
            <div class="toolbar-right">
              <button type="button" class="btn btn-ghost" onclick="window.print()">Print PDF</button>
              <a href="reports.php?tab=<?= $tab ?>&from=<?= $from ?>&to=<?= $to ?>&export=1"
                 class="btn btn-ghost">Export CSV</a>
            </div>
          </div>
          <!-- Period Tabs -->
          <div class="tab-row" style="margin-top:.75rem;margin-bottom:0;">
            <?php foreach (['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','yearly'=>'Yearly'] as $k=>$lbl): ?>
              <button type="submit" name="tab" value="<?= $k ?>"
                      class="tab-btn <?= $tab===$k?'active':'' ?>"><?= $lbl ?></button>
            <?php endforeach; ?>
          </div>
        </form>
      </div>

      <!-- SUMMARY CARDS -->
      <div class="stats-row mb-2">
        <div class="stat-card">
          <div class="sc-icon"></div>
          <div><div class="sc-val green">PHP <?= number_format($sumNet,2) ?></div><div class="sc-lbl">Net Revenue</div></div>
        </div>
        <div class="stat-card">
          <div class="sc-icon"></div>
          <div><div class="sc-val" style="color:#64748b;">PHP <?= number_format($sumRev,2) ?></div><div class="sc-lbl">Gross Revenue</div></div>
        </div>
        <div class="stat-card">
          <div class="sc-icon"></div>
          <div><div class="sc-val"><?= number_format($sumQty) ?></div><div class="sc-lbl">Total Boards Sold</div></div>
        </div>
      </div>

      <!-- CHARTS -->
      <div class="charts-row">
        <div class="card">
          <div class="card-title">Net Revenue Trend (<?= ucfirst($tab) ?>)</div>
          <canvas id="trendChart" style="max-height:260px;"></canvas>
        </div>
        <div class="card">
          <div class="card-title">Revenue by Category (<?= ucfirst($tab) ?>)</div>
          <canvas id="catChart" style="max-height:260px;"></canvas>
        </div>
      </div>

      <!-- DETAIL TABLE -->
      <div class="card mt-2">
        <div class="card-title">Detail Report - <?= ucfirst($tab) ?> (<?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?>)</div>
        <div class="toolbar mb-2">
          <div class="toolbar-left">
            <input type="text" id="rpSrch" class="search-box" placeholder="Search board type&hellip;"/>
          </div>
        </div>
        <div class="tbl-wrap">
          <table id="rpTbl">
            <thead>
              <tr><th>Period</th><th>Invoice</th><th>Board Type</th><th>Color/Design</th><th>Category</th><th style="text-align:right;">Total Qty</th><th style="text-align:right;">Gross Amount (PHP)</th><th style="text-align:right;">Net Amount (PHP)</th></tr>
            </thead>
            <tbody>
              <?php foreach ($detailRows as $r): ?>
                <tr data-name="<?= strtolower(htmlspecialchars($r['period'].' '.$r['board_type'].' '.$r['category'].' '.$r['color_design'].' '.$r['invoice_no'])) ?>">
                  <td class="muted"><?= htmlspecialchars($r['period']) ?></td>
                  <td class="muted" style="font-size:.8rem;"><?= htmlspecialchars($r['invoice_no'] ?: '-') ?></td>
                  <td class="fw7"><?= htmlspecialchars($r['board_type']) ?></td>
                  <td><?= htmlspecialchars($r['color_design'] ?: '-') ?></td>
                  <td><?= htmlspecialchars($r['category']) ?></td>
                  <td style="text-align:right;"><?= number_format($r['total_qty']) ?></td>
                  <td class="green fw7" style="text-align:right;">PHP <?= number_format($r['total_amount'],2) ?></td>
                  <td class="fw7" style="text-align:right; color:#16a34a;">PHP <?= number_format($r['net_amount'],2) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($detailRows)): ?>
                <tr><td colspan="8" class="tc muted" style="padding:2rem;">No data for the selected period.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="js/charts.js"></script>
<script>
const PAL = ['#2563eb','#16a34a','#d97706','#dc2626','#7c3aed','#0891b2','#be185d','#db2777','#ca8a04','#059669'];

const catSel = document.querySelector('select[name="category_id"]');

function updateCharts() {
    const p = "<?= $tab ?>";
    const from = "<?= $from ?>";
    const to = "<?= $to ?>";
    fetch(`api/sales-charts.php?period=${p}&category_id=${catSel.value}&start_date=${from}&end_date=${to}`)
      .then(res => res.json())
      .then(data => {
          renderLine('trendChart', data.trends.labels, data.trends.net_data, { label:'Net Revenue (PHP)', color:'#16a34a' });
          renderStackedBar('catChart', data.trends.labels, data.stacked, { colors: PAL });
      })
      .catch(err => console.error("Error fetching report charts:", err));
}

catSel.addEventListener('change', updateCharts);
updateCharts();
document.getElementById('rpSrch').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('#rpTbl tbody tr').forEach(r =>
    r.style.display = (!q || r.dataset.name.includes(q)) ? '' : 'none');
});
</script>
</body>
</html>

