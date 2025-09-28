<?php
// src/views/pages/payments-create.php
require_once __DIR__ . '/../../config/db.php';
$invoices = $pdo->query("SELECT i.id, i.total, i.status, c.name client FROM invoices i JOIN clients c ON c.id=i.client_id ORDER BY i.created_at DESC LIMIT 100")->fetchAll();
?>
<section>
  <h2>Record Payment</h2>
  <form method="post" action="/?page=payments-create" style="display:grid;gap:12px;max-width:520px">
    <label>
      <div>Invoice</div>
      <select required name="invoice_id" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <option value="">Select invoice...</option>
        <?php foreach ($invoices as $i): ?>
          <option value="<?php echo (int)$i['id']; ?>">#<?php echo (int)$i['id']; ?> · <?php echo htmlspecialchars($i['client']); ?> · $<?php echo number_format((float)$i['total'],2); ?> (<?php echo htmlspecialchars($i['status']); ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <div>Amount ($)</div>
      <input required type="number" step="0.01" name="amount" placeholder="0.00" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Method</div>
      <input type="text" name="method" placeholder="card / cash / bank_transfer" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Save Payment</button>
  </form>
</section>
