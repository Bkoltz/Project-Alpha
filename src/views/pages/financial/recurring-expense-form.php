<?php

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/recurring_expenses.php';

$orgId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);
$id = (int)($_GET['id'] ?? 0);
$schedule = null;

if ($id > 0) {
    [$scopeWhere, $scopeParams] = finance_scope_clause($pdo, 'r', $userId, $orgId, 'created_by');
    $stmt = $pdo->prepare('SELECT r.*,v.name AS vendor_name FROM recurring_expenses r LEFT JOIN vendors v ON v.id=r.vendor_id WHERE r.id=? AND ' . $scopeWhere);
    $stmt->execute(array_merge([$id], $scopeParams));
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$schedule) {
        header('Location: /?page=financial/expenses-list&tab=recurring');
        exit;
    }
}

$categories = $pdo->query('SELECT id,name FROM expense_categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$vendors = $pdo->query('SELECT id,name FROM vendors WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query('SELECT id,name FROM clients WHERE archived=0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$projects = $pdo->query('SELECT id,name FROM projects ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

$frequency = 'yearly';
if ($schedule) {
    $count = (int)$schedule['interval_count'];
    $unit = (string)$schedule['interval_unit'];
    $frequency = $unit === 'week' ? 'weekly' : ($unit === 'year' ? 'yearly' : ($count === 3 ? 'quarterly' : 'monthly'));
}
$nextDate = (string)($schedule['next_expense_date'] ?? date('Y-m-d'));
?>

<div style="max-width:860px;margin:0 auto;padding:24px">
  <div class="page-head">
    <div>
      <h2><?php echo $schedule ? 'Edit Recurring Expense' : 'Add Recurring Expense'; ?></h2>
      <p class="muted" style="margin:5px 0 0">Each due date creates a normal expense record. Past generated expenses never change when this schedule is edited.</p>
    </div>
    <a href="/?page=financial/expenses-list&tab=recurring" class="btn btn-sm">Back to Recurring</a>
  </div>

  <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger" style="margin-bottom:16px"><?php echo htmlspecialchars((string)$_GET['error']); ?></div><?php endif; ?>
  <?php if ($schedule): ?>
    <div class="alert" style="margin-bottom:16px;background:#f8fafc;border-color:#cbd5e1;color:#334155">
      Status: <strong><?php echo htmlspecialchars(ucfirst((string)$schedule['status'])); ?></strong>
      &middot; Generated <?php echo number_format((int)$schedule['generated_count']); ?> expense<?php echo (int)$schedule['generated_count'] === 1 ? '' : 's'; ?>
      <?php if (!empty($schedule['last_generated_date'])): ?>&middot; Last generated <?php echo htmlspecialchars(date('M j, Y', strtotime((string)$schedule['last_generated_date']))); ?><?php endif; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="/?page=financial/recurring-expense-handler" class="card">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_sf_token('expense')); ?>">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="<?php echo $schedule ? 'update' : 'create'; ?>">
    <?php if ($schedule): ?><input type="hidden" name="id" value="<?php echo (int)$schedule['id']; ?>"><?php endif; ?>
    <input type="hidden" name="vendor_id" id="recurringVendorId" value="<?php echo (int)($schedule['vendor_id'] ?? 0); ?>">

    <div class="grid grid-2">
      <div class="field">
        <label class="label">Vendor</label>
        <input type="text" name="vendor_name" id="recurringVendorName" list="recurringVendorList" value="<?php echo htmlspecialchars((string)($schedule['vendor_name'] ?? '')); ?>" placeholder="Registrar, hosting provider, software vendor" class="input">
        <datalist id="recurringVendorList"><?php foreach ($vendors as $vendor): ?><option value="<?php echo htmlspecialchars($vendor['name']); ?>" data-id="<?php echo (int)$vendor['id']; ?>"></option><?php endforeach; ?></datalist>
      </div>
      <div class="field">
        <label class="label">Category</label>
        <select name="category_id" class="input">
          <option value="0">No category</option>
          <?php foreach ($categories as $category): ?><option value="<?php echo (int)$category['id']; ?>" <?php echo (int)($schedule['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($category['name']); ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field">
      <label class="label">Description *</label>
      <input type="text" name="description" maxlength="500" required value="<?php echo htmlspecialchars((string)($schedule['description'] ?? '')); ?>" class="input" placeholder="Example: Annual domain renewal - clientdomain.com">
    </div>

    <div class="grid grid-2">
      <div class="field"><label class="label">Amount *</label><input type="number" name="amount" min="0.01" step="0.01" required value="<?php echo htmlspecialchars((string)($schedule['amount'] ?? '')); ?>" class="input"></div>
      <div class="field">
        <label class="label">Frequency *</label>
        <select name="frequency" class="input" required>
          <?php foreach (['weekly'=>'Weekly','monthly'=>'Monthly','quarterly'=>'Quarterly','yearly'=>'Yearly'] as $value=>$label): ?><option value="<?php echo $value; ?>" <?php echo $frequency === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid grid-2">
      <div class="field"><label class="label">Next Expense Date *</label><input type="date" name="next_expense_date" min="<?php echo date('Y-m-d'); ?>" required value="<?php echo htmlspecialchars($nextDate); ?>" class="input"><small class="muted">Use today to create the first occurrence immediately.</small></div>
      <div class="field"><label class="label">End Date</label><input type="date" name="end_date" value="<?php echo htmlspecialchars((string)($schedule['end_date'] ?? '')); ?>" class="input"><small class="muted">Leave blank for ongoing renewals.</small></div>
    </div>

    <div class="grid grid-2">
      <div class="field"><label class="label">Client Attribution</label><select name="client_id" class="input"><option value="0">No client</option><?php foreach ($clients as $client): ?><option value="<?php echo (int)$client['id']; ?>" <?php echo (int)($schedule['client_id'] ?? 0) === (int)$client['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($client['name']); ?></option><?php endforeach; ?></select><small class="muted">Use this even when you absorb the cost.</small></div>
      <div class="field"><label class="label">Project Attribution</label><select name="project_id" class="input"><option value="0">No project</option><?php foreach ($projects as $project): ?><option value="<?php echo (int)$project['id']; ?>" <?php echo (int)($schedule['project_id'] ?? 0) === (int)$project['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($project['name']); ?></option><?php endforeach; ?></select></div>
    </div>

    <div class="grid grid-2">
      <div class="field"><label class="label" style="display:flex;align-items:center;gap:7px"><input type="checkbox" name="is_billable" value="1" <?php echo !empty($schedule['is_billable']) ? 'checked' : ''; ?>> Billable to Client</label></div>
      <div class="field"><label class="label" style="display:flex;align-items:center;gap:7px"><input type="checkbox" name="is_tax_deductible" value="1" <?php echo !isset($schedule['is_tax_deductible']) || !empty($schedule['is_tax_deductible']) ? 'checked' : ''; ?>> Tax Deductible</label></div>
    </div>
    <div class="field"><label class="label">Notes</label><textarea name="notes" rows="3" class="input" placeholder="Account, renewal terms, or internal instructions"><?php echo htmlspecialchars((string)($schedule['notes'] ?? '')); ?></textarea></div>

    <?php if (!$schedule): ?>
      <div class="field"><label class="label" style="display:flex;align-items:flex-start;gap:7px"><input type="checkbox" name="generate_now" value="1" checked style="margin-top:3px"><span>Create the first expense immediately when the next expense date is today.<small class="muted" style="display:block">Future schedules wait for the daily recurring-expense job.</small></span></label></div>
    <?php endif; ?>

    <div class="flex-end" style="margin-top:16px"><a href="/?page=financial/expenses-list&tab=recurring" class="btn">Cancel</a><button type="submit" class="btn btn-primary"><?php echo $schedule ? 'Update' : 'Create'; ?> Recurring Expense</button></div>
  </form>
</div>

<script>
(function () {
  var vendorName = document.getElementById('recurringVendorName');
  var vendorId = document.getElementById('recurringVendorId');
  vendorName.addEventListener('change', function () {
    vendorId.value = '0';
    document.querySelectorAll('#recurringVendorList option').forEach(function (option) {
      if (option.value === vendorName.value) vendorId.value = option.dataset.id || '0';
    });
  });
})();
</script>
