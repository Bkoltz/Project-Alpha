<?php
// src/views/pages/financial/expense-detail.php
// View a single expense with receipt preview and actions
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$orgId = 1;
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /?page=financial/expenses-list'); exit; }

$stmt = $pdo->prepare('
    SELECT e.*, v.name as vendor_name, ec.name as category_name, c.name as client_name, p.name as project_name,
           r.file_path, r.file_name, r.mime_type
    FROM expenses e
    LEFT JOIN vendors v ON v.id = e.vendor_id
    LEFT JOIN expense_categories ec ON ec.id = e.category_id
    LEFT JOIN clients c ON c.id = e.client_id
    LEFT JOIN projects p ON p.id = e.project_id
    LEFT JOIN receipts r ON r.id = e.receipt_id
    WHERE e.id=? AND e.organization_id=?
');
$stmt->execute([$id, $orgId]);
$e = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$e) { header('Location: /?page=financial/expenses-list'); exit; }

$hasReceipt = !empty($e['file_path']);
$isPdf = $hasReceipt && strtolower(pathinfo($e['file_path'], PATHINFO_EXTENSION)) === 'pdf';
$fileUrl = '';
if ($hasReceipt) {
    $fileParam = str_replace('/src/uploads/', '', $e['file_path']);
    $fileUrl = '/?page=serve-upload&file=' . urlencode($fileParam);
}
$paymentLabels = ['cash' => 'Cash', 'check' => 'Check', 'card' => 'Credit/Debit Card', 'bank_transfer' => 'Bank Transfer', 'paypal' => 'PayPal', 'venmo' => 'Venmo', 'other' => 'Other'];
?>

<div style="max-width:1200px;margin:0 auto;padding:24px">
  <div class="page-head">
    <h2>Expense Details</h2>
    <div class="flex">
      <a href="/?page=financial/expense-create&id=<?php echo $id; ?>" class="btn btn-sm">Edit</a>
      <a href="/?page=financial/expenses-list" class="btn btn-sm">Back to List</a>
    </div>
  </div>

  <?php if (!empty($_GET['created'])): ?><div class="alert alert-success">Expense created.</div><?php endif; ?>
  <?php if (!empty($_GET['updated'])): ?><div class="alert alert-success">Expense updated.</div><?php endif; ?>

  <div class="grid grid-2" style="gap:24px;align-items:start">
    <!-- Receipt Preview -->
    <?php if ($hasReceipt): ?>
    <div class="card">
      <h3 class="card-title" style="margin-bottom:12px">Receipt</h3>
      <?php if ($isPdf): ?>
        <iframe src="<?php echo htmlspecialchars($fileUrl); ?>" style="width:100%;height:600px;border:0"></iframe>
      <?php else: ?>
        <img src="<?php echo htmlspecialchars($fileUrl); ?>" alt="Receipt" style="width:100%;border-radius:var(--radius-sm)">
      <?php endif; ?>
      <div style="margin-top:8px"><a href="<?php echo htmlspecialchars($fileUrl); ?>&download=1" class="btn btn-sm">Download</a></div>
    </div>
    <?php else: ?>
    <div class="card" style="text-align:center;padding:48px">
      <p class="muted">No receipt attached.</p>
      <a href="/?page=financial/receipt-upload" class="btn btn-sm">Upload Receipt</a>
    </div>
    <?php endif; ?>

    <!-- Expense Details -->
    <div class="card">
      <h3 class="card-title" style="margin-bottom:16px">Expense Information</h3>

      <div class="field"><label class="label-muted">Date</label><div class="font-600"><?php echo htmlspecialchars($e['expense_date']); ?></div></div>
      <div class="field"><label class="label-muted">Vendor</label><div><?php echo htmlspecialchars($e['vendor_name'] ?? '—'); ?></div></div>
      <div class="field"><label class="label-muted">Category</label><div><?php echo htmlspecialchars($e['category_name'] ?? '—'); ?></div></div>
      <div class="field"><label class="label-muted">Description</label><div><?php echo htmlspecialchars($e['description'] ?? '—'); ?></div></div>

      <div class="grid grid-3" style="margin:12px 0">
        <div class="card-tight" style="background:var(--surface-2);border-radius:var(--radius-sm);padding:12px">
          <div class="muted text-sm">Amount</div>
          <div class="font-600" style="font-size:18px">$<?php echo number_format((float)$e['amount'], 2); ?></div>
        </div>
        <div class="card-tight" style="background:var(--surface-2);border-radius:var(--radius-sm);padding:12px">
          <div class="muted text-sm">Tax</div>
          <div class="font-600" style="font-size:18px"><?php echo $e['tax_amount'] !== null ? '$' . number_format((float)$e['tax_amount'], 2) : '—'; ?></div>
        </div>
        <div class="card-tight" style="background:var(--surface-2);border-radius:var(--radius-sm);padding:12px">
          <div class="muted text-sm">Total</div>
          <div class="font-600" style="font-size:18px">$<?php echo number_format((float)($e['total_amount'] ?? $e['amount']), 2); ?></div>
        </div>
      </div>

      <div class="field"><label class="label-muted">Payment Method</label><div><?php echo htmlspecialchars($paymentLabels[$e['payment_method']] ?? $e['payment_method'] ?? '—'); ?></div></div>
      <div class="field"><label class="label-muted">Reference Number</label><div><?php echo htmlspecialchars($e['reference_number'] ?? '—'); ?></div></div>

      <div class="grid grid-2" style="margin:12px 0">
        <div><label class="label-muted">Status</label><span class="status-pill status-pill--<?php echo htmlspecialchars(strtolower($e['status'])); ?>"><?php echo htmlspecialchars($e['status']); ?></span></div>
        <div><label class="label-muted">Billable</label><?php echo $e['is_billable'] ? '<span class="status-pill status-pill--active">Yes</span>' : 'No'; ?></div>
      </div>

      <?php if ($e['is_billable']): ?>
      <div class="field"><label class="label-muted">Client</label><div><?php echo htmlspecialchars($e['client_name'] ?? '—'); ?></div></div>
      <div class="field"><label class="label-muted">Project</label><div><?php echo htmlspecialchars($e['project_name'] ?? '—'); ?></div></div>
      <?php endif; ?>

      <div class="grid grid-2" style="margin:12px 0">
        <div><label class="label-muted">Tax Deductible</label><?php echo $e['is_tax_deductible'] ? 'Yes' : 'No'; ?></div>
        <div><label class="label-muted">Reimbursed</label><?php echo $e['is_reimbursed'] ? 'Yes' : 'No'; ?></div>
      </div>

      <?php if (!empty($e['notes'])): ?>
      <div class="field"><label class="label-muted">Notes</label><div><?php echo htmlspecialchars($e['notes']); ?></div></div>
      <?php endif; ?>

      <!-- Actions -->
      <div class="flex" style="margin-top:20px;flex-wrap:wrap">
        <?php if (!$e['is_reimbursed'] && $e['is_billable']): ?>
        <form method="post" action="/?page=financial/expense-handler" style="display:inline">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="action" value="mark_reimbursed">
          <input type="hidden" name="id" value="<?php echo $id; ?>">
          <button type="submit" class="btn btn-sm">Mark Reimbursed</button>
        </form>
        <?php endif; ?>
        <?php if (!$e['is_reconciled']): ?>
        <form method="post" action="/?page=financial/expense-handler" style="display:inline">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="action" value="mark_reconciled">
          <input type="hidden" name="id" value="<?php echo $id; ?>">
          <button type="submit" class="btn btn-sm">Mark Reconciled</button>
        </form>
        <?php endif; ?>
        <form method="post" action="/?page=financial/expense-handler" style="display:inline" onsubmit="return confirm('Delete this expense?')">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?php echo $id; ?>">
          <button type="submit" class="btn btn-sm" style="background:#fef2f2;border-color:#fca5a5;color:#991b1b">Delete</button>
        </form>
      </div>
    </div>
  </div>
</div>