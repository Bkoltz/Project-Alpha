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
  <form method="post" action="/?page=payments-create" style="display:grid;gap:12px;max-width:520px">
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
    <?php require_once __DIR__ . '/../../../config/app.php'; $methods = (array)($appConfig['payment_methods'] ?? ['card','cash','bank_transfer']); ?>
      <select name="method" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <?php foreach ($methods as $m): ?>
          <option value="<?php echo htmlspecialchars($m); ?>"><?php echo htmlspecialchars($m); ?></option>
        <?php endforeach; ?>
      </select>
      <!-- if the user selects check, we add a textbox for the user to enter the check number that was used to pay the invoice. -->
      <?php if(1): ?>Enter check number<?php endif; ?>
    </label>
    <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Save Payment</button>
  </form>
</section>
<script>
  (function(){
    var sel = document.getElementById('invoiceSelect');
    var amt = document.getElementById('amountInput');
    function update(){
      var opt = sel.options[sel.selectedIndex];
      if (!opt) return;
      var r = opt.getAttribute('data-remaining');
      if (r) { amt.value = r; }
    }
    sel.addEventListener('change', update);
    if (!amt.value) { update(); }
  })();
</script>
