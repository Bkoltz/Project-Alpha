<?php
// src/views/pages/payments-list.php
require_once __DIR__ . '/../../config/db.php';
$rows = $pdo->query('SELECT p.id, p.amount, p.status, p.created_at, i.id AS invoice_id, c.name AS client FROM payments p JOIN invoices i ON i.id=p.invoice_id JOIN clients c ON c.id=i.client_id ORDER BY p.created_at DESC LIMIT 50')->fetchAll();
?>
<section>
  <h2>Payments</h2>
  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">ID</th>
          <th style="padding:10px">Invoice</th>
          <th style="padding:10px">Client</th>
          <th style="padding:10px">Amount</th>
          <th style="padding:10px">Status</th>
          <th style="padding:10px">Created</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr style="border-top:1px solid #f3f4f6">
            <td style="padding:10px">#<?php echo (int)$r['id']; ?></td>
            <td style="padding:10px">Invoice #<?php echo (int)$r['invoice_id']; ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['client']); ?></td>
            <td style="padding:10px">$<?php echo number_format((float)$r['amount'], 2); ?></td>
            <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($r['status']); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($r['created_at']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
