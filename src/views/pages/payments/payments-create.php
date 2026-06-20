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
  <form id="paymentForm" method="post" action="/?page=payments/payments-create" style="display:grid;gap:12px;max-width:520px">
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
         $methods = (array)($appConfig['payment_methods'] ?? ['card','cash','bank_transfer']);
         $stripeConfigured = StripeService::isConfigured($appConfig);
    ?>
      <select name="method" id="paymentMethod" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <?php
          // Normalize method labels for display vs value
          $methodMap = [
            'Card' => ['card', '💳 Card'],
            'Cash' => ['cash', '💵 Cash'],
            'Bank Transfer' => ['bank_transfer', '🏦 Bank Transfer'],
            'Check' => ['check', '📄 Check'],
            'Stripe' => ['stripe', '💳 Credit Card (Stripe)'],
          ];
          $addedStripe = false;
          foreach ($methods as $m):
            $key = trim($m);
            $lower = strtolower($key);
            if ($lower === 'stripe') { $addedStripe = true; }
            if (isset($methodMap[$key])):
              $val = $methodMap[$key][0];
              $label = $methodMap[$key][1];
            else:
              $val = $lower;
              $label = htmlspecialchars($key);
            endif;
        ?>
          <option value="<?php echo $val; ?>"><?php echo $label; ?></option>
        <?php endforeach; ?>
        <?php if ($stripeConfigured && !$addedStripe): ?>
          <option value="stripe">💳 Credit Card (Stripe)</option>
        <?php endif; ?>
      </select>
    </label>
    <div id="stripeNotice" style="display:none;padding:12px;background:#e6f4ff;border:1px solid #0284c7;border-radius:8px;font-size:14px">
      <strong>Stripe Checkout:</strong> You will be redirected to Stripe to collect the card payment.
    </div>
    <div id="checkNumberField" style="display:none">
      <label>
        <div>Check Number</div>
        <input type="text" name="check_number" id="checkNumberInput" placeholder="Enter check number" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
    </div>
    <label>
      <div>Notes</div>
      <textarea name="notes" rows="3" placeholder="Optional payment notes" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></textarea>
    </label>
    <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Save Payment</button>
  </form>
</section>

<script>
// Handle Stripe submission: submit directly to stripe-charge in a new tab
document.getElementById('paymentForm').addEventListener('submit', function(e) {
    var methodSelect = document.getElementById('paymentMethod');
    var method = methodSelect.value.toLowerCase();
    if (method === 'stripe') {
        e.preventDefault();
        var invoiceId = document.getElementById('invoiceSelect').value;
        var amount = document.getElementById('amountInput').value;
        if (!invoiceId || !amount) {
            alert('Please select an invoice and enter an amount');
            return;
        }
        var csrf = this.querySelector('input[name="csrf"]').value;
        // Open Stripe checkout in new tab
        var stripeUrl = '/?page=stripe-charge&invoice_id=' + encodeURIComponent(invoiceId) + '&amount=' + encodeURIComponent(amount);
        window.open(stripeUrl, '_blank');
        // Redirect current page to payments list
        window.location.href = '/?page=payments/payments-list';
    }
});
</script>

<script src="/assets/js/payments-create-logic.js" defer></script>
