<?php
// src/views/pages/financial/expense-create.php
// Create or edit an expense (manual entry or linked to receipt)
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/acl.php';

$orgId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);
$editId = (int)($_GET['id'] ?? 0);

// Pre-fill from query params (when coming from receipt)
$prefillAmount = $_GET['amount'] ?? '';
$prefillDate = $_GET['date'] ?? date('Y-m-d');
$prefillVendor = $_GET['vendor'] ?? '';
$prefillReceiptId = (int)($_GET['receipt_id'] ?? 0);
$prefillCategoryId = (int)($_GET['category_id'] ?? 0);

// Fetch dropdowns
$cats = $pdo->prepare('SELECT id, name FROM expense_categories ORDER BY name');
$cats->execute();
$categories = $cats->fetchAll(PDO::FETCH_ASSOC);

$vendorsQ = $pdo->prepare('SELECT id, name FROM vendors WHERE is_active=1 ORDER BY name');
$vendorsQ->execute();
$vendors = $vendorsQ->fetchAll(PDO::FETCH_ASSOC);

$clientsQ = $pdo->prepare('SELECT id, name FROM clients WHERE archived = 0 ORDER BY name');
$clientsQ->execute();
$clients = $clientsQ->fetchAll(PDO::FETCH_ASSOC);

$projectsQ = $pdo->prepare('SELECT id, name FROM projects ORDER BY name');
$projectsQ->execute();
$projects = $projectsQ->fetchAll(PDO::FETCH_ASSOC);

// Load existing expense for edit mode
$expense = null;
if ($editId > 0) {
    [$expenseScopeWhere, $expenseScopeParams] = finance_scope_clause($pdo, 'e', $userId, $orgId, 'created_by');
    $stmt = $pdo->prepare('
        SELECT e.*, v.name AS vendor_name
        FROM expenses e
        LEFT JOIN vendors v ON v.id = e.vendor_id
        WHERE e.id = ? AND ' . $expenseScopeWhere . '
    ');
    $stmt->execute(array_merge([$editId], $expenseScopeParams));
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$expense) {
        header('Location: /?page=financial/expenses-list&tab=expenses');
        exit;
    }
}
$selectedCategoryId = (int)($expense['category_id'] ?? $prefillCategoryId);
$expenseCreateReturnUrl = '/?page=financial/expense-create';
if ($editId > 0) {
    $expenseCreateReturnUrl .= '&id=' . $editId;
}
?>

<div style="max-width:800px;margin:0 auto;padding:24px">
  <div class="page-head">
    <h2><?php echo $editId > 0 ? 'Edit Expense' : 'Add Expense'; ?></h2>
    <a href="/?page=financial/expenses-list&tab=expenses" class="btn btn-sm">Back to Expenses</a>
  </div>

  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger" style="margin-bottom:16px"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
  <?php elseif (!empty($_GET['success'])): ?>
    <div class="alert alert-success" style="margin-bottom:16px"><?php echo htmlspecialchars((string)$_GET['success']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['created']) || !empty($_GET['updated'])): ?>
    <div class="alert alert-success" style="margin-bottom:16px">Expense saved.</div>
  <?php endif; ?>

  <form id="expenseForm" method="post" action="/?page=financial/expense-handler" class="card">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_sf_token('expense')); ?>">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="<?php echo $editId > 0 ? 'update' : 'create'; ?>">
    <?php if ($editId > 0): ?><input type="hidden" name="id" value="<?php echo $editId; ?>"><?php endif; ?>
    <input type="hidden" name="vendor_id" id="vendorId" value="<?php echo $expense['vendor_id'] ?? 0; ?>">
    <input type="hidden" name="receipt_id" value="<?php echo $expense['receipt_id'] ?? $prefillReceiptId; ?>">

    <div class="grid grid-2">
      <div class="field">
        <label class="label">Vendor</label>
        <input type="text" name="vendor_name" id="vendorName" list="vendorList"
               value="<?php echo htmlspecialchars($expense['vendor_name'] ?? $prefillVendor); ?>"
               placeholder="Start typing or select vendor" class="input">
        <datalist id="vendorList">
          <?php foreach ($vendors as $v): ?><option value="<?php echo htmlspecialchars($v['name']); ?>" data-id="<?php echo (int)$v['id']; ?>"></option><?php endforeach; ?>
        </datalist>
      </div>

      <div class="field">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
          <label class="label" for="expenseCategory">Category</label>
          <button type="button" class="btn btn-sm" id="openCategoryModal">New Category</button>
        </div>
        <select name="category_id" id="expenseCategory" class="input">
          <option value="0">No category</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?php echo (int)$cat['id']; ?>" <?php echo $selectedCategoryId === (int)$cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid grid-2">
      <div class="field">
        <label class="label">Amount *</label>
        <input type="number" name="amount" id="amountInput" step="0.01" required
               value="<?php echo htmlspecialchars($expense['amount'] ?? $prefillAmount); ?>" class="input">
      </div>
      <div class="field">
        <label class="label">Expense Date *</label>
        <input type="date" name="expense_date" required value="<?php echo htmlspecialchars($expense['expense_date'] ?? $prefillDate); ?>" class="input">
      </div>
    </div>

    <div class="field">
      <label class="label">Description</label>
      <input type="text" name="description" value="<?php echo htmlspecialchars($expense['description'] ?? ''); ?>" class="input" placeholder="What was this expense for?">
    </div>

    <div class="grid grid-2">
      <div class="field">
        <label class="label">Reference Number</label>
        <input type="text" name="reference_number" value="<?php echo htmlspecialchars($expense['reference_number'] ?? ''); ?>" class="input" placeholder="Order #, check #, etc.">
      </div>
      <div class="field" style="justify-content:flex-end">
        <label class="label" style="display:flex;align-items:center;gap:6px">
          <input type="checkbox" name="is_billable" id="billableCheck" value="1" <?php echo !empty($expense['is_billable']) ? 'checked' : ''; ?>>
          Billable to Client
        </label>
        <div id="billableFields" style="display:none">
          <select name="client_id" class="input-sm" style="margin-bottom:8px">
            <option value="0">— Select Client —</option>
            <?php foreach ($clients as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo ($expense['client_id'] ?? 0)===(int)$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
          </select>
          <select name="project_id" class="input-sm">
            <option value="0">— Select Project —</option>
            <?php foreach ($projects as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo ($expense['project_id'] ?? 0)===(int)$p['id']?'selected':''; ?>><?php echo htmlspecialchars($p['name']); ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="field">
      <label class="label" style="display:flex;align-items:center;gap:6px">
        <input type="checkbox" name="is_tax_deductible" value="1" <?php echo isset($expense['is_tax_deductible']) ? ($expense['is_tax_deductible'] ? 'checked' : '') : 'checked'; ?>>
        Tax Deductible
      </label>
    </div>

    <div class="field">
      <label class="label">Notes</label>
      <textarea name="notes" class="input" rows="3"><?php echo htmlspecialchars($expense['notes'] ?? ''); ?></textarea>
    </div>

    <div class="flex-end" style="margin-top:16px">
      <a href="/?page=financial/expenses-list&tab=expenses" class="btn">Cancel</a>
      <button type="submit" class="btn btn-primary"><?php echo $editId > 0 ? 'Update' : 'Create'; ?> Expense</button>
    </div>
  </form>

  <div id="categoryModalBackdrop" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:998"></div>
  <div id="categoryModal" class="card" style="display:none;position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(480px,calc(100vw - 32px));z-index:999">
    <div class="card-head">
      <h3 class="card-title">New Category</h3>
      <button type="button" class="btn btn-sm" id="closeCategoryModal">Close</button>
    </div>
    <form id="newCategoryForm" method="post" action="/?page=financial/category-handler">
      <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_sf_token('category')); ?>">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($expenseCreateReturnUrl); ?>">

      <div id="categoryFormError" class="alert alert-danger" style="display:none;margin-bottom:12px"></div>

      <div class="field">
        <label for="newCategoryName" class="label">Name *</label>
        <input type="text" id="newCategoryName" name="name" required class="input" autocomplete="off">
      </div>

      <div class="grid grid-2">
        <label class="label" style="display:flex;align-items:center;gap:6px">
          <input type="checkbox" name="tax_deductible" value="1" checked>
          Tax Deductible
        </label>
        <div class="field">
          <label for="newCategoryColor" class="label">Color</label>
          <input type="color" id="newCategoryColor" name="color" value="#3b82f6" class="input" style="padding:4px;height:40px">
        </div>
      </div>

      <div class="flex-end" style="margin-top:16px">
        <button type="button" class="btn" id="cancelCategoryCreate">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Category</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  'use strict';

// Billable toggle
const billableCheck = document.getElementById('billableCheck');
const billableFields = document.getElementById('billableFields');
function toggleBillable() { billableFields.style.display = billableCheck.checked ? 'block' : 'none'; }
billableCheck.addEventListener('change', toggleBillable);
toggleBillable();

// Vendor autocomplete → set vendor_id
const vendorNameInput = document.getElementById('vendorName');
const vendorIdInput = document.getElementById('vendorId');
vendorNameInput.addEventListener('change', function() {
  const val = this.value;
  const options = document.querySelectorAll('#vendorList option');
  let found = false;
  options.forEach(opt => {
    if (opt.value === val) { vendorIdInput.value = opt.dataset.id; found = true; }
  });
  if (!found) vendorIdInput.value = 0;
});

const openCategoryModal = document.getElementById('openCategoryModal');
const closeCategoryModal = document.getElementById('closeCategoryModal');
const cancelCategoryCreate = document.getElementById('cancelCategoryCreate');
const categoryModal = document.getElementById('categoryModal');
const categoryBackdrop = document.getElementById('categoryModalBackdrop');
const newCategoryForm = document.getElementById('newCategoryForm');
const categorySelect = document.getElementById('expenseCategory');
const categoryFormError = document.getElementById('categoryFormError');
const newCategoryName = document.getElementById('newCategoryName');

function setCategoryModal(open) {
  categoryModal.style.display = open ? 'block' : 'none';
  categoryBackdrop.style.display = open ? 'block' : 'none';
  if (open) {
    categoryFormError.style.display = 'none';
    categoryFormError.textContent = '';
    setTimeout(function() { newCategoryName.focus(); }, 0);
  }
}

openCategoryModal.addEventListener('click', function() { setCategoryModal(true); });
closeCategoryModal.addEventListener('click', function() { setCategoryModal(false); });
cancelCategoryCreate.addEventListener('click', function() { setCategoryModal(false); });
categoryBackdrop.addEventListener('click', function() { setCategoryModal(false); });

newCategoryForm.addEventListener('submit', function(event) {
  event.preventDefault();
  categoryFormError.style.display = 'none';
  categoryFormError.textContent = '';

  fetch(newCategoryForm.action, {
    method: 'POST',
    body: new FormData(newCategoryForm),
    headers: {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
    .then(function(response) {
      return response.text().then(function(text) {
        let payload = null;
        try {
          payload = JSON.parse(text);
        } catch (error) {
          throw new Error('Category request failed. Please refresh and try again.');
        }
        if (!response.ok) {
          throw new Error(payload.message || 'Category could not be created.');
        }
        return payload;
      });
    })
    .then(function(payload) {
      if (!payload || !payload.success) {
        throw new Error((payload && payload.message) || 'Category could not be created.');
      }
      const option = document.createElement('option');
      option.value = String(payload.id);
      option.textContent = payload.name || newCategoryName.value;
      option.selected = true;
      categorySelect.appendChild(option);
      newCategoryForm.reset();
      document.getElementById('newCategoryColor').value = '#3b82f6';
      setCategoryModal(false);
    })
    .catch(function(error) {
      categoryFormError.textContent = error.message || 'Category could not be created.';
      categoryFormError.style.display = 'block';
    });
});

})();
</script>
