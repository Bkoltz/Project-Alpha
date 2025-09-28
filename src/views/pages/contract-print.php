<?php
// src/views/pages/contract-print.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
$id = (int)($_GET['id'] ?? 0);
$c = $pdo->prepare('SELECT co.*, cl.name client_name, cl.email client_email, cl.phone client_phone, cl.address_line1, cl.address_line2, cl.city, cl.state, cl.postal, cl.country FROM contracts co JOIN clients cl ON cl.id=co.client_id WHERE co.id=?');
$c->execute([$id]);
$contract = $c->fetch(PDO::FETCH_ASSOC);
if(!$contract){ echo '<p>Contract not found</p>'; return; }
$items = $pdo->prepare('SELECT description, quantity, unit_price, line_total FROM contract_items WHERE contract_id=?');
$items->execute([$id]);
$items = $items->fetchAll();
$fromName = $appConfig['brand_name'] ?? 'Project Alpha';
$fromAddress = trim(($appConfig['from_address_line1'] ?? '')."\n".($appConfig['from_address_line2'] ?? '')."\n".($appConfig['from_city'] ?? '').' '.($appConfig['from_state'] ?? '').' '.($appConfig['from_postal'] ?? '')."\n".($appConfig['from_country'] ?? ''));
?>
<section>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <div>
      <div style="font-size:12px;color:#999">Page 1 of 2</div>
      <h2 style="margin:0">Contract #<?php echo (int)$contract['id']; ?></h2>
    </div>
    <button onclick="window.print()" style="padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">Print / Save PDF</button>
  </div>

  <div style="display:flex;gap:24px;margin-bottom:16px">
    <div style="flex:1">
      <div style="font-weight:600">From</div>
      <div><?php echo htmlspecialchars($fromName); ?></div>
      <pre style="white-space:pre-wrap;margin:0"><?php echo htmlspecialchars($fromAddress); ?></pre>
    </div>
    <div style="flex:1">
      <div style="font-weight:600">To</div>
      <div><?php echo htmlspecialchars($contract['client_name']); ?></div>
      <pre style="white-space:pre-wrap;margin:0"><?php echo htmlspecialchars(trim(($contract['address_line1'] ?? '')."\n".($contract['address_line2'] ?? '')."\n".($contract['city'] ?? '').' '.($contract['state'] ?? '').' '.($contract['postal'] ?? '')."\n".($contract['country'] ?? ''))); ?></pre>
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
        <td style="padding:10px">$<?php echo number_format($contract['subtotal'] ?? 0,2); ?></td>
      </tr>
      <tr>
        <td></td><td></td>
        <td style="padding:10px;font-weight:600">Discount</td>
        <td style="padding:10px">
          <?php if (($contract['discount_type'] ?? 'none')==='percent'): ?>
            <?php echo number_format($contract['discount_value'] ?? 0,2); ?>%
          <?php elseif (($contract['discount_type'] ?? 'none')==='fixed'): ?>
            $<?php echo number_format($contract['discount_value'] ?? 0,2); ?>
          <?php else: ?>
            $0.00
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td></td><td></td>
        <td style="padding:10px;font-weight:600">Tax</td>
        <td style="padding:10px"><?php echo number_format($contract['tax_percent'] ?? 0,2); ?>%</td>
      </tr>
      <tr>
        <td></td><td></td>
        <td style="padding:10px;font-weight:700">Total</td>
        <td style="padding:10px;font-weight:700">$<?php echo number_format($contract['total'] ?? 0,2); ?></td>
      </tr>
    </tbody>
  </table>

  <div style="page-break-after:always"></div>
  <div style="font-size:12px;color:#999">Page 2 of 2</div>
  <h3>Terms and Conditions</h3>
  <p class="lead">By signing, the client agrees to the scope, timeline, and payment schedule indicated in this contract. Additional terms can be customized later.</p>
  <ul>
    <li>Payment due NET 30 unless otherwise specified.</li>
    <li>Cancellation requires written notice.</li>
    <li>Work product ownership and usage rights per agreement.</li>
  </ul>

  <div style="margin-top:48px">
    <div style="height:80px;border-bottom:1px solid #ccc;width:360px"></div>
    <div style="color:#666">Signature (Client)</div>
  </div>
</section>
<style>@media print {.side-nav,.nav-footer{display:none} .main-content{margin-left:0} body{background:#fff}}</style>
