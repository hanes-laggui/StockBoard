?<?php
/**
 * audit_log.php - Audit trail viewer.
 * Accessible by Admin and Auditor.
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
requireCap('canViewAuditLog');

$db   = getDB();
$user = currentUser();

// -- Filters -------------------------------------------------------
$fromDate  = $_GET['from']    ?? date('Y-m-01');
$toDate    = $_GET['to']      ?? date('Y-m-d');
$filterUid = $_GET['user_id'] ?? '';
$filterAct = $_GET['action']  ?? '';

// Fetch users for filter dropdown
$allUsers = $db->query('SELECT id, username, full_name FROM users ORDER BY full_name')->fetchAll();

// Build query
$params = [$fromDate, $toDate];
$where  = '';
if ($filterUid !== '') {
    $where   .= ' AND al.user_id = ?';
    $params[] = $filterUid;
}
if ($filterAct !== '') {
    $where   .= ' AND al.action LIKE ?';
    $params[] = $filterAct . '%';
}

$stmt = $db->prepare("
    SELECT al.id, al.action, al.target_type, al.target_id, al.detail,
           al.ip_address, al.created_at,
           u.username, u.full_name, u.role
    FROM audit_log al
    JOIN users u ON u.id = al.user_id
    WHERE DATE(al.created_at) BETWEEN ? AND ?
    $where
    ORDER BY al.created_at DESC
    LIMIT 500
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Distinct action prefixes for filter
$actionTypes = $db->query("SELECT DISTINCT SUBSTRING_INDEX(action,'.',1) AS prefix FROM audit_log ORDER BY prefix")->fetchAll(PDO::FETCH_COLUMN);

// CSV export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="audit_log_' . $fromDate . '_' . $toDate . '.csv"');
    $f = fopen('php://output', 'w');
    fputcsv($f, ['ID','Timestamp','User','Role','Action','Target Type','Target ID','Detail','IP']);
    foreach ($logs as $r) {
        fputcsv($f, [
            $r['id'], $r['created_at'], $r['username'], $r['role'],
            $r['action'], $r['target_type'], $r['target_id'], $r['detail'], $r['ip_address'],
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
  <title>Audit Log - StockBoard</title>
  <link rel="stylesheet" href="css/style.css?v=5"/>
  <style>
    .action-badge {
      display:inline-block; padding:.15rem .5rem;
      border-radius:4px; font-size:.72rem; font-weight:600;
    }
    .act-product { background:#1e3a5f; color:#60a5fa; }
    .act-sale    { background:#14532d; color:#16a34a; }
    .act-stock   { background:#431407; color:#fb923c; }
    .act-user    { background:#3b0764; color:#c084fc; }
    .act-other   { background:#1e293b; color:#94a3b8; }
  </style>
</head>
<body>
<div class="layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <div>
        <div class="topbar-title">Audit Log</div>
        <div class="topbar-sub">Complete history of system changes - who did what and when</div>
      </div>
    </div>
    <div class="page-body">

      <!-- FILTER BAR -->
      <div class="card mb-2 no-print">
        <form method="get" action="audit_log.php">
          <div class="toolbar" style="flex-wrap:wrap;gap:.5rem;">
            <div class="toolbar-left" style="flex-wrap:wrap;gap:.5rem;">
              <select name="user_id" class="filter-sel" onchange="this.form.submit()">
                <option value="">All Users</option>
                <?php foreach ($allUsers as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $filterUid==$u['id']?'selected':'' ?>>
                  <?= htmlspecialchars($u['full_name']) ?> (<?= $u['username'] ?>)
                </option>
                <?php endforeach; ?>
              </select>
              <select name="action" class="filter-sel" onchange="this.form.submit()">
                <option value="">All Actions</option>
                <?php foreach ($actionTypes as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>" <?= $filterAct===$a?'selected':'' ?>>
                  <?= ucfirst($a) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <label class="muted" style="font-size:.78rem;">From</label>
              <input type="date" name="from" class="form-control" style="width:145px;" value="<?= htmlspecialchars($fromDate) ?>" onchange="this.form.submit()"/>
              <label class="muted" style="font-size:.78rem;">To</label>
              <input type="date" name="to" class="form-control" style="width:145px;" value="<?= htmlspecialchars($toDate) ?>" onchange="this.form.submit()"/>
              <button type="submit" class="btn btn-ghost">Filter</button>
            </div>
            <div class="toolbar-right">
              <button type="button" onclick="window.print()" class="btn btn-ghost">Export PDF</button>
              <a href="audit_log.php?from=<?= $fromDate ?>&to=<?= $toDate ?>&user_id=<?= $filterUid ?>&action=<?= $filterAct ?>&export=1"
                 class="btn btn-ghost">Export CSV</a>
            </div>
          </div>
        </form>
      </div>

      <!-- SUMMARY -->
      <div class="stats-row mb-2">
        <div class="stat-card">
          <div class="sc-icon"></div>
          <div><div class="sc-val"><?= count($logs) ?></div><div class="sc-lbl">Log Entries</div></div>
        </div>
        <div class="stat-card">
          <div class="sc-icon"></div>
          <div><div class="sc-val"><?= count(array_unique(array_column($logs,'username'))) ?></div><div class="sc-lbl">Users Active</div></div>
        </div>
      </div>

      <!-- LOG TABLE -->
      <div class="card">
        <div class="card-title">Activity Log</div>

        <?php if (empty($logs)): ?>
          <p class="muted" style="font-size:.84rem;">No audit entries found for this period.</p>
        <?php else: ?>
        <div class="tbl-wrap">
          <table id="auditTbl">
            <thead>
              <tr>
                <th>#</th>
                <th>Timestamp</th>
                <th>User</th>
                <th>Role</th>
                <th>Action</th>
                <th>Target</th>
                <th>Detail</th>
                <th>IP</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($logs as $r):
                $prefix = explode('.', $r['action'])[0];
                $actClass = match($prefix) {
                  'product' => 'act-product',
                  'sale'    => 'act-sale',
                  'stock'   => 'act-stock',
                  'user'    => 'act-user',
                  default   => 'act-other',
                };
              ?>
              <tr>
                <td class="muted"><?= $r['id'] ?></td>
                <td style="font-size:.8rem;white-space:nowrap;"><?= htmlspecialchars($r['created_at']) ?></td>
                <td>
                  <div class="fw7" style="font-size:.85rem;"><?= htmlspecialchars($r['full_name']) ?></div>
                  <div class="muted" style="font-size:.74rem;"><?= htmlspecialchars($r['username']) ?></div>
                </td>
                <td><span class="muted" style="font-size:.75rem;"><?= htmlspecialchars($r['role']) ?></span></td>
                <td><span class="action-badge <?= $actClass ?>"><?= htmlspecialchars($r['action']) ?></span></td>
                <td class="muted" style="font-size:.8rem;">
                  <?= $r['target_type'] ? htmlspecialchars($r['target_type']) . ' #' . ($r['target_id'] ?? '-') : '-' ?>
                </td>
                <td style="font-size:.8rem;max-width:260px;word-break:break-word;"><?= htmlspecialchars($r['detail'] ?? '') ?></td>
                <td class="muted" style="font-size:.75rem;"><?= htmlspecialchars($r['ip_address'] ?? '') ?></td>
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

