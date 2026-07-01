?<?php
/**
 * categories.php - Category management page (server/XAMPP mode).
 * Viewable by Manager, Administrator, InventoryOfficer, WarehouseStaff, SalesCashier.
 * Add/Edit/Delete restricted to Admin and InventoryOfficer via canManageCategories().
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
requireCap('canViewCategories');

$canManage = canManageCategories();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1.0" />
  <title>Categories &mdash; StockBoard</title>
  <meta name="description" content="Manage product categories for StockBoard inventory system." />
  <link rel="stylesheet" href="css/style.css?v=5" />
</head>

<body>
  <div class="layout">
      <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main">
      <div class="topbar">
        <div>
          <div class="topbar-title">Categories</div>
          <div class="topbar-sub">Manage product categories used across all inventory and sales</div>
        </div>
      </div>
      <div class="page-body">

        <div id="flash" class="flash flash-ok hidden"></div>

        <div class="toolbar">
          <div class="toolbar-left">
            <input type="text" id="srch" class="search-box" placeholder="Search category&hellip;" />
          </div>
          <div class="toolbar-right" id="addBtnWrap">
              <?php if ($canManage): ?>
              <button class="btn btn-primary" onclick="openAdd()">+ Add Category</button>
              <?php endif; ?>
          </div>
        </div>

        <div class="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Category Name</th>
                <th>Description</th>
                <th>Products</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="catBody">
              <tr>
                <td colspan="5" class="tc muted" style="padding:2rem;">Loading...</td>
              </tr>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>

  <!-- ADD / EDIT MODAL -->
  <div class="overlay" id="catModal">
    <div class="modal" style="max-width:460px;">
      <div class="modal-header">
        <div class="modal-title" id="mTitle">Add Category</div>
        <button class="modal-close" onclick="closeModal()">X</button>
      </div>
      <div class="form-group">
        <label>Category Name *</label>
        <input type="text" id="f_name" class="form-control" placeholder="e.g. PETG HIGH GLOSS" />
      </div>
      <div class="form-group">
        <label>Description (optional)</label>
        <input type="text" id="f_desc" class="form-control" placeholder="Short description" />
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
        <button class="btn btn-primary" onclick="saveCategory()">Save</button>
      </div>
    </div>
  </div>

  <script>
    const CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;
    let editId = null;
    let allCats = [];

    // ?,?, Load categories from PHP API ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,
    async function loadAndRender() {
      try {
        const res = await fetch('api/categories.php');
        if (!res.ok) throw new Error('HTTP ' + res.status);
        allCats = await res.json();
      } catch (e) {
        allCats = [];
        showFlash('Failed to load categories: ' + e.message, 'err');
      }
      renderTable();
    }

    function renderTable() {
      const q = document.getElementById('srch').value.toLowerCase();
      const tbody = document.getElementById('catBody');
      tbody.innerHTML = '';

      const filtered = allCats.filter(c =>
        !q || c.name.toLowerCase().includes(q) || (c.description || '').toLowerCase().includes(q)
      );

      if (!filtered.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="tc muted" style="padding:2rem;">No categories found.</td></tr>';
        return;
      }

      filtered.forEach(c => {
        const cnt = c.product_count ?? 0;
        const delAttr = cnt > 0 ? 'title="Has products, remove them first" disabled' : '';
        tbody.insertAdjacentHTML('beforeend', `
        <tr>
          <td class="muted">${c.id}</td>
          <td class="fw7">${escHtml(c.name)}</td>
          <td class="muted" style="font-size:.85rem;">${escHtml(c.description || '-')}</td>
          <td>
            <a href="inventory.php" style="color:var(--accent-lt);">${cnt} product${cnt !== 1 ? 's' : ''}</a>
          </td>
          <td>
            ${CAN_MANAGE ? `
              <button class="btn btn-ghost btn-sm" onclick="openEdit(${c.id})">Edit</button>
              <button class="btn btn-danger btn-sm" onclick="deleteCat(${c.id})" ${delAttr}>Delete</button>
            ` : '-'}
          </td>
        </tr>`);
      });
    }

    function escHtml(s) {
      return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function openAdd() {
      editId = null;
      document.getElementById('mTitle').textContent = 'Add Category';
      document.getElementById('f_name').value = '';
      document.getElementById('f_desc').value = '';
      document.getElementById('catModal').classList.add('open');
    }

    function openEdit(id) {
      const c = allCats.find(x => x.id === id);
      if (!c) return;
      editId = id;
      document.getElementById('mTitle').textContent = 'Edit Category';
      document.getElementById('f_name').value = c.name;
      document.getElementById('f_desc').value = c.description || '';
      document.getElementById('catModal').classList.add('open');
    }

    async function saveCategory() {
      if (!CAN_MANAGE) { showFlash('Access denied.', 'err'); return; }
      const name = document.getElementById('f_name').value.trim().toUpperCase();
      const desc = document.getElementById('f_desc').value.trim();
      if (!name) { alert('Category name is required.'); return; }

      const body = editId === null
        ? { action: 'add', name, description: desc }
        : { action: 'edit', id: editId, name, description: desc };

      try {
        const res = await fetch('api/categories.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        });
        const data = await res.json();
        if (!data.ok) { showFlash(data.error || 'Error saving category.', 'err'); return; }
        closeModal();
        await loadAndRender();
        showFlash(`Category "${name}" saved.`);
      } catch (e) {
        showFlash('Network error: ' + e.message, 'err');
      }
    }

    async function deleteCat(id) {
      if (!CAN_MANAGE) { showFlash('Access denied.', 'err'); return; }
      const c = allCats.find(x => x.id === id);
      if (!confirm(`Delete category "${c?.name}"?`)) return;

      try {
        const res = await fetch('api/categories.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'delete', id }),
        });
        const data = await res.json();
        if (!data.ok) { showFlash(data.error || 'Error deleting.', 'err'); return; }
        await loadAndRender();
        showFlash(data.msg || 'Category deleted.');
      } catch (e) {
        showFlash('Network error: ' + e.message, 'err');
      }
    }

    function closeModal() {
      document.getElementById('catModal').classList.remove('open');
    }

    function showFlash(msg, type = 'ok') {
      const el = document.getElementById('flash');
      el.textContent = (type === 'ok' ? 'o. ' : 'O ') + msg;
      el.className = `flash flash-${type}`;
      el.classList.remove('hidden');
      setTimeout(() => el.classList.add('hidden'), 4000);
    }

    // ?,?, Boot ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,
    document.getElementById('catModal').addEventListener('click', e => {
      if (e.target === document.getElementById('catModal')) closeModal();
    });
    document.getElementById('srch').addEventListener('input', renderTable);
    loadAndRender();
  </script>
</body>

</html>
