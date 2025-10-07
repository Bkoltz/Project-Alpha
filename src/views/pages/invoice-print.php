<?php
// src/views/pages/invoice-print.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/format.php';
require_once __DIR__ . '/../../utils/csrf.php';
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT i.*, c.name client_name, c.organization client_org, c.email client_email, c.phone client_phone, c.address_line1, c.address_line2, c.city, c.state, c.postal, c.country FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?');
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
// Load project notes if available
$projectNotes = null;
if (!empty($inv['project_code'])) {
  $pm = $pdo->prepare('SELECT notes FROM project_meta WHERE project_code=?');
  $pm->execute([$inv['project_code']]);
  $projectNotes = $pm->fetchColumn();
}
?>
<section>
  <div class="no-print" style="display:flex;gap:8px;margin-bottom:8px">
    <a href="javascript:history.back()" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Back</a>
    <button onclick="window.print()" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Print</button>
    <form method="post" action="/?page=email-send" style="display:inline">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="invoice">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
      <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Email</button>
    </form>
  </div>
  <div style=\"display:flex;justify-content:space-between;align-items:center;margin-bottom:8px\">
    <?php $brand = $appConfig['brand_name'] ?? 'Project Alpha'; $logo = $appConfig['logo_path'] ?? null; ?>
    <div style="font-weight:700;font-size:18px"><?php echo htmlspecialchars($brand); ?></div>
    <div><?php if ($logo): ?><img src="<?php echo htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars($brand); ?>" style="height:40px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px"><?php endif; ?></div>
  </div>
  <div style="display:flex;justify-content:space-between;align-items:center;margin:6px 0 12px">
    <div style="font-weight:700">Invoice I-<?php echo htmlspecialchars($inv['doc_number'] ?? $inv['id']); ?></div>
    <div><?php if (!empty($inv['project_code'])): ?>Project <?php echo htmlspecialchars($inv['project_code']); ?><?php endif; ?></div>
  </div>

  <div style="display:flex;gap:24px;margin-bottom:16px">
    <div style="flex:1">
      <div style="font-weight:600">From</div>
      <div><?php echo htmlspecialchars($fromName); ?></div>
      <pre style="white-space:pre-wrap;margin:0"><?php echo htmlspecialchars($fromAddress); ?><?php echo $fromPhone?"\n".format_phone($fromPhone):''; ?><?php echo $fromEmail?"\n".$fromEmail:''; ?></pre>
    </div>
    <div style="flex:1">
      <div style=\"font-weight:600\">To</div>
      <div><?php echo htmlspecialchars($inv['client_name']); ?></div>
      <?php if (!empty($inv['client_org'])): ?><div style=\"color:#374151; font-size: 14px; margin-top:2px\"><?php echo htmlspecialchars($inv['client_org']); ?></div><?php endif; ?>
      <pre style=\"white-space:pre-wrap;margin:0\"><?php echo htmlspecialchars(trim(($inv['address_line1'] ?? '') . (empty($inv['address_line2'])?'':'\\n'.$inv['address_line2']) . (empty($inv['city'])&&empty($inv['state'])&&empty($inv['postal'])?'':'\\n'.trim(($inv['city'] ?? '').' '.($inv['state'] ?? '').' '.($inv['postal'] ?? ''))) . (empty($inv['country'])?'':'\\n'.$inv['country']))); ?><?php echo !empty($inv['client_phone'])?"\\n".format_phone($inv['client_phone']):''; ?><?php echo !empty($inv['client_email'])?"\\n".$inv['client_email']:''; ?></pre>
    </div>
</div>

  <?php if (!empty($projectNotes)): ?>
  <div style="margin:12px 0;padding:10px;border:1px solid #eee;border-radius:8px;background:#f8fafc">
    <div style="font-weight:600;margin-bottom:6px">Project Notes</div>
    <pre style="white-space:pre-wrap;margin:0"><?php echo htmlspecialchars($projectNotes); ?></pre>
  </div>
  <?php endif; ?>


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
<style>.no-print{display:flex} @media print {.no-print{display:none} .side-nav,.nav-footer{display:none} .main-content{margin-left:0} body{background:#fff}}</style>
