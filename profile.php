?<?php
/**
 * profile.php  User profile and password reset endpoint.
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
requireLogin();

$user = currentUser();
$db = getDB();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'upload_avatar') {
    $stmt = $db->prepare("SELECT COUNT(*) FROM audit_log WHERE user_id=? AND action='user.avatar_update' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stmt->execute([$user['id']]);
    $changesIn7Days = $stmt->fetchColumn();

    if ($changesIn7Days >= 2) {
      $flash = ['type' => 'err', 'msg' => 'You can only change your profile picture twice in 7 days.'];
    } elseif (!empty($_POST['cropped_image'])) {
      $data = $_POST['cropped_image'];
      list($type, $data) = explode(';', $data);
      list(, $data) = explode(',', $data);
      $data = base64_decode($data);

      $ext = str_replace('data:image/', '', $type);
      if ($ext === '')
        $ext = 'jpg'; // fallback
      $ext = $ext === 'jpeg' ? 'jpg' : $ext;

      $allowed = ['jpg', 'png', 'gif', 'webp'];
      if (in_array($ext, $allowed)) {
        $uploadDir = __DIR__ . '/uploads/avatars/';
        if (!is_dir($uploadDir)) {
          mkdir($uploadDir, 0777, true);
        }
        $newName = 'avatar_' . $user['id'] . '_' . time() . '.' . $ext;
        $newAvatarPath = 'uploads/avatars/' . $newName;

        if (file_put_contents($uploadDir . $newName, $data)) {
          // Delete old avatar if it exists
          if (!empty($user['avatar'])) {
            $oldFile = __DIR__ . '/' . $user['avatar'];
            if (file_exists($oldFile)) {
              unlink($oldFile);
            }
          }

          $db->prepare('UPDATE users SET avatar=? WHERE id=?')->execute([$newAvatarPath, $user['id']]);
          logAudit($db, 'user.avatar_update', 'user', $user['id'], 'User updated their profile picture');
          $_SESSION['avatar'] = $newAvatarPath;
          $user['avatar'] = $newAvatarPath;
          $flash = ['type' => 'ok', 'msg' => 'Profile picture cropped and updated successfully.'];
        } else {
          $flash = ['type' => 'err', 'msg' => 'Failed to save cropped image file.'];
        }
      } else {
        $flash = ['type' => 'err', 'msg' => 'Invalid file type. Only JPG, PNG, GIF, WEBP allowed.'];
      }
    } else {
      $flash = ['type' => 'err', 'msg' => 'Please select a valid image to upload.'];
    }
  } else {
    $newUsername = trim($_POST['username'] ?? '');
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$current) {
      $flash = ['type' => 'err', 'msg' => 'Current password is required to save changes.'];
    } elseif (!$newUsername) {
      $flash = ['type' => 'err', 'msg' => 'Username cannot be empty.'];
    } else {
      $stmt = $db->prepare('SELECT password FROM users WHERE id=?');
      $stmt->execute([$user['id']]);
      $hash = $stmt->fetchColumn();

      if (password_verify($current, $hash)) {
        $stmtU = $db->prepare('SELECT id FROM users WHERE username=? AND id!=?');
        $stmtU->execute([$newUsername, $user['id']]);
        if ($stmtU->fetchColumn()) {
          $flash = ['type' => 'err', 'msg' => 'Username is already taken.'];
        } else {
          if ($new !== '') {
            if ($new !== $confirm) {
              $flash = ['type' => 'err', 'msg' => 'New passwords do not match.'];
            } elseif (strlen($new) < 6) {
              $flash = ['type' => 'err', 'msg' => 'New password must be at least 6 characters.'];
            } else {
              $newHash = password_hash($new, PASSWORD_BCRYPT);
              $db->prepare('UPDATE users SET username=?, password=? WHERE id=?')->execute([$newUsername, $newHash, $user['id']]);
              logAudit($db, 'user.profile_update', 'user', $user['id'], 'User updated profile and changed password');
              $_SESSION['username'] = $newUsername;
              $user['username'] = $newUsername;
              $flash = ['type' => 'ok', 'msg' => 'Profile and password updated successfully.'];
            }
          } else {
            $db->prepare('UPDATE users SET username=? WHERE id=?')->execute([$newUsername, $user['id']]);
            logAudit($db, 'user.profile_update', 'user', $user['id'], 'User updated username');
            $_SESSION['username'] = $newUsername;
            $user['username'] = $newUsername;
            $flash = ['type' => 'ok', 'msg' => 'Profile updated successfully.'];
          }
        }
      } else {
        $flash = ['type' => 'err', 'msg' => 'Incorrect current password.'];
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
  <title>My Profile - StockBoard</title>
  <link rel="stylesheet" href="css/style.css?v=5" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" />
  <style>
    /* "?"? Commission section on profile "?"? */
    .comm-section {
      margin-top: 1.5rem;
      max-width: 700px;
      margin-left: auto;
      margin-right: auto;
    }

    .comm-banner {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 1rem;
      background: linear-gradient(135deg, rgba(37, 99, 235, .14), rgba(16, 185, 129, .08));
      border: 1px solid rgba(37, 99, 235, .3);
      border-radius: 14px;
      padding: 1.25rem 1.5rem;
      margin-bottom: 1rem;
    }

    .comm-banner-left {
      display: flex;
      flex-direction: column;
      gap: .25rem;
    }

    .comm-banner-label {
      font-size: .72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .08em;
      color: var(--text-muted);
    }

    .comm-banner-amount {
      font-size: 2rem;
      font-weight: 800;
      color: #16a34a;
      line-height: 1.1;
    }

    .comm-banner-amount.zero {
      color: var(--text-muted);
      font-size: 1.5rem;
    }

    .comm-banner-sub {
      font-size: .78rem;
      color: var(--text-muted);
      margin-top: .15rem;
    }

    .comm-toggle-btn {
      background: rgba(255, 255, 255, .06);
      border: 1px solid var(--border);
      color: var(--text);
      border-radius: 8px;
      padding: .45rem 1rem;
      font-size: .83rem;
      cursor: pointer;
      font-family: inherit;
      transition: background .15s;
    }

    .comm-toggle-btn:hover {
      background: rgba(255, 255, 255, .12);
    }

    .comm-breakdown-wrap {
      display: none;
      margin-bottom: 1rem;
    }

    .comm-breakdown-wrap.open {
      display: block;
    }

    .comm-breakdown-table th {
      background: var(--surface);
    }

    .comm-breakdown-table td,
    .comm-breakdown-table th {
      padding: .45rem .75rem;
      font-size: .82rem;
    }

    .payout-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 1rem 1.2rem;
      margin-bottom: .65rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: .75rem;
      transition: border-color .15s;
    }

    .payout-card.confirmed {
      border-color: rgba(74, 222, 128, .3);
    }

    .payout-card-left {
      display: flex;
      flex-direction: column;
      gap: .2rem;
    }

    .payout-amount {
      font-size: 1.15rem;
      font-weight: 800;
      color: #16a34a;
    }

    .payout-meta {
      font-size: .75rem;
      color: var(--text-muted);
    }

    .payout-note {
      font-size: .8rem;
      color: var(--text);
      margin-top: .1rem;
    }

    .payout-badge {
      padding: .3rem .75rem;
      border-radius: 20px;
      font-size: .75rem;
      font-weight: 700;
      letter-spacing: .03em;
    }

    .payout-badge.confirmed {
      background: rgba(74, 222, 128, .15);
      color: #16a34a;
      border: 1px solid rgba(74, 222, 128, .3);
    }

    .payout-badge.pending {
      background: rgba(251, 191, 36, .1);
      color: #b45309;
      border: 1px solid rgba(251, 191, 36, .3);
    }

    .confirm-receipt-btn {
      padding: .38rem .9rem;
      border-radius: 7px;
      font-size: .8rem;
      font-weight: 600;
      border: 1px solid rgba(74, 222, 128, .4);
      background: rgba(74, 222, 128, .1);
      color: #16a34a;
      cursor: pointer;
      font-family: inherit;
      transition: background .15s;
    }

    .confirm-receipt-btn:hover {
      background: rgba(74, 222, 128, .2);
    }

    .confirm-receipt-btn:disabled {
      opacity: .5;
      cursor: default;
    }

    .comm-empty {
      text-align: center;
      padding: 2rem;
      color: var(--text-muted);
      font-size: .88rem;
    }

    /* Toast */
    .profile-toast {
      position: fixed;
      bottom: 1.5rem;
      right: 1.5rem;
      padding: .65rem 1.1rem;
      border-radius: 8px;
      font-size: .875rem;
      font-weight: 500;
      z-index: 9999;
      pointer-events: none;
      opacity: 0;
      transform: translateY(12px);
      transition: opacity .25s, transform .25s;
      max-width: 320px;
      box-shadow: 0 6px 24px rgba(0, 0, 0, .45);
    }

    .profile-toast.ok {
      background: #166534;
      color: #bbf7d0;
      border: 1px solid #16a34a;
    }

    .profile-toast.err {
      background: #7f1d1d;
      color: #fecaca;
      border: 1px solid #dc2626;
    }
  </style>
</head>

<body>
  <div class="layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <div class="topbar">
        <div>
          <div class="topbar-title">My Profile</div>
          <div class="topbar-sub">Manage your account settings</div>
        </div>
      </div>
      <div class="page-body">
        <?php if ($flash): ?>
          <div class="flash flash-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
        <?php endif; ?>

        <div class="card" style="max-width:500px;margin:0 auto 1.5rem;">
          <div class="card-title">Profile Picture</div>
          <div style="text-align:center; margin-bottom: 1rem;">
            <?php if (!empty($user['avatar'])): ?>
              <img src="<?= BASE_URL . htmlspecialchars($user['avatar']) ?>" alt="Avatar"
                style="width:100px;height:100px;border-radius:50%;object-fit:cover;border:2px solid var(--border);">
            <?php else: ?>
              <div
                style="width:100px;height:100px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:2.5rem;font-weight:bold;margin:0 auto;">
                <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
              </div>
            <?php endif; ?>
          </div>
          <form method="post" id="avatarForm">
            <input type="hidden" name="action" value="upload_avatar">
            <input type="hidden" name="cropped_image" id="croppedImageData">
            <div class="form-group" style="text-align:center;">
              <input type="file" id="avatarInput" accept="image/png, image/jpeg, image/gif, image/webp" required
                style="display:inline-block; font-size:.85rem; color:var(--text);" />
            </div>
            <button type="submit" class="btn btn-primary btn-full hidden" style="display:none;">Upload New
              Picture</button>
            <div style="text-align:center; font-size:.7rem; color:var(--text-muted); margin-top:.1rem;">Max 2 changes
              per 7 days. Images cropped automatically to 1:1.</div>
          </form>
        </div>

        <div class="card" style="max-width:500px;margin:0 auto;">
          <div class="card-title">Edit Profile & Password</div>
          <form method="post">
            <div class="form-group">
              <label>Username</label>
              <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>"
                required />
            </div>
            <div class="form-group">
              <label>Current Password (required to save changes)</label>
              <input type="password" name="current_password" class="form-control" required />
            </div>
            <div class="form-group">
              <label>New Password (leave blank to keep current)</label>
              <input type="password" name="new_password" class="form-control" placeholder="Min 6 chars" />
            </div>
            <div class="form-group">
              <label>Confirm New Password</label>
              <input type="password" name="confirm_password" class="form-control" />
            </div>
            <button type="submit" class="btn btn-primary btn-full">Save Changes</button>
          </form>
        </div>

        <!-- "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"? -->
        <!-- My Commission Section                               -->
        <!-- "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"? -->
        <?php if (canReceiveCommission()): ?>
        <div class="comm-section" id="commSection">
          <div class="card-title"
            style="max-width:700px;margin:0 auto .75rem; font-size:.78rem; text-transform:uppercase; letter-spacing:.08em; color:var(--text-muted); font-weight:700;">
            My Commission</div>

          <!-- Banner: unpaid total -->
          <div class="comm-banner" id="commBanner">
            <div class="comm-banner-left">
              <div class="comm-banner-label">Current Unpaid Commission</div>
              <div class="comm-banner-amount" id="commAmount">Loading?</div>
              <div class="comm-banner-sub" id="commSub"></div>
            </div>
            <button class="comm-toggle-btn" id="commToggleBtn" onclick="toggleBreakdown()" style="display:none;">View
              Breakdown</button>
          </div>

          <!-- Unpaid breakdown (toggled) -->
          <div class="comm-breakdown-wrap" id="commBreakdownWrap">
            <div class="card" style="padding:.5rem;">
              <div
                style="font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted); padding:.5rem .75rem .5rem;">
                Unpaid Sale Breakdown</div>
              <div class="tbl-wrap">
                <table class="comm-breakdown-table">
                  <thead>
                    <tr>
                      <th>Invoice</th>
                      <th>Product</th>
                      <th>Qty</th>
                      <th class="tr">Agent Price</th>
                      <th class="tr">Sold At</th>
                      <th class="tr">Commission</th>
                      <th>Date</th>
                    </tr>
                  </thead>
                  <tbody id="commBreakdownBody"></tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Payout history -->
          <div id="commPayoutsWrap">
            <div
              style="font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted); margin-bottom:.5rem; padding-left:.1rem;">
              Payout History</div>
            <div id="commPayoutsList">
              <p class="comm-empty">Loading...</p>
            </div>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- Cropper Modal -->
  <div class="overlay" id="cropModal">
    <div class="modal" style="max-width:500px;text-align:center;">
      <div class="modal-header">
        <div class="modal-title">Crop Profile Picture</div>
        <button type="button" class="modal-close" onclick="closeCropModal()">X</button>
      </div>
      <div style="max-height: 400px; margin-bottom:1rem; overflow:hidden;">
        <img id="imageToCrop" style="max-width:100%; display:block;" />
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeCropModal()">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="applyCrop()">Apply Crop</button>
      </div>
    </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
  <script>
    let cropper;
    const avatarInput = document.getElementById('avatarInput');
    const cropModal = document.getElementById('cropModal');
    const imageToCrop = document.getElementById('imageToCrop');
    const form = document.getElementById('avatarForm');

    avatarInput.addEventListener('change', function (e) {
      const files = e.target.files;
      // Clear any existing flash messages to not confuse UI state
      document.querySelectorAll('.flash').forEach(f => f.remove());

      if (files && files.length > 0) {
        const file = files[0];
        const reader = new FileReader();
        reader.onload = function (evt) {
          imageToCrop.src = evt.target.result;
          cropModal.classList.add('open');
          if (cropper) cropper.destroy();
          cropper = new Cropper(imageToCrop, {
            aspectRatio: 1, // 1:1 square crop
            viewMode: 1,
            autoCropArea: 1
          });
        };
        reader.readAsDataURL(file);
      }
    });

    function closeCropModal() {
      cropModal.classList.remove('open');
      if (cropper) { cropper.destroy(); cropper = null; }
      avatarInput.value = ''; // reset input
    }

    function applyCrop() {
      if (!cropper) return;
      const canvas = cropper.getCroppedCanvas({ width: 400, height: 400 });
      const dataUrl = canvas.toDataURL('image/jpeg', 0.8);
      document.getElementById('croppedImageData').value = dataUrl;
      closeCropModal();
      form.submit();
    }
  </script>

  <script>
    // "?"? Commission Section "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
    const fmt = (n) => parseFloat(n).toLocaleString('en-PH', { minimumFractionDigits: 2 });

    function showProfileToast(msg, type = 'ok') {
      const el = document.getElementById('profileToast');
      el.textContent = msg;
      el.className = 'profile-toast ' + type;
      el.style.opacity = '1'; el.style.transform = 'translateY(0)';
      clearTimeout(el._t);
      el._t = setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateY(12px)'; }, 3000);
    }

    function toggleBreakdown() {
      const wrap = document.getElementById('commBreakdownWrap');
      const btn = document.getElementById('commToggleBtn');
      const open = wrap.classList.toggle('open');
      btn.textContent = open ? 'Hide Breakdown' : 'View Breakdown';
    }

    function renderCommission(data) {
      const amtEl = document.getElementById('commAmount');
      const subEl = document.getElementById('commSub');
      const toggleBtn = document.getElementById('commToggleBtn');

      const total = parseFloat(data.unpaid_total);
      amtEl.textContent = 'PHP ' + fmt(total);
      amtEl.className = 'comm-banner-amount' + (total <= 0 ? ' zero' : '');

      if (data.unpaid_items && data.unpaid_items.length > 0) {
        subEl.textContent = `From ${data.unpaid_items.length} uncleared sale item(s)`;
        toggleBtn.style.display = '';

        const rows = data.unpaid_items.map(i => {
          const comm = (parseFloat(i.price_per_unit) - parseFloat(i.agent_price)) * parseInt(i.quantity);
          const color = comm > 0 ? '#16a34a' : (comm < 0 ? '#dc2626' : '#7d8590');
          return `<tr>
        <td class="muted" style="font-size:.77rem;">${i.invoice_no}</td>
        <td>${i.board_type}</td>
        <td class="tr">${i.quantity}</td>
        <td class="tr">PHP ${fmt(i.agent_price)}</td>
        <td class="tr">PHP ${fmt(i.price_per_unit)}</td>
        <td class="tr fw7" style="color:${color}">PHP ${fmt(comm)}</td>
        <td class="muted" style="font-size:.77rem;">${i.sale_date}</td>
      </tr>`;
        }).join('');
        document.getElementById('commBreakdownBody').innerHTML = rows;
      } else {
        subEl.textContent = 'No pending uncleared items.';
        document.getElementById('commBreakdownBody').innerHTML =
          '<tr><td colspan="7" class="comm-empty">No unpaid items found.</td></tr>';
      }

      // Payout history
      const listEl = document.getElementById('commPayoutsList');
      if (!data.payouts || data.payouts.length === 0) {
        listEl.innerHTML = '<p class="comm-empty">No payout history yet.</p>';
        return;
      }

      listEl.innerHTML = data.payouts.map(p => {
        const isConfirmed = !!p.acknowledged_at;
        const dateStr = new Date(p.cleared_at).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        const ackStr = isConfirmed
          ? new Date(p.acknowledged_at).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
          : null;

        const badge = isConfirmed
          ? `<span class="payout-badge confirmed">Receipt Confirmed</span>`
          : `<span class="payout-badge pending">Awaiting Confirmation</span>`;

        const confirmBtn = isConfirmed
          ? `<div style="font-size:.7rem; color:var(--text-muted); margin-top:.2rem;">Confirmed on ${ackStr}</div>`
          : `<button class="confirm-receipt-btn" id="confBtn_${p.id}" onclick="confirmReceipt(${p.id}, this)">Confirm Receipt</button>`;

        return `
      <div class="payout-card ${isConfirmed ? 'confirmed' : ''}" id="payout_${p.id}">
        <div class="payout-card-left">
          <div class="payout-amount">PHP ${fmt(p.amount)}</div>
          <div class="payout-meta">Paid by ${p.cleared_by_name}  ${dateStr}</div>
          ${p.note ? `<div class="payout-note">${p.note}</div>` : ''}
        </div>
        <div style="display:flex; flex-direction:column; align-items:flex-end; gap:.4rem;">
          ${badge}
          ${confirmBtn}
        </div>
      </div>`;
      }).join('');
    }

    function confirmReceipt(payoutId, btn) {
      btn.disabled = true;
      btn.textContent = 'Confirming?';

      const body = new URLSearchParams();
      body.append('payout_id', payoutId);

      fetch('api/confirm_commission.php', { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
          if (data.ok) {
            // Replace the button section with confirmed state
            const card = document.getElementById('payout_' + payoutId);
            if (card) {
              card.classList.add('confirmed');
              const badgeEl = card.querySelector('.payout-badge');
              if (badgeEl) { badgeEl.className = 'payout-badge confirmed'; badgeEl.textContent = 'Receipt Confirmed'; }
              btn.replaceWith((() => { const d = document.createElement('div'); d.style = 'font-size:.7rem;color:var(--text-muted);margin-top:.2rem;'; d.textContent = 'Confirmed just now'; return d; })());
            }
            showProfileToast('Receipt confirmed! Thank you.');
          } else {
            btn.disabled = false; btn.textContent = 'Confirm Receipt';
            showProfileToast(data.error || 'Failed to confirm. Try again.', 'err');
          }
        })
        .catch(() => {
          btn.disabled = false; btn.textContent = 'o" Confirm Receipt';
          showProfileToast('Network error. Please try again.', 'err');
        });
    }

    // Load commission data on page load
    <?php if (canReceiveCommission()): ?>
    fetch('api/my_commission.php')
      .then(r => r.json())
      .then(renderCommission)
      .catch(() => {
        document.getElementById('commAmount').textContent = 'Error loading';
        document.getElementById('commAmount').className = 'comm-banner-amount zero';
      });
    <?php endif; ?>
  </script>

  <div id="profileToast" class="profile-toast ok"></div>
</body>

</html>
