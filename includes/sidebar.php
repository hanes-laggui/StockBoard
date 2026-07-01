<?php
/**
 * includes/sidebar.php  Role-gated navigation sidebar (XAMPP / PHP version).
 * Depends on auth.php being included and BASE_URL defined.
 */
$page = basename($_SERVER['PHP_SELF'], '.php');
$u = currentUser();

$roleLabels = [
  'Administrator'            => ['label' => 'Administrator',           'class' => 'role-owner'],
  'Manager'            => ['label' => 'Manager',           'class' => 'role-admin'],
  'OnlineAgent'      => ['label' => 'Online Agent',    'class' => 'role-invoff'],
  'SalesCashier'     => ['label' => 'Cashier',         'class' => 'role-cashier'],
  'InventoryOfficer' => ['label' => 'Inventory',       'class' => 'role-invoff'],
];
$roleInfo = $roleLabels[$u['role']] ?? ['label' => $u['role'], 'class' => 'role-admin'];

// Track whether we've started a section (for hr dividers)
$sectionOpen = false;
function sectionDivider(bool &$open): string
{
  $hr = $open ? '<hr style="border:none;border-top:1px solid var(--border);margin:.5rem 0 .3rem;opacity:.6;">' : '';
  $open = true;
  return $hr;
}
?>
<nav class="sidebar">
  <div class="sidebar-brand">
    <div class="company">StockBoard</div>
    <div class="tagline">For MCC Mae Anne Cunanan Laminates</div>
    <span class="badge-role <?= $roleInfo['class'] ?>"><?= htmlspecialchars($roleInfo['label']) ?></span>
  </div>

  <div class="sidebar-nav">

    <?php if (canViewDashboard()): ?>
      <?= sectionDivider($sectionOpen) ?>
      <div class="nav-group-label">Overview</div>
      <a href="<?= BASE_URL ?>dashboard.php" class="nav-link <?= $page === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
    <?php endif; ?>

    <?php if (canViewInventory() || canViewMovements() || canViewCategories()): ?>
      <?= sectionDivider($sectionOpen) ?>
      <div class="nav-group-label">Inventory</div>
    <?php endif; ?>

    <?php if (canViewInventory()): ?>
      <a href="<?= BASE_URL ?>inventory.php" class="nav-link <?= $page === 'inventory' ? 'active' : '' ?>">Inventory</a>
    <?php endif; ?>

    <?php if (canViewMovements()): ?>
      <a href="<?= BASE_URL ?>stock_movements.php"
        class="nav-link <?= $page === 'stock_movements' ? 'active' : '' ?>">Stock
        Movements</a>
    <?php endif; ?>

    <?php if (canViewCategories()): ?>
      <a href="<?= BASE_URL ?>categories.php"
        class="nav-link <?= $page === 'categories' ? 'active' : '' ?>">Categories</a>
    <?php endif; ?>

    <?php if (canViewSales()): ?>
      <?= sectionDivider($sectionOpen) ?>
      <div class="nav-group-label">Sales</div>
      <a href="<?= BASE_URL ?>sales.php" class="nav-link <?= $page === 'sales' ? 'active' : '' ?>">Sales</a>
    <?php endif; ?>

    <?php if (canCreateQuotation()): ?>
      <?php if (!canViewSales()) { echo sectionDivider($sectionOpen); echo '<div class="nav-group-label">Sales</div>'; } ?>
      <a href="<?= BASE_URL ?>quotation.php" class="nav-link <?= $page === 'quotation' ? 'active' : '' ?>">Quotation</a>
    <?php endif; ?>

    <?php if (canViewReports() || canViewPredictions()): ?>
      <?= sectionDivider($sectionOpen) ?>
      <div class="nav-group-label">Analytics</div>
    <?php endif; ?>

    <?php if (canViewReports()): ?>
      <a href="<?= BASE_URL ?>reports.php" class="nav-link <?= $page === 'reports' ? 'active' : '' ?>">Reports</a>
    <?php endif; ?>

    <?php if (canViewPredictions()): ?>
      <a href="<?= BASE_URL ?>prediction.php"
        class="nav-link <?= $page === 'prediction' ? 'active' : '' ?>">Prediction</a>
    <?php endif; ?>

    <?php if (canManageUsers() || canViewAuditLog()): ?>
      <?= sectionDivider($sectionOpen) ?>
      <div class="nav-group-label">Administration</div>
    <?php endif; ?>

    <?php if (canManageUsers()): ?>
      <a href="<?= BASE_URL ?>users.php" class="nav-link <?= $page === 'users' ? 'active' : '' ?>">User Management</a>
    <?php endif; ?>

    <?php if (canViewCommissions()): ?>
      <a href="<?= BASE_URL ?>commissions.php" class="nav-link <?= $page === 'commissions' ? 'active' : '' ?>">Agent Commissions</a>
    <?php endif; ?>

    <?php if (canViewAuditLog()): ?>
      <a href="<?= BASE_URL ?>audit_log.php" class="nav-link <?= $page === 'audit_log' ? 'active' : '' ?>">Audit Log</a>
    <?php endif; ?>

  </div>

  <div class="sidebar-bottom">
    <a href="<?= BASE_URL ?>profile.php" style="text-decoration:none;">
      <div class="user-row" style="cursor:pointer;" title="My Profile">
        <?php if (!empty($u['avatar'])): ?>
          <img src="<?= BASE_URL . htmlspecialchars($u['avatar']) ?>" class="avatar" style="object-fit:cover;"
            alt="Avatar" />
        <?php else: ?>
          <div class="avatar"><?= strtoupper(substr($u['full_name'], 0, 1)) ?></div>
        <?php endif; ?>
        <div class="user-details" style="flex: 1; min-width: 0;">
          <div class="uname" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
            <?= htmlspecialchars($u['full_name']) ?>
          </div>
          <div class="urole"><?= htmlspecialchars($roleInfo['label']) ?></div>
        </div>
      </div>
    </a>
    <a href="<?= BASE_URL ?>logout.php" class="btn-signout"
      onclick="return confirm('Are you sure you want to sign out?');" title="Sign Out">
      <span class="icon"></span>
      <span class="signout-text">Sign Out</span>
    </a>
  </div>
</nav>
