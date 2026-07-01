?<?php
/**
 * dashboard.php - Admin overview for StockBoard Dealer.
 *
 * Shows:
 *  - 4 stat cards: Total Board Types, Today's Revenue, Low-Stock Count, Best-Selling Product
 *  - Bar chart: board sales trend (last 7 days)
 *  - Pie chart: sales by board category today
 *  - Low-stock alert list
 *
 * All queries use PDO prepared statements for safety.
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/stock_status.php';
requireCap('canViewDashboard');

$db = getDB();
$user = currentUser();
$statuses = getDynamicStockStatuses($db);

// -- Stat cards ---------------------------------------------------
$totalTypes = $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
$todayRevenue = $db->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date=CURDATE() AND status='Valid'")->fetchColumn();

// Count low-stock using dynamic thresholds (all products)
$lowCount = 0;
foreach ($statuses as $pid => $s) {
  if (in_array($s['risk'], ['critical', 'warning', 'low'])) {
    $lowCount++;
  }
}

// Best-selling product (by total units sold, all time)
$best = $db->query("
    SELECT p.board_type, SUM(si.quantity) AS total_units
    FROM sale_items si 
    JOIN products p ON p.id=si.product_id
    JOIN sales s ON s.id=si.sale_id
    WHERE s.status='Valid'
    GROUP BY p.id ORDER BY total_units DESC LIMIT 1")->fetch();

// -- Pie and Trend data are now loaded via API client-side --

// -- Low-stock list (dynamic threshold, all products) ----------
$allProducts = $db->query("
    SELECT p.id, p.board_type, p.current_stock, p.low_stock_threshold
    FROM products p
    WHERE p.is_active = 1
    ORDER BY p.current_stock ASC
")->fetchAll();

$lowItems = [];
foreach ($allProducts as $p) {
  $s = $statuses[$p['id']] ?? null;
  if ($s && in_array($s['risk'], ['critical', 'warning', 'low'])) {
    $p['dynamic_threshold'] = $s['dynamic_threshold'];
    $p['risk'] = $s['risk'];
    $p['status_label'] = $s['status_label'];
    $p['badge_class'] = $s['badge_class'];
    $lowItems[] = $p;
    if (count($lowItems) >= 8)
      break;
  }
}


?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1.0" />
  <title>Dashboard - StockBoard</title>
  <link rel="stylesheet" href="css/style.css?v=5" />
</head>

<body>
  <div class="layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <div class="topbar">
        <div>
          <div class="topbar-title">Dashboard</div>
          <div class="topbar-sub">Overview of your laminated boards inventory</div>
        </div>
        <div class="topbar-meta"><?= date('l, F j, Y') ?></div>
      </div>
      <div class="page-body">

        <?php if (isset($_GET['error']) && $_GET['error'] === 'access_denied'): ?>
          <div class="flash flash-err">Access denied.</div>
        <?php endif; ?>

        <!-- STAT CARDS -->
        <div class="stats-row">
          <div class="stat-card">
            <div class="sc-icon"></div>
            <div>
              <div class="sc-val"><?= $totalTypes ?></div>
              <div class="sc-lbl">Total Board Types</div>
            </div>
          </div>
          <div class="stat-card">
            <div class="sc-icon"></div>
            <div>
              <div class="sc-val green">&#8369;<?= number_format($todayRevenue, 2) ?></div>
              <div class="sc-lbl">Today's Revenue</div>
            </div>
          </div>
          <div class="stat-card">
            <div class="sc-icon"></div>
            <div>
              <div class="sc-val red"><?= $lowCount ?></div>
              <div class="sc-lbl">Low-Stock Types</div>
            </div>
          </div>
          <div class="stat-card">
            <div class="sc-icon"></div>
            <div>
              <div class="sc-val" style="font-size:1rem;"><?= $best ? htmlspecialchars($best['board_type']) : '-' ?>
              </div>
              <div class="sc-lbl">Best-Selling Product</div>
            </div>
          </div>
        </div>

        <!-- CHARTS & FILTERS -->
        <div class="toolbar mb-2">
          <div class="toolbar-left">
            <div class="tab-row" style="margin-bottom:0;">
              <button type="button" class="tab-btn" data-period="daily">Daily</button>
              <button type="button" class="tab-btn" data-period="weekly">Weekly</button>
              <button type="button" class="tab-btn active" data-period="monthly">Monthly</button>
              <button type="button" class="tab-btn" data-period="yearly">Yearly</button>
            </div>
          </div>
        </div>

        <div class="charts-row mb-2">
          <div class="card">
            <div class="card-title">Sales Trend</div>
            <canvas id="trendChart"></canvas>
          </div>
          <div class="card">
            <div class="card-title">Sales by Category</div>
            <canvas id="pieChart"></canvas>
          </div>
        </div>

        <!-- LOW-STOCK PANEL -->
        <div class="card">
          <div class="card-title">Low-Stock Alerts</div>
          <?php if (empty($lowItems)): ?>
            <p class="muted" style="font-size:.84rem;">All board types are adequately stocked.</p>
          <?php else: ?>
            <div class="tbl-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Board Type</th>
                    <th>Current Stock</th>
                    <th>Auto Threshold</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($lowItems as $r): ?>
                    <tr>
                      <td class="fw7"><?= htmlspecialchars($r['board_type']) ?></td>
                      <td class="<?= $r['risk'] === 'critical' ? 'red' : 'amber' ?> fw7"><?= $r['current_stock'] ?></td>
                      <td class="muted"><?= $r['dynamic_threshold'] ?></td>
                      <td><span
                          class="badge <?= htmlspecialchars($r['badge_class']) ?>"><?= htmlspecialchars($r['status_label']) ?></span>
                      </td>
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
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <script src="js/charts.js"></script>
  <script>
    const PAL = ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#be185d'];

    function loadDashCharts(p = 'monthly') {
      fetch(`api/sales-data.php?period=${p}`)
        .then(res => res.json())
        .then(data => {
          renderLine('trendChart', data.trends.labels, data.trends.data, { label: 'Revenue (PHP )', color: '#2563eb' });
          renderPie('pieChart', data.categories.labels, data.categories.data, PAL);
        })
        .catch(err => console.error("Error fetching dashboard charts:", err));
    }

    document.querySelectorAll('.tab-btn[data-period]').forEach(btn => {
      btn.addEventListener('click', function () {
        document.querySelectorAll('.tab-btn[data-period]').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        loadDashCharts(this.dataset.period);
      });
    });

    loadDashCharts('monthly');
  </script>
</body>

</html>
