<?php
// src/views/pages/invoice-print.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/format.php';
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT i.*, c.name client_name, c.email client_email, c.phone client_phone, c.address_line1, c.address_line2, c.city, c.state, c.postal, c.country FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?');
$st->execute([$id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if(!$inv){ echo '<p>Invoice not found</p>'; return; }
$items = $pdo->prepare('SELECT description, quantity, unit_price, line_total FROM invoice_items WHERE invoice_id=?');
$items->execute([$id]);
$items = $items->fetchAll();
$fromName = ($appConfig['from_name'] ?? '') ?: ($appConfig['brand_name'] ?? 'Project Alpha');
$fromAddress = trim(($appConfig['from_address_line1'] ?? '')."\n".($appConfig['from_address_line2'] ?? '')."\n".($appConfig['from_city'] ?? '').' '.($appConfig['from_state'] ?? '').' '.($appConfig['from_postal'] ?? '')."\n".($appConfig['from_country'] ?? ''));
$fromPhone = $appConfig['from_phone'] ?? '';
$fromEmail = $appConfig['from_email'] ?? '';
?>
<section>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h2 style="margin:0">Invoice #<?php echo (int)$inv['id']; ?></h2>
    <div>
      <a href="/?page=invoices-edit&id=<?php echo (int)$inv['id']; ?>" style="padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff;margin-right:6px">Edit</a>
      <button onclick="window.print()" style="padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">Print / Save PDF</button>
    </div>
  </div>

  <div style="display:flex;gap:24px;margin-bottom:16px">
    <div style="flex:1">
      <div style="font-weight:600">From</div>
      <div><?php echo htmlspecialchars($fromName); ?></div>
      <pre style="white-space:pre-wrap;margin:0"><?php echo htmlspecialchars($fromAddress); ?><?php echo $fromPhone?"\n".format_phone($fromPhone):''; ?><?php echo $fromEmail?"\n".$fromEmail:''; ?></pre>
    </div>
    <div style="flex:1">
      <div style="font-weight:600">To</div>
      <div><?php echo htmlspecialchars($inv['client_name']); ?></div>
      <pre style="white-space:pre-wrap;margin:0"><?php echo htmlspecialchars(trim(($inv['address_line1'] ?? '')."\n".($inv['address_line2'] ?? '')."\n".($inv['city'] ?? '').' '.($inv['state'] ?? '').' '.($inv['postal'] ?? '')."\n".($inv['country'] ?? ''))); ?></pre>
    </div>
  </div>

  <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
    <thead>
      <tr style="text-align:left;border-bottom:1px solid #eee">
        <th style="padding:10px">Description</th>
        <th style="padding:10px">Qty</th>
        <th style="padding:10px">Unit</th>
        <th style="padding:10px">Line Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr style="border-top:1px solid #f3f4f6">
        <td style="padding:10px"><?php echo htmlspecialchars($it['description']); ?></td>
        <td style="padding:10px"><?php echo number_format($it['quantity'],2); ?></td>
        <td style="padding:10px">$<?php echo number_format($it['unit_price'],2); ?></td>
        <td style="padding:10px">$<?php echo number_format($it['line_total'],2); ?></td>
      </tr>
      <?php endforeach; ?>
      <tr><td colspan="4" style="border-top:1px solid #eee"></td></tr>
      <tr>
        <td></td><td></td>
        <td style="padding:10px;font-weight:600">Subtotal</td>
        <td style="padding:10px">$<?php echo number_format($inv['subtotal'],2); ?></td>
      </tr>
      <tr>
        <td></td><td></td>
        <td style="padding:10px;font-weight:600">Discount</td>
        <td style="padding:10px">
          <?php if ($inv['discount_type']==='percent'): ?>
            <?php echo number_format($inv['discount_value'],2); ?>%
          <?php elseif ($inv['discount_type']==='fixed'): ?>
            $<?php echo number_format($inv['discount_value'],2); ?>
          <?php else: ?>
            $0.00
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td></td><td></td>
        <td style="padding:10px;font-weight:600">Tax</td>
        <td style="padding:10px"><?php echo number_format($inv['tax_percent'],2); ?>%</td>
      </tr>
      <tr>
        <td></td><td></td>
        <td style="padding:10px;font-weight:700">Total</td>
        <td style="padding:10px;font-weight:700">$<?php echo number_format($inv['total'],2); ?></td>
      </tr>
    </tbody>
  </table>
</section>
<style>@media print {.side-nav,.nav-footer{display:none} .main-content{margin-left:0} body{background:#fff}}</style>
