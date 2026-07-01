?<?php
/**
 * users.php - Admin-only User Management.
 * Create, edit, deactivate, and delete system users.
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
requireCap('canManageUsers');

$db   = getDB();
$user = currentUser();
$flash = null;

$ROLES = [
    'Administrator', 'Manager', 'OnlineAgent', 'SalesCashier', 'InventoryOfficer',
];

// -- Handle POST --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* -- SAVE USER (add / edit) -- */
    if ($action === 'save_user') {
        $uid      = (int)($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $role     = $_POST['role'] ?? '';
        $password = $_POST['password'] ?? '';
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (!$username || !$fullName || !in_array($role, $ROLES)) {
            $flash = ['type' => 'err', 'msg' => 'Username, Full Name, and valid Role are required.'];
        } else {
            if ($uid === 0) {
                // Add new user
                $confirm = $_POST['confirm_password'] ?? '';
                if (strlen($password) < 6) {
                    $flash = ['type' => 'err', 'msg' => 'Password must be at least 6 characters.'];
                } elseif ($password !== $confirm) {
                    $flash = ['type' => 'err', 'msg' => 'Passwords do not match.'];
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $stmt = $db->prepare('INSERT INTO users (username, password, full_name, role, is_active, is_pending) VALUES (?,?,?,?,?,0)');
                    try {
                        $stmt->execute([$username, $hash, $fullName, $role, $isActive]);
                        $newId = (int)$db->lastInsertId();
                        logAudit($db, 'user.add', 'user', $newId, "Added user: $username ($role)");
                        $flash = ['type' => 'ok', 'msg' => "User \"$username\" created successfully."];
                    } catch (PDOException $e) {
                        $flash = ['type' => 'err', 'msg' => 'Username already exists.'];
                    }
                }
            } else {
                // Edit existing user
                if ($password !== '') {
                    $confirm = $_POST['confirm_password'] ?? '';
                    if (strlen($password) < 6) {
                        $flash = ['type' => 'err', 'msg' => 'Password must be at least 6 characters.'];
                    } elseif ($password !== $confirm) {
                        $flash = ['type' => 'err', 'msg' => 'Passwords do not match.'];
                    } else {
                        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                        $stmt = $db->prepare('UPDATE users SET username=?, password=?, full_name=?, role=?, is_active=?, is_pending=0 WHERE id=?');
                        $stmt->execute([$username, $hash, $fullName, $role, $isActive, $uid]);
                        logAudit($db, 'user.edit', 'user', $uid, "Edited user: $username ($role), password changed");
                        if ($uid === (int)$user['id']) {
                            $_SESSION['username'] = $username;
                            $_SESSION['full_name'] = $fullName;
                            $_SESSION['role'] = $role;
                        }
                        $flash = ['type' => 'ok', 'msg' => "User \"$username\" updated (with new password)."];
                    }
                } else {
                    $stmt = $db->prepare('UPDATE users SET username=?, full_name=?, role=?, is_active=?, is_pending=0 WHERE id=?');
                    $stmt->execute([$username, $fullName, $role, $isActive, $uid]);
                    logAudit($db, 'user.edit', 'user', $uid, "Edited user: $username ($role)");
                    if ($uid === (int)$user['id']) {
                        $_SESSION['username'] = $username;
                        $_SESSION['full_name'] = $fullName;
                        $_SESSION['role'] = $role;
                    }
                    $flash = ['type' => 'ok', 'msg' => "User \"$username\" updated."];
                }
            }
        }
    }

    /* -- DELETE -- */
    if ($action === 'delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === (int)$user['id']) {
            $flash = ['type' => 'err', 'msg' => 'You cannot delete your own account.'];
        } else {
            $u = $db->prepare('SELECT username FROM users WHERE id=?');
            $u->execute([$uid]);
            $uname = $u->fetchColumn() ?: 'unknown';
            try {
                $db->prepare('UPDATE users SET is_deleted=1, is_active=0 WHERE id=?')->execute([$uid]);
                logAudit($db, 'user.delete', 'user', $uid, "Deleted user (soft): $uname");
                $flash = ['type' => 'ok', 'msg' => "User \"$uname\" deleted."];
            } catch (PDOException $e) {
                // Failsafe
                $flash = ['type' => 'err', 'msg' => "Could not delete user: " . $e->getMessage()];
            }
        }
    }

    /* -- TOGGLE ACTIVE -- */
    if ($action === 'toggle_active') {
        $uid     = (int)($_POST['user_id'] ?? 0);
        $current = (int)($_POST['current_active'] ?? 1);
        $new     = $current ? 0 : 1;
        if ($uid === (int)$user['id']) {
            $flash = ['type' => 'err', 'msg' => 'You cannot deactivate your own account.'];
        } else {
            $db->prepare('UPDATE users SET is_active=?, is_pending=0 WHERE id=?')->execute([$new, $uid]);
            $label = $new ? 'activated' : 'deactivated';
            logAudit($db, 'user.' . $label, 'user', $uid, "User $uid $label");
            $flash = ['type' => 'ok', 'msg' => "User $label successfully."];
        }
    }
}

// -- Fetch all users -----------------------------------------------
$users = $db->query('SELECT id, username, full_name, role, avatar, is_active, last_seen, created_at FROM users WHERE is_deleted=0 ORDER BY created_at DESC')->fetchAll();

$roleColors = [
    'Pending'          => '#64748b',
    'Administrator'    => '#f59e0b',
    'Manager'          => '#ef4444',
    'OnlineAgent'      => '#3b82f6',
    'SalesCashier'     => '#10b981',
    'InventoryOfficer' => '#8b5cf6',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>User Management - StockBoard</title>
  <link rel="stylesheet" href="css/style.css?v=5"/>
  <style>
    .role-pill {
      display:inline-block;
      padding:.2rem .6rem;
      border-radius:999px;
      font-size:.72rem;
      font-weight:600;
      color:#fff;
    }
    .status-dot {
      display:inline-block;
      width:8px; height:8px;
      border-radius:50%;
      margin-right:4px;
    }
  </style>
</head>
<body>
<div class="layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <div>
        <div class="topbar-title">User Management</div>
        <div class="topbar-sub">Create and manage system user accounts and roles</div>
      </div>
    </div>
    <div class="page-body">

      <?php if ($flash): ?>
        <div class="flash flash-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
      <?php endif; ?>

      <div class="toolbar">
        <div class="toolbar-left">
          <input type="text" id="srch" class="search-box" placeholder="Search user&hellip;"/>
          <select id="roleF" class="filter-sel">
            <option value="">All Roles</option>
            <?php
              $roleDisplayNames = [
                'Administrator'    => 'Administrator',
                'Manager'          => 'Manager',
                'OnlineAgent'      => 'Online Agent',
                'SalesCashier'     => 'Cashier',
                'InventoryOfficer' => 'Inventory',
              ];
              foreach ($ROLES as $r): ?>
            <option value="<?= $r ?>"><?= $roleDisplayNames[$r] ?? $r ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary" onclick="openAdd()">+ Add User</button>
        </div>
      </div>

      <div class="tbl-wrap">
        <table id="usrTbl">
          <thead>
            <tr>
              <th>#</th><th>Username</th><th>Full Name</th><th>Role</th>
              <th>Status</th><th>Created</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
            <tr data-name="<?= strtolower(htmlspecialchars($u['username'].' '.$u['full_name'])) ?>"
                data-role="<?= htmlspecialchars($u['role']) ?>">
              <td class="muted"><?= $u['id'] ?></td>
              <td class="fw7"><code><?= htmlspecialchars($u['username']) ?></code></td>
              <td>
                <div style="display:flex;align-items:center;gap:.55rem;">
                  <?php if (!empty($u['avatar'])): ?>
                    <img src="<?= BASE_URL . htmlspecialchars($u['avatar']) ?>" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;border:1px solid var(--border);flex-shrink:0;">
                  <?php else: ?>
                    <div style="width:32px;height:32px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;flex-shrink:0;">
                      <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                    </div>
                  <?php endif; ?>
                  <span><?= htmlspecialchars($u['full_name']) ?></span>
                </div>
              </td>
              <td>
                <?php
                  $roleLabelsMap = [
                    'Administrator'    => 'Administrator',
                    'Manager'          => 'Manager',
                    'OnlineAgent'      => 'Online Agent',
                    'SalesCashier'     => 'Cashier',
                    'InventoryOfficer' => 'Inventory',
                  ];
                ?>
                <span class="role-pill" style="background:<?= $roleColors[$u['role']] ?? '#64748b' ?>">
                  <?= htmlspecialchars($roleLabelsMap[$u['role']] ?? $u['role']) ?>
                </span>
              </td>
              <td>
                <?php
                  $isOnline = false;
                  if (!empty($u['last_seen'])) {
                      $diff = time() - strtotime($u['last_seen']);
                      if ($diff <= 300) $isOnline = true; // 5 minutes threshold
                  }
                  if (!$u['is_active']) {
                      echo '<span class="status-dot" style="background:#ef4444"></span>Disabled';
                  } elseif ($isOnline) {
                      echo '<span class="status-dot" style="background:#22c55e"></span>Active (Online)';
                  } else {
                      echo '<span class="status-dot" style="background:#94a3b8"></span>Inactive (Offline)';
                  }
                ?>
              </td>
              <td class="muted" style="font-size:.8rem;"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
              <td>
                <button class="btn btn-ghost btn-sm" onclick="openEdit(
                  <?= $u['id'] ?>,'<?= addslashes($u['username']) ?>','<?= addslashes($u['full_name']) ?>',
                  '<?= $u['role'] ?>',<?= $u['is_active'] ?>
                )">Edit</button>
                <!-- Toggle Active -->
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="toggle_active"/>
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>"/>
                  <input type="hidden" name="current_active" value="<?= $u['is_active'] ?>"/>
                  <button class="btn btn-warning btn-sm" type="submit"
                    title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>">
                    <?= $u['is_active'] ? 'Disable' : 'Enable' ?>
                  </button>
                </form>
                <?php if ($u['id'] !== (int)$user['id']): ?>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('Delete user <?= htmlspecialchars($u['username']) ?>?')">
                  <input type="hidden" name="action" value="delete_user"/>
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>"/>
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

<!-- ADD / EDIT USER MODAL -->
<div class="overlay" id="userModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header">
      <div class="modal-title" id="mTitle">Add User</div>
      <button class="modal-close" onclick="closeModal('userModal')">X</button>
    </div>
    <form method="post" action="users.php">
      <input type="hidden" name="action" value="save_user"/>
      <input type="hidden" name="user_id" id="f_uid" value="0"/>

      <div class="form-grid-2">
        <div class="form-group">
          <label>Username *</label>
          <input type="text" name="username" id="f_uname" class="form-control"
                 placeholder="e.g. jdoe" required autocomplete="username"/>
        </div>
        <div class="form-group">
          <label>Full Name *</label>
          <input type="text" name="full_name" id="f_fname" class="form-control"
                 placeholder="e.g. Juan Dela Cruz" required/>
        </div>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label>Role *</label>
          <select name="role" id="f_role" class="form-control" required>
            <?php
              $roleDisplayNames = [
                'Administrator'    => 'Administrator',
                'Manager'          => 'Manager',
                'OnlineAgent'      => 'Online Agent',
                'SalesCashier'     => 'Cashier',
                'InventoryOfficer' => 'Inventory',
              ];
              foreach ($ROLES as $r): ?>
            <option value="<?= $r ?>"><?= $roleDisplayNames[$r] ?? $r ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Status</label>
          <label style="display:flex;align-items:center;gap:.5rem;margin-top:.5rem;">
            <input type="checkbox" name="is_active" id="f_active" value="1" checked/>
            <span>Active</span>
          </label>
        </div>
      </div>

      <div class="form-grid-2" style="align-items:end;">
        <div class="form-group">
          <label id="pwLabel">Password * <span id="pwHint" class="muted" style="font-size:.75rem;"></span></label>
          <input type="password" name="password" id="f_pw" class="form-control"
                 placeholder="Min 6 characters" autocomplete="new-password"/>
        </div>
        <div class="form-group">
          <label id="pwConfLabel">Confirm Password *</label>
          <input type="password" name="confirm_password" id="f_pw_conf" class="form-control"
                 placeholder="Retype password" autocomplete="new-password"/>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('userModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save User</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAdd() {
  document.getElementById('mTitle').textContent = 'Add User';
  document.getElementById('f_uid').value   = '0';
  document.getElementById('f_uname').value = '';
  document.getElementById('f_fname').value = '';
  document.getElementById('f_role').value  = 'SalesCashier';
  document.getElementById('f_active').checked = true;
  document.getElementById('f_pw').value    = '';
  document.getElementById('f_pw').required = true;
  document.getElementById('f_pw_conf').value    = '';
  document.getElementById('f_pw_conf').required = true;
  document.getElementById('pwHint').textContent = '';
  document.getElementById('userModal').classList.add('open');
}
function openEdit(id, uname, fname, role, isActive) {
  document.getElementById('mTitle').textContent = 'Edit User';
  document.getElementById('f_uid').value   = id;
  document.getElementById('f_uname').value = uname;
  document.getElementById('f_fname').value = fname;
  document.getElementById('f_role').value  = role;
  document.getElementById('f_active').checked = !!isActive;
  document.getElementById('f_pw').value    = '';
  document.getElementById('f_pw').required = false;
  document.getElementById('f_pw_conf').value    = '';
  document.getElementById('f_pw_conf').required = false;
  document.getElementById('pwHint').textContent = '(leave blank to keep current password)';
  document.getElementById('userModal').classList.add('open');
}
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.overlay').forEach(el =>
  el.addEventListener('click', e => { if (e.target===el) closeModal(el.id); }));

// Filter
(function(){
  const rows = document.querySelectorAll('#usrTbl tbody tr');
  function filter() {
    const q  = document.getElementById('srch').value.toLowerCase();
    const rf = document.getElementById('roleF').value;
    rows.forEach(r => {
      const ok = (!q  || r.dataset.name.includes(q))
              && (!rf || r.dataset.role === rf);
      r.style.display = ok ? '' : 'none';
    });
  }
  document.getElementById('srch').addEventListener('input', filter);
  document.getElementById('roleF').addEventListener('change', filter);
})();
</script>
</body>
</html>

