<?php
// src/views/pages/financial/expense-create.php
// Create or edit an expense (manual entry or linked to receipt)
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/acl.php';

$orgId = active_or_default_org_id($pdo);
$editId = (int)($_GET['id'] ?? 0);

// Pre-fill from query params (when coming from receipt)
$prefillAmount = $_GET['amount'] ?? '';
$prefillDate = $_GET['date'] ?? date('Y-m-d');
$prefillVendor = $_GET['vendor'] ?? '';
$prefillReceiptId = (int)($_GET['receipt_id'] ?? 0);

// Fetch dropdowns
$cats = $pdo->prepare('SELECT id, name FROM expense_categories WHERE organization_id=? ORDER BY name');
$cats->execute([$orgId]);
$categories = $cats->fetchAll(PDO::FETCH_ASSOC);

$vendorsQ = $pdo->prepare('SELECT id, name FROM vendors WHERE organization_id=? AND is_active=1 ORDER BY name');
$vendorsQ->execute([$orgId]);
$vendors = $vendorsQ->fetchAll(PDO::FETCH_ASSOC);

$clientsQ = $pdo->query('SELECT id, name FROM clients ORDER BY name');
$clients = $clientsQ->fetchAll(PDO::FETCH_ASSOC);

$projectsQ = $pdo->query('SELECT id, name FROM projects ORDER BY name');
$projects = $projectsQ->fetchAll(PDO::FETCH_ASSOC);

// Load existing expense for edit mode
$expense = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM expenses WHERE id=? AND organization_id=?');
    $stmt->execute([$editId, $orgId]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$expense) {
        header('Location: /?page=financial/expenses-list');
        exit;
    }
}

$paymentMethods = [];
?>

<div style="max-width:800px;margin:0 auto;padding:24px">
  <div class="page-head">
    <h2><?php echo $editId > 0 ? 'Edit Expense' : 'Add Expense'; ?></h2>
    <a href="/?page=financial/expenses-list" class="btn btn-sm">Back to Expenses</a>
  </div>

  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger" style="margin-bottom:16px"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
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
          <?php foreach ($vendors as $v): ?><option value="<?php echo htmlspecialchars($v['name']); ?>" data-id="<?php echo (int)$v['id']; ?>"><?php endforeach; ?>
        </datalist>
      </div>

      <div class="field">
        <label class="label">Category</label>
        <select name="category_id" class="input">
          <option value="0">— No category —</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?php echo (int)$cat['id']; ?>" <?php echo ($expense['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
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
      <div class="field" style="display:none">
        <label class="label">Payment Method</label>
        <select name="payment_method" class="input">
          <option value="">— Select —</option>
          <?php foreach ($paymentMethods as $val => $label): ?>
            <option value="<?php echo $val; ?>" <?php echo ($expense['payment_method'] ?? '') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
          <?php endforeach; ?>
        </select>
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
      <a href="/?page=financial/expenses-list" class="btn">Cancel</a>
      <button type="submit" class="btn btn-primary"><?php echo $editId > 0 ? 'Update' : 'Create'; ?> Expense</button>
    </div>
  </form>
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

})();
</script>
