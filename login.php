?<?php
/**
 * login.php - Session-based authentication for StockBoard Dealer.
 *
 * Flow:
 *   1. User submits username + password.
 *   2. Fetch user by username; verify bcrypt hash.
 *   3. On success: set session -> redirect Manager->dashboard, Staff->sales.
 *   4. On failure: re-render form with error.
 *
 * Default demo credentials (from scratch/update_roles.php):
 *   Manager: admin / password123
 *   Sales: cashier / password123
 *   Inventory: inventory / password123
 *   Agent: agent / password123
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

if (isLoggedIn()) {
  header('Location: dashboard.php');
  exit;
}

// Removed staff auto-insert logic as role is being deprecated.

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $uname = trim($_POST['username'] ?? '');
  $pass = $_POST['password'] ?? '';

  if ($uname === '' || $pass === '') {
    $error = 'Please enter both username and password.';
  } else {
    $stmt = getDB()->prepare('SELECT id, password, full_name, role, is_active, is_pending FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$uname]);
    $user = $stmt->fetch();

    if ($user && password_verify($pass, $user['password'])) {
      if ($user['is_pending'] ?? 0) {
        $error = 'Your account is pending administrator approval. You will be notified once it is activated.';
      } elseif (!($user['is_active'] ?? 1)) {
        $error = 'Your account has been deactivated. Contact an administrator.';
      } else {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $uname;
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        // -- Record login in audit log --------------------
        $auditDb = getDB();
        $ip = substr($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
        $auditDb->prepare(
          'INSERT INTO audit_log (user_id, action, target_type, target_id, detail, ip_address)
                     VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$user['id'], 'user.login', 'user', $user['id'], "Signed in: {$uname}", $ip]);
        $landing = BASE_URL . roleLandingPage();
        header('Location: ' . $landing);
        exit;
      }
    } else {
      $error = 'Incorrect username or password.';
    }
  }
}
$loggedOut = isset($_GET['msg']) && $_GET['msg'] === 'out';

// Check if this is a fresh installation (no users yet)
$isFirstInstall = false;
try {
  $isFirstInstall = ((int) getDB()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0);
} catch (Exception $e) {
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1.0" />
  <title>Login - StockBoard</title>
  <link rel="stylesheet" href="css/style.css?v=5" />
</head>

<body>
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-logo">
        <img src="uploads/logo.jpg" alt="StockBoard Logo" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border); margin-bottom: 0.8rem; box-shadow: 0 4px 6px rgba(0,0,0,0.05);" />
        <div class="company-name">StockBoard</div>
        <div class="system-name">For MCC Mae Anne Cunanan Laminates
        </div>
      </div>

      <!-- Tab switcher -->
      <div style="display:flex;gap:0;margin-bottom:1.4rem;border-bottom:1px solid var(--border);">
        <span
          style="flex:1;text-align:center;padding:.6rem 0;font-size:.88rem;font-weight:600;color:var(--accent);border-bottom:2px solid var(--accent);cursor:default;">Sign
          In</span>
        <a href="register.php"
          style="flex:1;text-align:center;padding:.6rem 0;font-size:.88rem;color:var(--text-muted);text-decoration:none;border-bottom:2px solid transparent;">Create
          Account</a>
      </div>

      <?php if ($isFirstInstall): ?>
        <div
          style="text-align:center;margin-bottom:1rem;padding:.65rem .8rem;background:rgba(168,85,247,.08);border:1px solid rgba(168,85,247,.25);border-radius:7px;font-size:.8rem;color:#6b21a8;">
          Fresh installation detected. <a href="setup.php" style="color:#7e22ce;font-weight:600;">Create the first Manager
            account &rarr;</a>
        </div>
      <?php endif; ?>

      <?php if ($loggedOut): ?>
        <div class="flash flash-ok">You have been signed out.</div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="flash flash-err"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" action="login.php">
        <label for="un">Username</label>
        <input id="un" name="username" type="text" class="form-control" placeholder="Enter username"
          value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autofocus autocomplete="username" required />

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: .3rem;">
          <label for="pw" style="margin-bottom: 0;">Password</label>
          <a href="#" style="font-size: .75rem; color: var(--accent); text-decoration: none;" onclick="alert('Forgot password functionality coming soon!'); return false;">Forgot Password?</a>
        </div>
        <div style="position:relative; margin-bottom:.85rem;">
          <input id="pw" name="password" type="password" class="form-control" placeholder="Enter password"
            autocomplete="current-password" required style="padding-right: 40px; margin-bottom: 0;" />
          <span id="togglePw"
            style="position:absolute; right:12px; top:50%; transform:translateY(-50%); cursor:pointer; user-select:none; opacity:0.6; display:flex; align-items:center; color:var(--text-muted);">
            <svg id="iconClosed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
              stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path
                d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24">
              </path>
              <line x1="1" y1="1" x2="23" y2="23"></line>
            </svg>
            <svg id="iconOpen" style="display:none;" width="18" height="18" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
              <circle cx="12" cy="12" r="3"></circle>
            </svg>
          </span>
        </div>

        <button type="submit" class="btn btn-primary btn-full" style="margin-top:.4rem">Sign In</button>
      </form>

      <script>
        document.getElementById('togglePw').addEventListener('click', function () {
          const pw = document.getElementById('pw');
          const iconClosed = document.getElementById('iconClosed');
          const iconOpen = document.getElementById('iconOpen');
          if (pw.type === 'password') {
            pw.type = 'text';
            iconClosed.style.display = 'none';
            iconOpen.style.display = 'block';
            this.style.opacity = '1';
          } else {
            pw.type = 'password';
            iconClosed.style.display = 'block';
            iconOpen.style.display = 'none';
            this.style.opacity = '0.6';
          }
        });
      </script>


    </div>
  </div>
</body>

</html>
