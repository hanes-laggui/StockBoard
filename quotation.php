?<?php
/**
 * quotation.php - Accessible by all roles that need to create quotations.
 * Generates quotations from inventory without affecting stock/db.
 * Allows downloading the quotation as an image using html2canvas.
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
requireCap('canCreateQuotation');

$db = getDB();
$user = currentUser();

// Fetch products for quotation
$products = $db->query("
    SELECT p.id, p.board_type, c.name AS category_name, p.color_design, p.unit, p.selling_price, p.current_stock
    FROM products p
    JOIN categories c ON c.id = p.category_id
    WHERE p.is_active = 1
    ORDER BY p.board_type")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1.0" />
  <title>Create Quotation - StockBoard</title>
  <link rel="stylesheet" href="css/style.css?v=5" />
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <style>
    .quote-preview {
      background: white;
      color: black;
      padding: 2rem;
      border-radius: 8px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
      border: 1px solid #ddd;
      margin-top: 1.5rem;
      max-width: 800px;
      margin-inline: auto;
    }

    .quote-header {
      display: flex;
      justify-content: space-between;
      margin-bottom: 2rem;
      align-items: flex-end;
    }

    .quote-brand h1 {
      margin: 0;
      font-size: 1.8rem;
      color: #111;
    }

    .quote-brand p {
      margin: 0;
      font-size: 0.9rem;
      color: #555;
    }

    .quote-details {
      text-align: right;
    }

    .quote-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 1.5rem;
    }

    .quote-table th {
      background: #f4f4f4;
      border-bottom: 2px solid #ccc;
      padding: 0.5rem;
      text-align: left;
      color: #333;
    }

    .quote-table td {
      border-bottom: 1px solid #eee;
      padding: 0.5rem;
      color: #111;
    }

    .total-row td {
      font-weight: bold;
      font-size: 1.1rem;
    }
  </style>
</head>

<body>
  <div class="layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <div class="topbar">
        <div>
          <div class="topbar-title">Quotation Maker</div>
          <div class="topbar-sub">Draft a price quotation and download as an image</div>
        </div>
      </div>
      <div class="page-body">

        <div class="card mb-2 no-print">
          <div class="card-title">Add Items to Quote</div>
          <div class="form-grid-3">
            <div class="form-group">
              <label>Client Name / Company</label>
              <input type="text" id="clientName" class="form-control" placeholder="e.g. John Doe / ABC Corp"
                oninput="updateClient()" />
            </div>
            <div class="form-group">
              <label>Payment Type</label>
              <select id="paymentType" class="form-control">
                <option value="">- Select Type -</option>
                <option value="Cash">Cash</option>
                <option value="GCash">GCash</option>
                <option value="Bank Transfer">Bank Transfer</option>
                <option value="Check">Check</option>
              </select>
            </div>
            <div class="form-group">
              <label>Payment Ref. No.</label>
              <input type="text" id="paymentRef" class="form-control" placeholder="e.g. 100012345" />
            </div>
          </div>

          <div class="form-grid-2" style="align-items:end; margin-top:0.5rem;">
            <div class="form-group">
              <label>Product Name</label>
              <select id="prodSel" class="form-control" onchange="fillProduct()">
                <option value="">- Select product -</option>
                <?php foreach ($products as $p): ?>
                  <option value="<?= $p['id'] ?>" data-pr="<?= $p['selling_price'] ?>"
                    data-st="<?= $p['current_stock'] ?>">
                    <?= htmlspecialchars($p['board_type']) ?> | <?= htmlspecialchars($p['category_name']) ?> | PHP
                    <?= number_format($p['selling_price'], 2) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-grid-3" style="align-items:end;">
            <div class="form-group">
              <label>Quantity <span id="qtyUnit" class="muted" style="font-size:0.8rem;"></span></label>
              <input type="number" id="qty" class="form-control" min="1" placeholder="0"
                oninput="calcTotal(); checkStockAjax();" />
              <div id="stockAjaxStatus" style="font-size:0.8rem; margin-top:4px;"></div>
            </div>
            <div class="form-group">
              <label>Price per unit (PHP) <span
                  style="color:var(--accent-lt);font-size:.72rem;">(editable)</span></label>
              <input type="number" step="0.01" min="0" id="ppu" class="form-control" oninput="calcTotal()" />
            </div>
            <div class="form-group">
              <label>Line Total (PHP)</label>
              <div id="lineTotal" style="font-size:1.15rem;font-weight:700;color:#16a34a;padding:.52rem 0;">PHP 0.00
              </div>
            </div>
          </div>

          <div style="margin-top:1rem; display:flex; gap:.5rem;">
            <button type="button" class="btn btn-success" onclick="addToQuote()">+ Add to Quote</button>
            <button type="button" class="btn btn-ghost" onclick="clearQuote()">Clear All</button>
          </div>
        </div>

        <!-- Generated Quote Preview -->
        <div id="quoteArea" class="quote-preview">
          <div class="quote-header">
            <div class="quote-brand">
              <h1>StockBoard</h1>
              <p>For MCC Mae Anne Cunanan Laminates</p>
              <div style="margin-top:1rem; color:#333;">
                <strong style="display:block;">Quotation For:</strong>
                <span id="displayClientName">Walk-in Client</span>
              </div>
            </div>
            <div class="quote-details" style="color:#333;">
              <h2 style="margin:0; font-size:1.5rem;">QUOTATION</h2>
              <p style="margin:0;">Date: <?= date('F d, Y') ?></p>
              <p style="margin:0;">Prepared by: <?= htmlspecialchars($user['full_name']) ?></p>
            </div>
          </div>

          <table class="quote-table">
            <thead>
              <tr>
                <th>Item Description</th>
                <th style="text-align:right;">Qty</th>
                <th style="text-align:right;">Unit Price (PHP)</th>
                <th style="text-align:right;">Total (PHP)</th>
              </tr>
            </thead>
            <tbody id="quoteBody">
              <tr>
                <td colspan="4" style="text-align:center;color:#888;">No items added yet.</td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="total-row">
                <td colspan="3" style="text-align:right; border-top:2px solid #ccc; padding-top:1rem;">Grand Total:</td>
                <td id="quoteGrandTotal"
                  style="text-align:right; border-top:2px solid #ccc; padding-top:1rem; color:#22c55e;">PHP 0.00</td>
              </tr>
            </tfoot>
          </table>
          <div style="margin-top: 2rem; color:#555; font-size:0.85rem; text-align:center;">
            Thank you for your business! Prices are subject to change without prior notice.
          </div>
        </div>

        <div style="display:flex; justify-content:center; align-items:center; gap: 1rem; margin-top:1.5rem;"
          class="no-print">
          <button class="btn btn-primary" style="font-size:1.1rem; padding:0.8rem 1.5rem;" onclick="downloadQuote()">
            Download Photo of Quotation
          </button>
          <button class="btn btn-warning" id="bookBtn" style="font-size:1.1rem; padding:0.8rem 1.5rem;"
            onclick="bookOrder()">
            Send to Sales Queue (Book)
          </button>
        </div>

      </div>
    </div>
  </div>

  <!-- Confirmation Modal -->
  <div class="overlay" id="confirmModal">
    <div class="modal" style="max-width:400px; text-align:center;">
      <h3 style="margin-top:0; color:#fff;">Confirm Booking</h3>
      <p style="font-size:1.1rem; margin-bottom:0.5rem; color:#fff;">Total Amount: <strong id="modalTotal">PHP
          0.00</strong></p>
      <p style="font-size:1rem; margin-bottom:1.5rem; color:#ddd;">Ref No: <strong id="modalRef"></strong> (<span
          id="modalType"></span>)</p>

      <div
        style="background:#fff; color:#000; border:1px solid #000; padding:1rem; font-weight:bold; font-size:0.9rem; margin-bottom:1rem; text-align:left;">
        IMPORTANT: This transaction will be added to your Pending Liability Ledger.
      </div>

      <div style="background:#fff; border:1px solid #ccc; padding:1rem; text-align:left; margin-bottom:1.5rem;">
        <label style="display:flex; gap:0.75rem; align-items:flex-start; cursor:pointer; margin:0;">
          <input type="checkbox" id="liabilityCheck" style="margin-top:0.2rem; transform:scale(1.2);"
            onchange="toggleModalBtn()" />
          <div>
            <strong style="color:#000; display:block; margin-bottom:0.2rem; font-size:0.95rem;">Declaration of
              Accountability</strong>
            <span style="color:#333; font-size:0.85rem; display:block;">I confirm that I have received the payment for
              this transaction and encoded a valid Reference Number. I assume full financial liability for this
              remittance until cleared.</span>
            <span
              style="color:#555; font-size:0.8rem; font-style:italic; margin-top:0.3rem; display:block;">(Kinukumpirma
              ko na natanggap ko ang bayad at ako ang mananagot sa perang ito hanggang sa ma-clear ng Manager.)</span>
          </div>
        </label>
      </div>

      <div style="display:flex; gap:1rem; justify-content:center;">
        <button class="btn btn-ghost" style="color:#000; border:1px solid #ccc;"
          onclick="document.getElementById('confirmModal').classList.remove('open')">Cancel</button>
        <button class="btn" id="modalConfirmBtn" style="background:#000; color:#fff; border:1px solid #000;"
          onclick="submitBooking()" disabled>Proceed & Book</button>
      </div>
    </div>
  </div>

  <script>
    const PRODUCTS = {
      <?php foreach ($products as $p): ?>
            "<?= $p['id'] ?>": {
          name: "<?= addslashes($p['board_type']) ?>",
          color: "<?= addslashes($p['color_design']) ?>",
          unit: "<?= addslashes($p['unit']) ?>",
          price: <?= $p['selling_price'] ?>,
          stock: <?= $p['current_stock'] ?>
        },
      <?php endforeach; ?>
    };

    let cart = [];

    function updateClient() {
      const name = document.getElementById('clientName').value.trim();
      document.getElementById('displayClientName').textContent = name ? name : 'Walk-in Client';
    }

    function fillProduct() {
      const pid = document.getElementById('prodSel').value;
      if (pid && PRODUCTS[pid]) {
        const p = PRODUCTS[pid];
        document.getElementById('qtyUnit').textContent = '(' + p.unit + ')';
        document.getElementById('ppu').value = p.price.toFixed(2);
        document.getElementById('ppu').min = p.price.toFixed(2);
        calcTotal();
        checkStockAjax();
      } else {
        document.getElementById('qtyUnit').textContent = '';
        document.getElementById('ppu').value = '';
        document.getElementById('ppu').min = '0';
        document.getElementById('lineTotal').textContent = 'PHP 0.00';
        document.getElementById('stockAjaxStatus').innerHTML = '';
      }
    }

    function checkStockAjax() {
      const pid = document.getElementById('prodSel').value;
      const qty = parseInt(document.getElementById('qty').value) || 0;
      const statusEl = document.getElementById('stockAjaxStatus');
      if (!pid) { statusEl.innerHTML = ''; return; }

      fetch(`api/check-stock.php?product_id=${pid}&qty=${qty}`)
        .then(res => res.json())
        .then(data => {
          if (data.error) {
            statusEl.innerHTML = `<span style="color:#ef4444">${data.error}</span>`;
          } else {
            const color = data.available ? '#22c55e' : '#ef4444';
            statusEl.innerHTML = `<span style="color:${color}; font-weight:bold;">Stock Available: ${data.message}</span>`;
          }
        })
        .catch(err => {
          console.error('Ajax Stock Check Error:', err);
        });
    }

    function calcTotal() {
      const q = parseFloat(document.getElementById('qty').value) || 0;
      const p = parseFloat(document.getElementById('ppu').value) || 0;
      document.getElementById('lineTotal').textContent = 'PHP ' + (q * p).toLocaleString('en-PH', { minimumFractionDigits: 2 });
    }

    function addToQuote() {
      const pid = document.getElementById('prodSel').value;
      const qty = parseInt(document.getElementById('qty').value);
      const ppu = parseFloat(document.getElementById('ppu').value);
      if (!pid) { alert('Select a product.'); return; }
      if (!qty || qty < 1) { alert('Enter valid quantity.'); return; }
      if (!ppu || ppu < 0) { alert('Enter valid price.'); return; }

      const prod = PRODUCTS[pid];
      if (ppu < prod.price) { alert('Price per unit cannot be below the default selling price (PHP ' + prod.price.toFixed(2) + ').'); return; }

      const desc = prod.name + (prod.color ? ' - ' + prod.color : '');
      const existing = cart.find(c => c.product_id === pid && c.price === ppu);

      const currentQtyInCart = existing ? existing.qty : 0;
      if (qty + currentQtyInCart > prod.stock) {
        alert(`Insufficient stock! You already have ${currentQtyInCart} in quote, and adding ${qty} more exceeds the current stock of ${prod.stock}.`);
        return;
      }

      if (existing) { existing.qty += qty; }
      else { cart.push({ product_id: pid, desc, qty, price: ppu }); }

      renderQuote();
      document.getElementById('prodSel').value = '';
      document.getElementById('qty').value = '';
      document.getElementById('ppu').value = '';
      document.getElementById('lineTotal').textContent = 'PHP 0.00';
    }

    function clearQuote() {
      if (confirm('Clear all items?')) {
        cart = [];
        renderQuote();
      }
    }

    function renderQuote() {
      const tbody = document.getElementById('quoteBody');
      tbody.innerHTML = '';

      if (cart.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#888;">No items added yet.</td></tr>';
        document.getElementById('quoteGrandTotal').textContent = 'PHP 0.00';
        return;
      }

      let grand = 0;
      cart.forEach((c, idx) => {
        const lineT = c.qty * c.price;
        grand += lineT;
        tbody.insertAdjacentHTML('beforeend', `
          <tr>
            <td>
              ${c.desc}
              <div class="no-print" style="margin-top:2px;">
                 <button class="btn btn-ghost btn-sm" style="padding:0.1rem 0.4rem; font-size:0.7rem;" onclick="removeItem(${idx})">Remove</button>
              </div>
            </td>
            <td style="text-align:right;">${c.qty}</td>
            <td style="text-align:right;">${c.price.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
            <td style="text-align:right; font-weight:500;">${lineT.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
          </tr>
        `);
      });
      document.getElementById('quoteGrandTotal').textContent = 'PHP ' + grand.toLocaleString('en-PH', { minimumFractionDigits: 2 });
    }

    function removeItem(idx) {
      cart.splice(idx, 1);
      renderQuote();
    }

    function downloadQuote() {
      if (cart.length === 0) {
        alert('Please add items to quote before saving.');
        return;
      }
      const client = document.getElementById('clientName').value.trim() || 'Client';
      const safeName = client.replace(/[^a-zA-Z0-9_-]/g, '_');
      const filename = `Quotation_${safeName}_${new Date().getTime()}.png`;

      // Hide the remove buttons temporarily
      const noPrintEls = document.querySelectorAll('#quoteArea .no-print');
      noPrintEls.forEach(el => el.style.display = 'none');

      html2canvas(document.querySelector("#quoteArea"), {
        scale: 2, // High quality image
        backgroundColor: "#ffffff",
        useCORS: true
      }).then(canvas => {
        // Restore buttons
        noPrintEls.forEach(el => el.style.display = '');

        const imgData = canvas.toDataURL("image/png");
        const link = document.createElement('a');
        link.download = filename;
        link.href = imgData;
        link.click();
      }).catch(err => {
        console.error('Error generating image:', err);
        alert('Failed to generate image. Please try again or use browser print to PDF.');
        noPrintEls.forEach(el => el.style.display = '');
      });
    }

    function toggleModalBtn() {
      const isChecked = document.getElementById('liabilityCheck').checked;
      document.getElementById('modalConfirmBtn').disabled = !isChecked;
    }

    function bookOrder() {
      if (cart.length === 0) {
        alert('Please add items to quote before booking an order.');
        return;
      }

      const paymentType = document.getElementById('paymentType').value.trim();
      const paymentRef = document.getElementById('paymentRef').value.trim();

      if (!paymentType) {
        alert('Payment Type is required.');
        return;
      }

      if (paymentType !== 'Cash' && !paymentRef) {
        alert('Reference Number is required for ' + paymentType + '.');
        return;
      }

      const clientName = document.getElementById('clientName').value.trim();
      if (!clientName) {
        if (!confirm('Client Name is empty. Proceed anyway?')) return;
      }

      // Populate modal
      document.getElementById('modalTotal').textContent = document.getElementById('quoteGrandTotal').textContent;
      document.getElementById('modalRef').textContent = paymentRef;
      document.getElementById('modalType').textContent = paymentType;

      // Reset checkbox state
      document.getElementById('liabilityCheck').checked = false;
      toggleModalBtn();

      // Show modal
      document.getElementById('confirmModal').classList.add('open');
    }

    function submitBooking() {
      const clientName = document.getElementById('clientName').value.trim();
      const paymentType = document.getElementById('paymentType').value.trim();
      const paymentRef = document.getElementById('paymentRef').value.trim();

      const payload = new URLSearchParams();
      payload.append('action', 'record_sale_pending');
      payload.append('items', JSON.stringify(cart));
      payload.append('notes', clientName ? 'Client: ' + clientName : 'Pending Order');
      payload.append('payment_type', paymentType);
      payload.append('payment_reference', paymentRef);

      const btn = document.getElementById('modalConfirmBtn');
      const origText = btn.textContent;
      btn.textContent = 'Processing...';
      btn.disabled = true;

      fetch('sales.php', {
        method: 'POST',
        body: payload
      }).then(res => res.text()).then(txt => {
        btn.textContent = origText;
        btn.disabled = false;
        document.getElementById('confirmModal').classList.remove('open');

        if (txt.startsWith('Error:')) {
          alert(txt);
        } else {
          alert('Order successfully sent to Sales Queue! Stock has been reserved.');
          cart = [];
          renderQuote();
          document.getElementById('clientName').value = '';
          document.getElementById('paymentType').value = '';
          document.getElementById('paymentRef').value = '';
          document.getElementById('liabilityCheck').checked = false;
          toggleModalBtn();
          updateClient();
        }
      }).catch(err => {
        btn.textContent = origText;
        btn.disabled = false;
        alert('Network error communicating with Sales Queue.');
      });
    }
  </script>
</body>

</html>