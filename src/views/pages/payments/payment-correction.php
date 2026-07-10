<?php

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/invoice_numbers.php';
require_once __DIR__ . '/../../../utils/payment_accounting.php';
require_once __DIR__ . '/../../../utils/payment_corrections.php';

$paymentId = (int)($_GET['payment_id'] ?? 0);
$userId = (int)($_SESSION['user']['id'] ?? 0);
$payment = null;
$targets = [];
$manualCandidates = [];
$pageError = trim((string)($_GET['error'] ?? ''));

try {
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
        } elseif ((float)$payment['disputed_amount'] > 0.005) {
            $pageError = $pageError ?: 'A disputed payment cannot be reallocated.';
        } elseif ((float)$payment['refunded_amount'] > 0.005 && !payment_is_processor_backed($payment)) {
            $pageError = $pageError ?: 'A refunded manual payment cannot be reallocated.';
        } else {
            $targetStmt = $pdo->prepare('
                SELECT i.id, i.doc_number, i.invoice_type, i.status, i.total, i.amount_paid, i.balance_due,
                       i.contract_id, i.document_date
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
                  AND COALESCE(p.processor_provider, "") = ""
                  AND COALESCE(p.processor_payment_id, "") = ""
                  AND p.project_invoice_payment_id IS NULL
                  AND p.refunded_amount <= 0.005
                  AND p.disputed_amount <= 0.005
                  AND i.collection_mode = "direct"
                  AND i.status NOT IN ("draft", "void", "cancelled")
                ORDER BY p.payment_date DESC, p.id DESC
                LIMIT 250
            ');
            $manualStmt->execute([
                (int)$payment['client_id'],
                $payment['organization_id'] !== null ? (int)$payment['organization_id'] : null,
            ]);
            foreach ($manualStmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
                $candidate['invoice_label'] = pa_invoice_label_from_row($candidate);
                $manualCandidates[] = $candidate;
            }
        }
    }
} catch (Throwable $e) {
    @error_log('[payment-correction] Page load failed for payment ' . $paymentId . ': ' . $e->getMessage());
    $pageError = 'The payment correction page could not be loaded. No accounting data was changed. Please try again after updating Project Alpha.';
    $targets = [];
}

$processorFee = $payment && $payment['processor_fee_amount'] !== null
    ? (float)$payment['processor_fee_amount']
    : 0.0;
$netReceived = $payment ? payment_accounting_net_income($payment) : 0.0;
$hasLocalRefund = $payment && (float)$payment['refunded_amount'] > 0.005;
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
      <strong>No money moves in this workflow.</strong> PA keeps the existing Stripe transaction and processor IDs. The invoice receives the gross client payment; Stripe fees remain separately reported as processing expense and net received.
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px">
      <div style="padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:#fff"><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Payment</div><div style="font-size:20px;font-weight:700">#<?php echo (int)$payment['id']; ?> &middot; $<?php echo number_format((float)$payment['amount'], 2); ?></div></div>
      <div style="padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:#fff"><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Currently applied to</div><div style="font-size:20px;font-weight:700"><?php echo htmlspecialchars(pa_invoice_label_from_row($payment)); ?></div></div>
      <div style="padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:#fff"><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Processor fee</div><div style="font-size:20px;font-weight:700">$<?php echo number_format($processorFee, 2); ?></div></div>
      <div style="padding:14px;border:1px solid #e5e7eb;border-radius:10px;background:#fff"><div style="font-size:12px;color:var(--muted);text-transform:uppercase">Current net received</div><div style="font-size:20px;font-weight:700">$<?php echo number_format($netReceived, 2); ?></div></div>
    </div>

    <?php if (!$targets): ?>
      <div style="padding:12px;border-radius:8px;background:#fffbeb;color:#92400e;border:1px solid #fde68a">No other finalized direct invoices are available for this client.</div>
    <?php else: ?>
      <form method="post" action="/?page=payments/payment-correct" style="display:grid;gap:16px;padding:18px;border:1px solid #e5e7eb;border-radius:10px;background:#fff" onsubmit="return confirm('Correct this payment allocation? No Stripe refund or new charge will be created.');">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="payment_id" value="<?php echo (int)$payment['id']; ?>">

        <?php if ($hasLocalRefund): ?>
          <label style="display:flex;gap:10px;align-items:flex-start;padding:14px;border:1px solid #fcd34d;border-radius:8px;background:#fffbeb;color:#92400e">
            <input type="checkbox" name="clear_local_refund" value="1" required style="margin-top:3px">
            <span><strong>Recover the incorrect $<?php echo number_format((float)$payment['refunded_amount'], 2); ?> local refund record</strong><br><span style="font-size:13px">Check this only if no money was actually refunded in Stripe. Before changing PA, the server will query Stripe and stop if Stripe reports any real refund.</span></span>
          </label>
        <?php endif; ?>

        <label>
          <div style="font-weight:600;margin-bottom:6px">Target invoice</div>
          <select name="target_invoice_id" id="correctionTargetInvoice" required style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;background:#fff">
            <option value="">Select the correct invoice</option>
            <?php foreach ($targets as $target): ?>
              <option value="<?php echo (int)$target['id']; ?>">
                <?php echo htmlspecialchars(pa_invoice_label_from_row($target)); ?> &middot; <?php echo htmlspecialchars(ucfirst((string)$target['status'])); ?> &middot; $<?php echo number_format((float)$target['total'], 2); ?> total &middot; $<?php echo number_format((float)$target['balance_due'], 2); ?> due
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <div>
          <div style="font-weight:600;margin-bottom:6px">Duplicate manual payments to reverse</div>
          <div id="correctionDuplicatePayments" style="display:grid;gap:8px;padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb"></div>
          <div style="font-size:13px;color:var(--muted);margin-top:5px">Select every duplicate cash/manual entry on both the accidental source invoice and the correct target invoice. They remain in the audit trail as reversed and are hidden from the normal payment list.</div>
        </div>

        <label>
          <div style="font-weight:600;margin-bottom:6px">Correction reason</div>
          <textarea name="reason" maxlength="500" required rows="3" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;box-sizing:border-box">Duplicate invoice created after the original recurring invoice email was delayed; restore and reallocate the real Stripe payment to the correct recurring invoice.</textarea>
        </label>

        <label style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid #e5e7eb;border-radius:8px">
          <input type="checkbox" name="void_source" value="1" checked style="margin-top:3px">
          <span><strong>Void the original invoice after moving its payment</strong><br><span style="font-size:13px;color:var(--muted)">The accidental invoice remains only in audit history as void; it cannot be paid or sent again.</span></span>
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
  var container = document.getElementById('correctionDuplicatePayments');
  var sourceInvoiceId = <?php echo (int)$payment['invoice_id']; ?>;
  var candidates = <?php echo json_encode($manualCandidates, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

  function renderCandidates() {
    var targetInvoiceId = Number(target.value || 0);
    var relevant = candidates.filter(function (item) {
      return Number(item.invoice_id) === sourceInvoiceId || (targetInvoiceId > 0 && Number(item.invoice_id) === targetInvoiceId);
    });
    container.innerHTML = '';
    if (!relevant.length) {
      container.textContent = targetInvoiceId > 0
        ? 'No eligible duplicate manual payments were found on the source or target invoice.'
        : 'Select the target invoice to show eligible manual payments.';
      return;
    }
    relevant.forEach(function (item) {
      var label = document.createElement('label');
      label.style.cssText = 'display:flex;gap:10px;align-items:flex-start;padding:9px;background:#fff;border:1px solid #e5e7eb;border-radius:7px';
      var checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.name = 'replacement_payment_ids[]';
      checkbox.value = item.id;
      checkbox.style.marginTop = '3px';
      var text = document.createElement('span');
      var location = Number(item.invoice_id) === sourceInvoiceId ? 'Source' : 'Target';
      text.textContent = location + ' ' + item.invoice_label + ' - Payment #' + item.id + ' - $' + Number(item.amount).toFixed(2) + ' - ' + String(item.payment_method).replaceAll('_', ' ') + ' - ' + item.payment_date;
      label.appendChild(checkbox);
      label.appendChild(text);
      container.appendChild(label);
    });
  }

  target.addEventListener('change', renderCandidates);
  renderCandidates();
})();
</script>
<?php endif; ?>
