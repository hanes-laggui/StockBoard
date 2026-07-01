?
<?php
/**
 * register.php - Self-registration for StockBoard.
 *
 * Creates user with is_pending=1, is_active=0, default role='Pending'.
 * Account becomes usable only after Manager approves it in User Management.
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

// Already logged in -> go to dashboard
if (isLoggedIn()) {
  header('Location: ' . BASE_URL . 'dashboard.php');
  exit;
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $full_name = trim($_POST['full_name'] ?? '');
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  $confirm = $_POST['confirm'] ?? '';

  // Validate
  if (!$full_name || !$username || !$password || !$confirm) {
    $error = 'All fields are required.';
  } elseif (strlen($password) < 6) {
    $error = 'Password must be at least 6 characters.';
  } elseif ($password !== $confirm) {
    $error = 'Passwords do not match.';
  } elseif (!preg_match('/^[a-z0-9_]{3,80}$/i', $username)) {
    $error = 'Username may only contain letters, numbers, and underscores (380 characters).';
  } else {
    $db = getDB();
    // Check username uniqueness
    $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $chk->execute([$username]);
    if ((int) $chk->fetchColumn() > 0) {
      $error = "Username \"$username\" is already taken. Please choose another.";
    } else {
      // Insert pending user - is_active=0, is_pending=1, default role
      $hash = password_hash($password, PASSWORD_BCRYPT);
      try {
        $db->prepare("INSERT INTO users (username, password, full_name, role, is_active, is_pending)
                              VALUES (?, ?, ?, 'Pending', 0, 1)")
          ->execute([$username, $hash, $full_name]);
        $newId = (int) $db->lastInsertId();
        // Log to audit (no active session, use system user_id=0 workaround via direct insert)
        $db->prepare("INSERT INTO audit_log (user_id, action, target_type, target_id, detail)
                              VALUES (?, 'user.register', 'user', ?, ?)")
          ->execute([$newId, $newId, "Self-registration by: $username"]);
        $success = true;
      } catch (Exception $e) {
        $error = 'Registration failed. Please try again.';
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1.0" />
  <title>Create Account - StockBoard</title>
  <meta name="description" content="Create a new StockBoard account. Requires administrator approval." />
  <link rel="stylesheet" href="css/style.css?v=5" />
</head>

<body>
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-logo">
        <img src="uploads/logo.jpg" alt="StockBoard Logo"
          style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border); margin-bottom: 0.8rem; box-shadow: 0 4px 6px rgba(0,0,0,0.05);" />
        <div class="company-name">StockBoard</div>
        <div class="system-name">For MCC Mae Anne Cunanan Laminates</div>
      </div>

      <!-- Tab switcher -->
      <div style="display:flex;gap:0;margin-bottom:1.4rem;border-bottom:1px solid var(--border);">
        <a href="login.php"
          style="flex:1;text-align:center;padding:.6rem 0;font-size:.88rem;color:var(--text-muted);text-decoration:none;border-bottom:2px solid transparent;">Sign
          In</a>
        <span
          style="flex:1;text-align:center;padding:.6rem 0;font-size:.88rem;font-weight:600;color:var(--accent);border-bottom:2px solid var(--accent);cursor:default;">Create
          Account</span>
      </div>

      <?php if ($success): ?>
        <div class="flash flash-ok" style="margin-bottom:1rem;">
          Account created! A manager will review and activate your account before you can sign in.
        </div>
        <div style="text-align:center;margin-top:.5rem;">
          <a href="login.php" class="btn btn-primary" style="display:inline-block;width:100%;">Back to Sign In</a>
        </div>
      <?php else: ?>

        <?php if ($error): ?>
          <div class="flash flash-err" style="margin-bottom:.75rem;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="register.php" autocomplete="off">
          <label for="full_name">Full Name *</label>
          <input id="full_name" name="full_name" type="text" class="form-control" placeholder="e.g. Juan Dela Cruz"
            value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required autofocus autocomplete="name" />

          <label for="username" style="margin-top:.75rem;">Username *</label>
          <input id="username" name="username" type="text" class="form-control"
            placeholder="Letters, numbers, underscores only" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
            required autocomplete="username" />

          <label for="password" style="margin-top:.75rem;">Password *</label>
          <div style="position:relative;margin-bottom:.6rem;">
            <input id="password" name="password" type="password" class="form-control" placeholder="Min 6 characters"
              required autocomplete="new-password" style="padding-right:40px;margin-bottom:0;" />
            <span id="togglePw"
              style="position:absolute;right:12px;top:50%;transform:translateY(-50%);cursor:pointer;user-select:none;opacity:0.6;display:flex;align-items:center;color:var(--text-muted);">
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

          <label for="confirm" style="margin-top:.6rem;">Confirm Password *</label>
          <input id="confirm" name="confirm" type="password" class="form-control" placeholder="Re-enter password" required
            autocomplete="new-password" />

          <div style="margin-top:.4rem;margin-bottom:.9rem;">
            <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:.4rem;">
              Your account will be reviewed by a manager before activation.
              A manager will assign your role based on your position.
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-full">Create Account</button>
        </form>

      <?php endif; ?>

    </div>
  </div>

  <script>
    const togglePw = document.getElementById('togglePw');
    if (togglePw) {
      togglePw.addEventListener('click', function () {
        const pw = document.getElementById('password');
        const iconClosed = document.getElementById('iconClosed');
        const iconOpen = document.getElementById('iconOpen');
        if (pw.type === 'password') {
          pw.type = 'text'; iconClosed.style.display = 'none'; iconOpen.style.display = 'block'; this.style.opacity = '1';
        } else {
          pw.type = 'password'; iconClosed.style.display = 'block'; iconOpen.style.display = 'none'; this.style.opacity = '0.6';
        }
      });
    }
  </script>
</body>

</html>