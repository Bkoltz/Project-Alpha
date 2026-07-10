<?php

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/invoice_numbers.php';

$paymentId = (int)($_GET['payment_id'] ?? 0);
$userId = (int)($_SESSION['user']['id'] ?? 0);
$payment = null;
$targets = [];
$manualCandidates = [];
$pageError = trim((string)($_GET['error'] ?? ''));

if ($paymentId <= 0 || !can_access_record($pdo, 'payments', $paymentId, $userId)) {
    $pageError = $pageError ?: 'Payment not found or permission denied.';
} else {
    $stmt = $pdo->prepare('
        SELECT p.*, i.doc_number, i.invoice_type, i.status AS invoice_status,
               i.collection_mode, c.name AS client_name
        FROM payments p
        JOIN invoices i ON i.id = p.invoice_id
        JOIN clients c ON c.id = p.client_id
        WHERE p.id = ?
    ');
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$payment) {
        $pageError = $pageError ?: 'This payment is not linked to an invoice.';
    } elseif (strtolower((string)$payment['status']) !== 'succeeded') {
        $pageError = $pageError ?: 'Only a successful payment can be corrected.';
    } elseif (!empty($payment['project_invoice_payment_id']) || (string)$payment['collection_mode'] !== 'direct') {
        $pageError = $pageError ?: 'Project invoice payments must be corrected from the project invoice workflow.';
    } elseif ((float)$payment['refunded_amount'] > 0.005 || (float)$payment['disputed_amount'] > 0.005) {
        $pageError = $pageError ?: 'Refunded or disputed payments cannot be reallocated.';
    } else {
        $targetStmt = $pdo->prepare('
            SELECT i.id, i.doc_number, i.invoice_type, i.status, i.total, i.amount_paid, i.balance_due,
                   i.contract_id, i.issue_date
            FROM invoices i
            WHERE i.client_id = ?
              AND i.id <> ?
              AND i.organization_id <=> ?
              AND i.collection_mode = "direct"
              AND i.status NOT IN ("draft", "void", "cancelled")
              AND i.finalized_at IS NOT NULL
            ORDER BY i.id DESC
            LIMIT 250
        ');
        $targetStmt->execute([
            (int)$payment['client_id'],
            (int)$payment['invoice_id'],
            $payment['organization_id'] !== null ? (int)$payment['organization_id'] : null,
        ]);
        $targets = $targetStmt->fetchAll(PDO::FETCH_ASSOC);

        $manualStmt = $pdo->prepare('
            SELECT p.id, p.invoice_id, p.amount, p.payment_method, p.payment_date,
                   i.doc_number, i.invoice_type
            FROM payments p
            JOIN invoices i ON i.id = p.invoice_id
            WHERE p.client_id = ?
              AND p.organization_id <=> ?
              AND p.status = "succeeded"
              AND p.payment_method <> "stripe"
              AND p.stripe_payment_intent_id IS NULL
              AND p.stripe_session_id IS NULL
              AND p.processor_provider IS NULL
              AND p.processor_payment_id IS NULL
              AND p.project_invoice_payment_id IS NULL
              AND p.refunded_amount <= 0.005
              AND p.disputed_amount <= 0.005
              AND p.invoice_id <> ?
              AND i.collection_mode = "direct"
              AND i.status NOT IN ("draft", "void", "cancelled")
            ORDER BY p.payment_date DESC, p.id DESC
            LIMIT 250
        ');
        $manualStmt->execute([
            (int)$payment['client_id'],
            $payment['organization_id'] !== null ? (int)$payment['organization_id'] : null,
            (int)$payment['invoice_id'],
        ]);
        foreach ($manualStmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            $candidate['invoice_label'] = pa_invoice_label_from_row($candidate);
            $manualCandidates[] = $candidate;
        }
    }
}
?>
<section style="max-width:900px">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div>
      <h2 style="margin:0">Correct payment allocation</h2>
      <p style="color:var(--muted);margin:6px 0 0">Move a real payment to the invoice it should have paid without sending or recording a refund.</p>
    </div>
    <a href="/?page=payments-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff">Back to payments</a>
  </div>

  <?php if ($pageError !== ''): ?>
    <div style="margin:16px 0;padding:12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars($pageError); ?></div>
  <?php endif; ?>

  <?php if ($payment && $pageError === ''): ?>
    <div style="margin:18px 0;padding:16px;border:1px solid #bfdbfe;border-radius:10px;background:#eff6ff;color:#1e3a8a">
      <strong>No money moves in this workflow.</strong> Project Alpha keeps the existing Stripe transaction and processor IDs. It only corrects which invoice receives the payment.
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:18px">
      <div style="padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:#fff"><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Payment</div><div style="font-size:20px;font-weight:700">#<?php echo (int)$payment['id']; ?> · $<?php echo number_format((float)$payment['amount'], 2); ?></div></div>
      <div style="padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:#fff"><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Currently applied to</div><div style="font-size:20px;font-weight:700"><?php echo htmlspecialchars(pa_invoice_label_from_row($payment)); ?></div></div>
      <div style="padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:#fff"><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Client</div><div style="font-size:20px;font-weight:700"><?php echo htmlspecialchars((string)$payment['client_name']); ?></div></div>
      <div style="padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:#fff"><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Method</div><div style="font-size:20px;font-weight:700"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$payment['payment_method']))); ?></div></div>
    </div>

    <?php if (!$targets): ?>
      <div style="padding:12px;border-radius:8px;background:#fffbeb;color:#92400e;border:1px solid #fde68a">No other finalized direct invoices are available for this client.</div>
    <?php else: ?>
      <form method="post" action="/?page=payments/payment-correct" style="display:grid;gap:16px;padding:18px;border:1px solid #e5e7eb;border-radius:10px;background:#fff" onsubmit="return confirm('Correct this payment allocation? No Stripe refund will be created.');">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="payment_id" value="<?php echo (int)$payment['id']; ?>">

        <label>
          <div style="font-weight:600;margin-bottom:6px">Target invoice</div>
          <select name="target_invoice_id" id="correctionTargetInvoice" required style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;background:#fff">
            <option value="">Select the correct invoice</option>
            <?php foreach ($targets as $target): ?>
              <option value="<?php echo (int)$target['id']; ?>">
                <?php echo htmlspecialchars(pa_invoice_label_from_row($target)); ?> · <?php echo htmlspecialchars(ucfirst((string)$target['status'])); ?> · $<?php echo number_format((float)$target['total'], 2); ?> total · $<?php echo number_format((float)$target['balance_due'], 2); ?> due
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          <div style="font-weight:600;margin-bottom:6px">Duplicate manual payment to reverse</div>
          <select name="replacement_payment_id" id="correctionReplacementPayment" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;background:#fff" disabled>
            <option value="">None</option>
          </select>
          <div style="font-size:13px;color:var(--muted);margin-top:5px">For your case, select the cash payment you manually recorded on the LTI. It will be marked “reversed,” not refunded or deleted.</div>
        </label>

        <label>
          <div style="font-weight:600;margin-bottom:6px">Correction reason</div>
          <textarea name="reason" maxlength="500" required rows="3" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;box-sizing:border-box">Duplicate invoice created after the original recurring invoice email was delayed; reallocate the real payment to the correct invoice.</textarea>
        </label>

        <label style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid #e5e7eb;border-radius:8px">
          <input type="checkbox" name="void_source" value="1" checked style="margin-top:3px">
          <span><strong>Void the original invoice after moving its payment</strong><br><span style="font-size:13px;color:var(--muted)">This is appropriate for an accidental duplicate invoice. The invoice remains in history as void.</span></span>
        </label>

        <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap">
          <a href="/?page=payments-list" style="padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;background:#fff">Cancel</a>
          <button type="submit" style="padding:10px 14px;border:1px solid #1d4ed8;border-radius:8px;background:#2563eb;color:#fff;font-weight:600">Correct allocation</button>
        </div>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php if ($payment && $pageError === '' && $targets): ?>
<script>
(function () {
  var target = document.getElementById('correctionTargetInvoice');
  var replacement = document.getElementById('correctionReplacementPayment');
  var candidates = <?php echo json_encode($manualCandidates, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  function refreshCandidates() {
    var invoiceId = String(target.value || '');
    replacement.innerHTML = '<option value="">None</option>';
    candidates.filter(function (item) { return String(item.invoice_id) === invoiceId; }).forEach(function (item) {
      var option = document.createElement('option');
      option.value = item.id;
      option.textContent = 'Payment #' + item.id + ' · $' + Number(item.amount).toFixed(2) + ' · ' + String(item.payment_method).replaceAll('_', ' ') + ' · ' + item.payment_date;
      replacement.appendChild(option);
    });
    replacement.disabled = invoiceId === '' || replacement.options.length === 1;
  }
  target.addEventListener('change', refreshCandidates);
  refreshCandidates();
})();
</script>
<?php endif; ?>
