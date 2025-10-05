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
require_once __DIR__ . '/../../utils/format.php';
$fromName = ($appConfig['from_name'] ?? '') ?: ($appConfig['brand_name'] ?? 'Project Alpha');
$fromAddress = trim(($appConfig['from_address_line1'] ?? '')."\n".($appConfig['from_address_line2'] ?? '')."\n".($appConfig['from_city'] ?? '').' '.($appConfig['from_state'] ?? '').' '.($appConfig['from_postal'] ?? '')."\n".($appConfig['from_country'] ?? ''));
$fromPhone = $appConfig['from_phone'] ?? '';
$fromEmail = $appConfig['from_email'] ?? '';
$termsText = trim($contract['terms'] ?? ($appConfig['terms'] ?? ''));
?>
<section>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <div style="display:flex;align-items:center;gap:10px">
      <?php $brand = $appConfig['brand_name'] ?? 'Project Alpha'; $logo = $appConfig['logo_path'] ?? null; ?>
      <?php if ($logo): ?>
        <img src="<?php echo htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars($brand); ?>" style="height:36px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px">
      <?php endif; ?>
      <div>
        <div style="font-size:12px;color:#999">Page 1 of 2</div>
        <h2 style="margin:0">Contract C-<?php echo htmlspecialchars($contract['doc_number'] ?? $contract['id']); ?><?php if (!empty($contract['project_code'])) echo ' (Project '.htmlspecialchars($contract['project_code']).')'; ?></h2>
      </div>
      <span style="color:#64748b;font-weight:600;margin-left:8px"><?php echo htmlspecialchars($brand); ?></span>
    </div>
    <div>
      <a href="/?page=contracts-edit&id=<?php echo (int)$contract['id']; ?>" style="display:inline-block;min-width:140px;text-align:center;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff;margin-right:6px; font-size: small;">Edit</a>
      <form method="post" action="/?page=email-send" style="display:inline-block;margin-right:6px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="type" value="contract">
        <input type="hidden" name="id" value="<?php echo (int)$contract['id']; ?>">
        <button type="submit" style="min-width:140px;text-align:center;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff; font-size: small;">Email</button>
      </form>
      <button onclick="window.print()" style="display:inline-block;min-width:140px;text-align:center;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff; font-size: small;">Print / Save PDF</button>
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
  <?php if ($termsText !== ''): ?>
    <pre style="white-space:pre-wrap;background:#fff;padding:12px;border:1px solid #eee;border-radius:8px"><?php echo htmlspecialchars($termsText); ?></pre>
  <?php else: ?>
    <p class="lead">By signing, the client agrees to the scope, timeline, and payment schedule indicated in this contract. Additional terms can be customized later.</p>
    <ul>
      <li>Payment due NET 30 unless otherwise specified.</li>
      <li>Cancellation requires written notice.</li>
      <li>Work product ownership and usage rights per agreement.</li>
    </ul>
  <?php endif; ?>

  <div style="margin-top:48px">
    <div style="height:80px;border-bottom:1px solid #ccc;width:360px"></div>
    <div style="color:#666">Signature (Client)</div>
  </div>
</section>
<style>@media print {.side-nav,.nav-footer{display:none} .main-content{margin-left:0} body{background:#fff}}</style>
