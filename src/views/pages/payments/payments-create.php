<?php
// src/views/pages/payments-create.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
$invoices = $pdo->query("SELECT i.id, i.total, COALESCE(p.paid,0) AS paid, i.status, c.name client FROM invoices i JOIN clients c ON c.id=i.client_id LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid FROM payments WHERE status='succeeded' GROUP BY invoice_id) p ON p.invoice_id=i.id WHERE i.status IN ('unpaid','partial') ORDER BY i.created_at DESC LIMIT 200")->fetchAll();
$pref = (int)($_GET['invoice_id'] ?? 0);
$prefAmount = '';
if ($pref > 0) {
  // compute remaining amount
  $st = $pdo->prepare("SELECT i.total, COALESCE(p.paid,0) AS paid FROM invoices i LEFT JOIN (
      SELECT invoice_id, SUM(amount) AS paid FROM payments WHERE status='succeeded' GROUP BY invoice_id
    ) p ON p.invoice_id=i.id WHERE i.id=?");
  $st->execute([$pref]);
  if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $remain = max(0, (float)$row['total'] - (float)$row['paid']);
    if ($remain > 0) { $prefAmount = number_format($remain, 2, '.', ''); }
  }
}
?>
<section>
  <h2>Record Payment</h2>
  <form method="post" action="/?page=payments/payments-create" style="display:grid;gap:12px;max-width:520px">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <label>
      <div>Invoice</div>
      <select required name="invoice_id" id="invoiceSelect" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <option value="">Select invoice...</option>
        <?php foreach ($invoices as $i): $remain = max(0, (float)$i['total'] - (float)$i['paid']); ?>
          <option value="<?php echo (int)$i['id']; ?>" data-remaining="<?php echo number_format($remain,2,'.',''); ?>" <?php echo $pref===(int)$i['id']?'selected':''; ?>>#<?php echo (int)$i['id']; ?> · <?php echo htmlspecialchars($i['client']); ?> · $<?php echo number_format((float)$i['total'],2); ?> (<?php echo htmlspecialchars($i['status']); ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <div>Amount ($)</div>
      <input required type="number" step="0.01" name="amount" id="amountInput" placeholder="0.00" value="<?php echo htmlspecialchars($prefAmount); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Method</div>
    <?php require_once __DIR__ . '/../../../config/app.php';
         require_once __DIR__ . '/../../../services/StripeService.php';
         require_once __DIR__ . '/../../../utils/payment_methods.php';
         $methods = pa_payment_methods_from_config($appConfig);
         $stripeConfigured = StripeService::isConfigured($appConfig);
    ?>
      <select name="method" id="paymentMethod" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <?php foreach ($methods as $m): ?>
          <?php if (($m['key'] ?? '') === 'stripe') { continue; } ?>
          <option value="<?php echo htmlspecialchars($m['key']); ?>"><?php echo htmlspecialchars($m['label']); ?></option>
        <?php endforeach; ?>
        <?php if ($stripeConfigured && pa_payment_methods_has($appConfig, 'stripe')): ?>
          <option value="stripe">💳 Credit Card (Stripe)</option>
        <?php endif; ?>
      </select>
    </label>
    <div id="stripeNotice" style="display:none;padding:12px;background:#e6f4ff;border:1px solid #0284c7;border-radius:8px;font-size:14px">
      <strong>Stripe Checkout:</strong> You will be redirected to Stripe to collect the card payment.
    </div>
    <div id="checkNumberField" style="display:none">
      <label>
        <div id="referenceLabel">Check Number</div>
        <input type="text" name="reference_number" id="checkNumberInput" placeholder="Enter check number" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </div>
    <label>
      <div>Notes</div>
      <textarea name="notes" rows="3" placeholder="Optional payment notes" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></textarea>
    </label>
    <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Save Payment</button>
  </form>
</section>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/payments-create-logic.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
