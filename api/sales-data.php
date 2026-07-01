<?php
/**
 * api/sales-data.php
 * Fetches JSON aggregated sales data for charts.
 * Queries real tables: sales, sale_items, products, categories.
 *
 * Query Params:
 * - period: 'daily', 'weekly', 'monthly', 'yearly' (default: monthly)
 * - start_date: YYYY-MM-DD
 * - end_date: YYYY-MM-DD
 * - category_id: optional int
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();
$db = getDB();

$period  = in_array($_GET['period'] ?? '', ['daily','weekly','monthly','yearly']) ? $_GET['period'] : 'monthly';
$endDate = $_GET['end_date'] ?? date('Y-m-d');

if (!empty($_GET['start_date'])) {
    $startDate = $_GET['start_date'];
} else {
    $startDate = match($period) {
        'daily'   => date('Y-m-d', strtotime('-30 days')),
        'weekly'  => date('Y-m-d', strtotime('-12 weeks')),
        'monthly' => date('Y-m-d', strtotime('-12 months')),
        'yearly'  => date('Y-m-d', strtotime('-5 years')),
    };
}

$catId  = $_GET['category_id'] ?? '';
$params = [$startDate, $endDate];
$catSql = '';
if ($catId !== '') {
    $catSql  = ' AND p.category_id = ?';
    $params[] = (int)$catId;
}

// MySQL grouping expression per period
$groupExpr = match($period) {
    'daily'   => "DATE(s.sale_date)",
    'weekly'  => "DATE_FORMAT(s.sale_date, '%Y-W%u')",
    'monthly' => "DATE_FORMAT(s.sale_date, '%Y-%m')",
    'yearly'  => "YEAR(s.sale_date)",
};

// "?"? 1. Trend data (total revenue per period) "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
$trendSql = "
    SELECT {$groupExpr} AS period_label,
           SUM(si.total) AS total_revenue
    FROM   sales s
    JOIN   sale_items si ON si.sale_id = s.id
    JOIN   products   p  ON p.id = si.product_id
    WHERE  s.sale_date BETWEEN ? AND ?
    AND    s.status = 'Valid'
    {$catSql}
    GROUP  BY {$groupExpr}
    ORDER  BY MIN(s.sale_date) ASC
";
$stmt = $db->prepare($trendSql);
$stmt->execute($params);
$trendRows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
$trendLabels = array_column($trendRows, 'period_label');
$trendData   = array_column($trendRows, 'total_revenue');

// "?"? 2. Category pie data "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
$catPieSql = "
    SELECT c.name AS category_name,
           SUM(si.total) AS total_revenue
    FROM   sales s
    JOIN   sale_items si ON si.sale_id = s.id
    JOIN   products   p  ON p.id = si.product_id
    JOIN   categories c  ON c.id = p.category_id
    WHERE  s.sale_date BETWEEN ? AND ?
    AND    s.status = 'Valid'
    {$catSql}
    GROUP  BY c.id, c.name
    ORDER  BY total_revenue DESC
";
$stmt2 = $db->prepare($catPieSql);
$stmt2->execute($params);
$catRows   = $stmt2->fetchAll(PDO::FETCH_ASSOC);
$catLabels = array_column($catRows, 'category_name');
$catData   = array_column($catRows, 'total_revenue');

// "?"? 3. Stacked bar: per period - per category "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
$stackedSql = "
    SELECT {$groupExpr} AS period_label,
           c.name AS category_name,
           SUM(si.total) AS total_revenue
    FROM   sales s
    JOIN   sale_items si ON si.sale_id = s.id
    JOIN   products   p  ON p.id = si.product_id
    JOIN   categories c  ON c.id = p.category_id
    WHERE  s.sale_date BETWEEN ? AND ?
    AND    s.status = 'Valid'
    {$catSql}
    GROUP  BY {$groupExpr}, c.id, c.name
    ORDER  BY MIN(s.sale_date) ASC, total_revenue DESC
";
$stmt3 = $db->prepare($stackedSql);
$stmt3->execute($params);
$stackedRows = $stmt3->fetchAll(PDO::FETCH_ASSOC);

// Build stacked datasets: { catName: [val1, val2, ...] }
$stackedDatasets = [];
foreach ($catLabels as $catLabel) {
    $stackedDatasets[$catLabel] = array_fill(0, count($trendLabels), 0);
}
foreach ($stackedRows as $row) {
    $pIndex = array_search($row['period_label'], $trendLabels);
    if ($pIndex !== false && isset($stackedDatasets[$row['category_name']])) {
        $stackedDatasets[$row['category_name']][$pIndex] = (float)$row['total_revenue'];
    }
}

echo json_encode([
    'trends' => [
        'labels' => $trendLabels,
        'data'   => array_map('floatval', $trendData),
    ],
    'categories' => [
        'labels' => $catLabels,
        'data'   => array_map('floatval', $catData),
    ],
    'stacked' => $stackedDatasets,
]);

