?<?php
/**
 * commissions.php ?" Agent Commission Dashboard
 * Visible to Manager and Administrator only.
 *
 * - Shows total unpaid commission per OnlineAgent
 * - Admin can view item-level breakdown (modal)
 * - Admin can mark commission as paid/cleared (resets counter)
 * - Admin can filter by date range
 * - Per-product agent_price is editable inline via modal ?' saves to products table
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
requireCap('canViewCommissions');

$db = getDB();
$user = currentUser();
$flash = null;

// "?"? Handle POST actions "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?

// POST: clear/pay agent's commission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_commission') {
  if (!canMarkCommissionPaid()) {
    $flash = ['type' => 'err', 'msg' => 'Access denied. Only the Administrator can authorize commission payouts.'];
  } else {
    $agentId = (int) ($_POST['agent_id'] ?? 0);
  $from = $_POST['from'] ?? date('Y-m-01');
  $to = $_POST['to'] ?? date('Y-m-d');
  $note = trim($_POST['note'] ?? '');

  // Compute total being cleared
  $totalStmt = $db->prepare("
        SELECT COALESCE(SUM((si.price_per_unit - p.agent_price) * si.quantity), 0)
        FROM sale_items si
        JOIN sales s    ON s.id = si.sale_id
        JOIN products p ON p.id = si.product_id
        WHERE s.user_id = ?
          AND s.status = 'Valid'
          AND si.commission_cleared = 0
          AND s.sale_date BETWEEN ? AND ?
    ");
  $totalStmt->execute([$agentId, $from, $to]);
  $cleared = (float) $totalStmt->fetchColumn();

  // Mark items as cleared
  $db->prepare("
        UPDATE sale_items si
        JOIN sales s ON s.id = si.sale_id
        SET si.commission_cleared = 1
        WHERE s.user_id = ?
          AND s.status = 'Valid'
          AND si.commission_cleared = 0
          AND s.sale_date BETWEEN ? AND ?
    ")->execute([$agentId, $from, $to]);

  // Log payout
  $db->prepare("
        INSERT INTO agent_commission_payouts (agent_id, cleared_by, amount, note)
        VALUES (?, ?, ?, ?)
    ")->execute([$agentId, $user['id'], $cleared, $note ?: 'Commission payout']);

  logAudit(
    $db,
    'commission.clear',
    'user',
    $agentId,
    "Cleared commission PHP " . number_format($cleared, 2) . " for agent #$agentId"
  );

    $flash = ['type' => 'ok', 'msg' => "Commission of PHP " . number_format($cleared, 2) . " marked as paid and cleared."];
  }
}

// POST: delete a single payout log entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_payout') {
  $pid = (int) ($_POST['payout_id'] ?? 0);
  $db->prepare("DELETE FROM agent_commission_payouts WHERE id=?")->execute([$pid]);
  logAudit($db, 'commission.payout_delete', 'commission_payout', $pid, "Deleted payout record #$pid");
  $flash = ['type' => 'ok', 'msg' => 'Payout record deleted.'];
}

// POST: update agent_price for a product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_agent_price') {
  $pid = (int) ($_POST['product_id'] ?? 0);
  $price = round((float) ($_POST['agent_price'] ?? 0), 2);
  $db->prepare("UPDATE products SET agent_price=? WHERE id=?")->execute([$price, $pid]);
  logAudit($db, 'product.agent_price', 'product', $pid, "Set agent_price to PHP $price");
  $flash = ['type' => 'ok', 'msg' => 'Agent price updated.'];
}

// "?"? Filters "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

// "?"? Summary: unpaid commission per agent "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
$agentSummary = $db->prepare("
    SELECT
        u.id,
        u.full_name,
        u.username,
        u.role,
        COUNT(DISTINCT s.id)                                              AS sale_count,
        COALESCE(SUM(si.total), 0)                                        AS total_sold,
        COALESCE(SUM((si.price_per_unit - p.agent_price) * si.quantity), 0) AS total_commission
    FROM users u
    LEFT JOIN sales s    ON s.user_id = u.id AND s.status = 'Valid'
                         AND s.sale_date BETWEEN :from AND :to
    LEFT JOIN sale_items si ON si.sale_id = s.id AND si.commission_cleared = 0
    LEFT JOIN products p    ON p.id = si.product_id
    WHERE u.is_active = 1 AND (u.is_deleted IS NULL OR u.is_deleted = 0)
    GROUP BY u.id, u.full_name, u.username, u.role
    ORDER BY total_commission DESC
");
$agentSummary->execute([':from' => $from, ':to' => $to]);
$agents = $agentSummary->fetchAll();

// "?"? Grand total unpaid "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
$grandTotal = array_sum(array_column($agents, 'total_commission'));

// "?"? Products for agent price editor "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
$products = $db->query("
    SELECT p.id, p.board_type, c.name AS category, p.selling_price, p.agent_price
    FROM products p
    JOIN categories c ON c.id = p.category_id
    WHERE p.is_active = 1
    ORDER BY p.board_type
")->fetchAll();

// "?"? Recent payouts "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
$payouts = $db->query("
    SELECT acp.*, u.full_name AS agent_name, cb.full_name AS cleared_by_name
    FROM agent_commission_payouts acp
    JOIN users u  ON u.id  = acp.agent_id
    JOIN users cb ON cb.id = acp.cleared_by
    ORDER BY acp.cleared_at DESC
    LIMIT 50
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1.0" />
  <title>Agent Commissions - StockBoard</title>
  <link rel="stylesheet" href="css/style.css?v=5" />
  <style>
    /* "?"? Commission-specific styles "?"? */
    .comm-hero {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem;
      margin-bottom: 1.5rem;
    }

    .comm-hero-card {
      background: linear-gradient(135deg, var(--card) 0%, var(--surface) 100%);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 1.25rem 1.4rem;
      display: flex;
      flex-direction: column;
      gap: .3rem;
    }

    .comm-hero-card.accent {
      border-color: rgba(105,189,49,.4);
      background: linear-gradient(135deg, rgba(105,189,49,.12), var(--card));
    }

    .comm-hero-card.gold {
      border-color: rgba(245, 158, 11, .4);
      background: linear-gradient(135deg, rgba(245, 158, 11, .10), var(--card));
    }

    .comm-val {
      font-size: 1.8rem;
      font-weight: 800;
      line-height: 1;
    }

    .comm-val.green {
      color: #16a34a;
    }

    .comm-val.amber {
      color: #b45309;
    }

    .comm-label {
      font-size: .72rem;
      text-transform: uppercase;
      letter-spacing: .08em;
      color: var(--text-muted);
      font-weight: 600;
    }

    .tab-row {
      display: flex;
      gap: .4rem;
      margin-bottom: 1rem;
      flex-wrap: wrap;
    }

    .tab-btn {
      padding: .38rem .9rem;
      border: 1px solid var(--border);
      border-radius: 6px;
      background: transparent;
      color: var(--text-muted);
      font-size: .83rem;
      cursor: pointer;
      font-family: inherit;
    }

    .tab-btn.active {
      background: var(--accent);
      border-color: var(--accent);
      color: #fff;
    }

    .agent-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 1.2rem 1.4rem;
      margin-bottom: 1rem;
      transition: border-color .15s;
    }

    .agent-card:hover {
      border-color: rgba(59, 130, 246, .4);
    }

    .agent-card-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: .75rem;
    }

    .agent-avatar {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      background: linear-gradient(135deg, #1d4ed8, #1e40af);
      color: #fff;
      font-size: 1.1rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .agent-info {
      flex: 1;
      min-width: 0;
    }

    .agent-name {
      font-size: 1rem;
      font-weight: 700;
    }

    .agent-meta {
      font-size: .75rem;
      color: var(--text-muted);
    }

    .agent-stats {
      display: flex;
      gap: 1.5rem;
      flex-wrap: wrap;
      align-items: center;
    }

    .agent-stat {
      text-align: center;
    }

    .agent-stat-val {
      font-size: 1.1rem;
      font-weight: 700;
    }

    .agent-stat-lbl {
      font-size: .65rem;
      color: var(--text-muted);
      text-transform: uppercase;
    }

    .agent-actions {
      display: flex;
      gap: .4rem;
      flex-wrap: wrap;
      align-items: center;
    }

    .commission-positive {
      color: #16a34a;
    }

    .commission-zero {
      color: var(--text-muted);
    }

    .commission-negative {
      color: #dc2626;
    }

    .breakdown-table th {
      background: var(--surface);
    }

    .breakdown-table td,
    .breakdown-table th {
      padding: .5rem .8rem;
      font-size: .84rem;
    }

    .price-editor-grid {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr 1fr auto;
      gap: .5rem;
      align-items: center;
      font-size: .85rem;
    }

    .payout-history-row td {
      font-size: .82rem;
    }

    /* "?"? Toast "?"? */
    .ajax-toast {
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
      transition: opacity .25s ease, transform .25s ease;
      max-width: 340px;
      box-shadow: 0 6px 24px rgba(0, 0, 0, .45);
    }

    .ajax-toast.ok {
      background: #d1fae5;
      color: #065f46;
      border: 1px solid #6ee7b7;
    }

    .ajax-toast.err {
      background: #fee2e2;
      color: #7f1d1d;
      border: 1px solid #fca5a5;
    }
  </style>
</head>

<body>
  <div class="layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <div class="topbar">
        <div>
          <div class="topbar-title">Agent Commissions</div>
          <div class="topbar-sub">Track and manage online agent commission earnings</div>
        </div>
      </div>
      <div class="page-body">

        <?php if ($flash): ?>
          <div class="flash flash-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
        <?php endif; ?>

        <!-- Hero Stats -->
        <div class="comm-hero">
          <div class="comm-hero-card gold">
            <div class="comm-label">Total Unpaid Commission</div>
            <div class="comm-val amber">PHP <?= number_format($grandTotal, 2) ?></div>
            <div style="font-size:.75rem; color:var(--text-muted); margin-top:.2rem;">Period:
              <?= htmlspecialchars($from) ?> &rarr; <?= htmlspecialchars($to) ?></div>
          </div>
          <div class="comm-hero-card accent">
            <div class="comm-label">Staff with Sales</div>
            <div class="comm-val"><?= count($agents) ?></div>
            <div style="font-size:.75rem; color:var(--text-muted); margin-top:.2rem;">All active roles</div>
          </div>
          <div class="comm-hero-card">
            <div class="comm-label">Payouts Logged</div>
            <div class="comm-val"><?= count($payouts) ?></div>
            <div style="font-size:.75rem; color:var(--text-muted); margin-top:.2rem;">All-time payout records</div>
          </div>
        </div>

        <!-- Date Filter -->
        <form method="get" action="commissions.php" class="toolbar mb-2">
          <div class="toolbar-left">
            <label class="muted" style="font-size:.78rem;">From</label>
            <input type="date" name="from" class="form-control" style="width:150px;"
              value="<?= htmlspecialchars($from) ?>" />
            <label class="muted" style="font-size:.78rem;">To</label>
            <input type="date" name="to" class="form-control" style="width:150px;"
              value="<?= htmlspecialchars($to) ?>" />
            <button type="submit" class="btn btn-ghost">Filter</button>
          </div>
        </form>

        <!-- Tab Navigation -->
        <div class="tab-row">
          <button class="tab-btn active" onclick="showTab('tab-agents', this)">Agent Commissions</button>
          <button class="tab-btn" onclick="showTab('tab-prices', this)">Agent Price Editor</button>
          <button class="tab-btn" onclick="showTab('tab-history', this)">Payout History</button>
        </div>

        <!-- "?"? TAB: Agent Commissions "?"? -->
        <div id="tab-agents">
          <?php if (empty($agents)): ?>
            <div class="card" style="text-align:center; padding:3rem; color:var(--text-muted);">
              No online agents found. Create users with the <strong>OnlineAgent</strong> role to track commissions.
            </div>
          <?php else: ?>
            <?php foreach ($agents as $ag):
              $comm = (float) $ag['total_commission'];
              $commClass = $comm > 0 ? 'commission-positive' : ($comm < 0 ? 'commission-negative' : 'commission-zero');
              ?>
              <div class="agent-card">
                <div class="agent-card-header">
                  <div style="display:flex; align-items:center; gap:.9rem;">
                    <div class="agent-avatar"><?= strtoupper(substr($ag['full_name'], 0, 1)) ?></div>
                    <div class="agent-info">
                      <div class="agent-name"><?= htmlspecialchars($ag['full_name']) ?></div>
                      <div class="agent-meta">@<?= htmlspecialchars($ag['username']) ?>  <span
                          style="color:var(--accent-lt);"><?= htmlspecialchars($ag['role']) ?></span> 
                        <?= $ag['sale_count'] ?> sale(s) this period</div>
                    </div>
                  </div>

                  <div class="agent-stats">
                    <div class="agent-stat">
                      <div class="agent-stat-val">PHP <?= number_format($ag['total_sold'], 2) ?></div>
                      <div class="agent-stat-lbl">Total Sold</div>
                    </div>
                    <div class="agent-stat">
                      <div class="agent-stat-val <?= $commClass ?>">PHP <?= number_format($comm, 2) ?></div>
                      <div class="agent-stat-lbl">Unpaid Commission</div>
                    </div>
                  </div>

                  <div class="agent-actions">
                    <button class="btn btn-ghost btn-sm"
                      onclick="loadBreakdown(<?= $ag['id'] ?>, '<?= htmlspecialchars($ag['full_name']) ?>', '<?= htmlspecialchars($from) ?>', '<?= htmlspecialchars($to) ?>')">
                      View Details
                    </button>
                    <?php if ($comm != 0 && canMarkCommissionPaid()): ?>
                      <button class="btn btn-success btn-sm"
                        onclick="openPayModal(<?= $ag['id'] ?>, '<?= addslashes($ag['full_name']) ?>', <?= $comm ?>)">
                        Mark as Paid
                      </button>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- "?"? TAB: Agent Price Editor "?"? -->
        <div id="tab-prices" style="display:none;">
          <div class="card">
            <div class="card-title">Product Agent Price Settings</div>
              Set the <strong>Agent Price</strong> for each product. Commission = (Selling Price &minus; Agent Price) &times;
              Quantity.
            </p>

            <div
              style="display:grid; grid-template-columns:2.5fr 1fr 1fr 1.2fr auto; gap:.5rem; padding:.5rem .9rem; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); border-bottom:1px solid var(--border);">
              <span>Product</span>
              <span>Category</span>
              <span>Selling Price</span>
              <span>Agent Price</span>
              <span></span>
            </div>

            <?php foreach ($products as $prod):
              $margin = $prod['selling_price'] - $prod['agent_price'];
              ?>
              <div
                style="display:grid; grid-template-columns:2.5fr 1fr 1fr 1.2fr auto; gap:.5rem; padding:.65rem .9rem; border-bottom:1px solid var(--border); align-items:center;">
                <div class="fw7" style="font-size:.88rem;"><?= htmlspecialchars($prod['board_type']) ?></div>
                <div class="muted" style="font-size:.8rem;"><?= htmlspecialchars($prod['category']) ?></div>
                <div style="font-size:.88rem;">PHP <?= number_format($prod['selling_price'], 2) ?></div>
                <div>
                  <input type="number" step="0.01" min="0" id="ap_<?= $prod['id'] ?>"
                    value="<?= number_format($prod['agent_price'], 2, '.', '') ?>" class="form-control"
                    style="padding:.3rem .5rem; font-size:.85rem;" />
                </div>
                <button type="button" class="btn btn-primary btn-sm" onclick="saveAgentPrice(<?= $prod['id'] ?>, this)">
                  Save
                </button>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- "?"? TAB: Payout History "?"? -->
        <div id="tab-history" style="display:none;">
          <div class="card">
            <div class="card-title">Payout History (Last 50)</div>
            <div class="tbl-wrap" style="margin-top:.5rem;">
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Agent</th>
                    <th>Amount Cleared</th>
                    <th>Note</th>
                    <th>Cleared By</th>
                    <th>Date</th>
                    <th>Receipt Confirmed</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($payouts)): ?>
                    <tr>
                      <td colspan="7" class="tc muted" style="padding:2rem;">No payout history yet.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($payouts as $p): ?>
                      <tr class="payout-history-row">
                        <td class="muted"><?= $p['id'] ?></td>
                        <td class="fw7"><?= htmlspecialchars($p['agent_name']) ?></td>
                        <td class="green fw7">PHP <?= number_format($p['amount'], 2) ?></td>
                        <td class="muted"><?= htmlspecialchars($p['note'] ?: '?"') ?></td>
                        <td><?= htmlspecialchars($p['cleared_by_name']) ?></td>
                        <td class="muted"><?= date('M d, Y H:i', strtotime($p['cleared_at'])) ?></td>
                        <td>
                          <?php if ($p['acknowledged_at']): ?>
                            <span style="color:#16a34a; font-size:.8rem; font-weight:600;">Confirmed</span>
                            <div style="font-size:.7rem; color:var(--text-muted);"><?= date('M d, Y H:i', strtotime($p['acknowledged_at'])) ?></div>
                          <?php else: ?>
                            <span style="color:var(--text-muted); font-size:.8rem;">Pending</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <form method="post" onsubmit="return confirm('Delete this payout record?')">
                            <input type="hidden" name="action" value="delete_payout" />
                            <input type="hidden" name="payout_id" value="<?= $p['id'] ?>" />
                            <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div><!-- /page-body -->
    </div><!-- /main -->
  </div><!-- /layout -->

  <!-- "?"? Mark-as-Paid Modal "?"? -->
  <div class="overlay" id="payModal">
    <div class="modal" style="max-width:420px;">
      <div class="modal-header">
        <div class="modal-title">Mark Commission as Paid</div>
        <button class="modal-close" onclick="closeModal('payModal')">X</button>
      </div>
      <form method="post" action="commissions.php">
        <input type="hidden" name="action" value="clear_commission" />
        <input type="hidden" name="agent_id" id="pay_agent_id" />
        <input type="hidden" name="from" value="<?= htmlspecialchars($from) ?>" />
        <input type="hidden" name="to" value="<?= htmlspecialchars($to) ?>" />

        <div id="pay_summary"
          style="background:rgba(22,163,74,.08); border:1px solid rgba(22,163,74,.25); border-radius:8px; padding:1rem; margin-bottom:1rem; font-size:.9rem;">
        </div>

        <div class="form-group">
          <label>Note (optional)</label>
          <input type="text" name="note" class="form-control" placeholder="e.g. Cash paid on April 22" />
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" onclick="closeModal('payModal')">Cancel</button>
          <button type="submit" class="btn btn-success">Confirm & Clear</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Breakdown Modal -->
  <div class="overlay" id="breakdownModal">
    <div class="modal" style="max-width:700px;">
      <div class="modal-header">
        <div class="modal-title">Commission Breakdown &mdash; <span id="bdAgentName"></span></div>
        <button class="modal-close" onclick="closeModal('breakdownModal')">X</button>
      </div>
      <div id="bdContent" style="min-height:120px; display:flex; align-items:center; justify-content:center;">
        <span class="muted">Loading...</span>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('breakdownModal')">Close</button>
      </div>
    </div>
  </div>

  <script>
    // Tab switching
    function showTab(id, btn) {
      document.querySelectorAll('[id^="tab-"]').forEach(t => t.style.display = 'none');
      document.getElementById(id).style.display = '';
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
    }

    // "?"? Toast notification "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
    function showToast(msg, type = 'ok') {
      const el = document.getElementById('ajaxToast');
      el.textContent = msg;
      el.className = 'ajax-toast ' + type;
      el.style.opacity = '1';
      el.style.transform = 'translateY(0)';
      clearTimeout(el._timer);
      el._timer = setTimeout(() => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(12px)';
      }, 2800);
    }

    // "?"? Save agent price via AJAX (stays on page) "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
    function saveAgentPrice(productId, btn) {
      const input = document.getElementById('ap_' + productId);
      const price = parseFloat(input.value);
      if (isNaN(price) || price < 0) { showToast('Enter a valid price.', 'err'); return; }

      const origText = btn.textContent;
      btn.textContent = '?';
      btn.disabled = true;

      const body = new URLSearchParams();
      body.append('action', 'update_agent_price');
      body.append('product_id', productId);
      body.append('agent_price', price.toFixed(2));

      fetch('commissions.php', { method: 'POST', body })
        .then(r => r.text())
        .then(() => {
          btn.textContent = 'Saved';
          btn.style.background = 'var(--success)';
          showToast('Agent price updated to PHP ' + price.toLocaleString('en-PH', { minimumFractionDigits: 2 }));
          setTimeout(() => {
            btn.textContent = origText;
            btn.style.background = '';
            btn.disabled = false;
          }, 1800);
        })
        .catch(() => {
          btn.textContent = origText;
          btn.disabled = false;
          showToast('Save failed. Please try again.', 'err');
        });
    }

    function closeModal(id) { document.getElementById(id).classList.remove('open'); }
    document.querySelectorAll('.overlay').forEach(el =>
      el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); }));

    // Open pay modal
    function openPayModal(agentId, agentName, amount) {
      document.getElementById('pay_agent_id').value = agentId;
      document.getElementById('pay_summary').innerHTML =
        `Marking <strong>${agentName}</strong>'s unpaid commission of ` +
        `<span style="color:#16a34a; font-weight:700;">PHP ${amount.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</span> ` +
        `as paid.<br/><span style="font-size:.8rem; color:var(--text-muted);">This will clear all unpaid items in the selected date range.</span>`;
      document.getElementById('payModal').classList.add('open');
    }

    // Load per-agent breakdown via AJAX
    function loadBreakdown(agentId, agentName, from, to) {
      document.getElementById('bdAgentName').textContent = agentName;
      document.getElementById('bdContent').innerHTML = '<span class="muted">Loading?</span>';
      document.getElementById('breakdownModal').classList.add('open');

      fetch(`api/commission_breakdown.php?agent_id=${agentId}&from=${from}&to=${to}`)
        .then(r => r.json())
        .then(data => {
          if (!data.items || data.items.length === 0) {
            document.getElementById('bdContent').innerHTML =
              '<p class="muted tc" style="padding:2rem;">No unpaid commission items found for this period.</p>';
            return;
          }
          let rows = data.items.map(i => {
            const comm = (i.price_per_unit - i.agent_price) * i.quantity;
            const commColor = comm > 0 ? '#16a34a' : (comm < 0 ? '#dc2626' : '#64748b');
            return `<tr>
            <td class="muted" style="font-size:.77rem;">${i.invoice_no}</td>
            <td>${i.board_type}</td>
            <td class="tr">${i.quantity}</td>
            <td class="tr">PHP ${parseFloat(i.agent_price).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
            <td class="tr">PHP ${parseFloat(i.price_per_unit).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
            <td class="tr fw7" style="color:${commColor}">PHP ${comm.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
            <td class="muted" style="font-size:.77rem;">${i.sale_date}</td>
          </tr>`;
          }).join('');

          const total = data.items.reduce((s, i) => s + (i.price_per_unit - i.agent_price) * i.quantity, 0);

          document.getElementById('bdContent').innerHTML = `
          <div class="tbl-wrap">
            <table class="breakdown-table">
              <thead>
                <tr>
                  <th>Invoice</th><th>Product</th><th>Qty</th>
                  <th class="tr">Agent Price</th><th class="tr">Sold At</th>
                  <th class="tr">Commission</th><th>Date</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
              <tfoot>
                <tr style="font-weight:700;">
                  <td colspan="5" class="tr" style="padding:.7rem .9rem; border-top:2px solid var(--border);">Total Unpaid Commission:</td>
                  <td class="tr" style="padding:.7rem .9rem; border-top:2px solid var(--border); color:#16a34a;">
                    PHP ${total.toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                  </td>
                  <td style="border-top:2px solid var(--border);"></td>
                </tr>
              </tfoot>
            </table>
          </div>`;
        })
        .catch(() => {
          document.getElementById('bdContent').innerHTML =
            '<p class="muted tc" style="padding:2rem; color:#dc2626;">Failed to load breakdown. Please try again.</p>';
        });
    }
  </script>
  <div id="ajaxToast" class="ajax-toast ok"></div>
</body>

</html>
