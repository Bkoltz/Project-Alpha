<?php
// src/views/pages/contract-print.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
$id = (int)($_GET['id'] ?? 0);
$c = $pdo->prepare('SELECT co.*, cl.name client_name, cl.organization client_org, cl.email client_email, cl.phone client_phone, cl.address_line1, cl.address_line2, cl.city, cl.state, cl.postal, cl.country FROM contracts co JOIN clients cl ON cl.id=co.client_id WHERE co.id=?');
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
// Resolve terms: project-level terms override contract terms override app settings
$termsText = '';
if (!empty($contract['project_code'])) {
  try {
    $pm = $pdo->prepare('SELECT terms FROM project_meta WHERE project_code=?');
    $pm->execute([$contract['project_code']]);
    $pt = (string)$pm->fetchColumn();
    if (trim($pt) !== '') { $termsText = trim($pt); }
  } catch (Throwable $e) { /* ignore */ }
}
if ($termsText === '') { $termsText = trim((string)($contract['terms'] ?? '')); }
if ($termsText === '') { $termsText = trim((string)($appConfig['terms'] ?? '')); }
?>
<section>
  <div class="doc-type" style="text-align:center;font-weight:700;font-size:22px;margin-bottom:6px">Contract</div>
  <?php if (!defined('PDF_MODE')): ?>
  <div class="no-print" style="display:flex;gap:8px;margin-bottom:8px">
    <a href="javascript:history.back()" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">Back</a>
    <a href="/?page=contract-pdf&id=<?php echo (int)$id; ?>" target="_blank" rel="noopener" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">Download PDF</a>
    <form method="post" action="/?page=email-send" style="display:inline">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="contract">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
      <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">Email</button>
    </form>
  </div>
  <?php endif; ?>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
    <?php $brand = $appConfig['brand_name'] ?? 'Project Alpha'; $logo = $appConfig['logo_path'] ?? null; ?>
    <div>
      <div style="font-weight:700;font-size:20px"><?php echo htmlspecialchars($brand); ?></div>
      <?php if (!empty($contract['project_code'])): ?>
        <div style="color:#374151;font-size:13px;margin-top:2px">Project: <?php echo htmlspecialchars($contract['project_code']); ?></div>
      <?php endif; ?>
    </div>
    <div><?php if ($logo): ?><img src="<?php echo htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars($brand); ?>" style="height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px"><?php endif; ?></div>
  </div>
  <div style="display:flex;gap:24px;margin:12px 0 16px">
    <div style="flex:1">
      <div style="font-weight:600">From</div>
      <?php 
        $fromCompany = $appConfig['brand_name'] ?? 'Project Alpha';
        $fromNameLine = trim((string)($fromName ?? ''));
        $fromLines = [];
        if ($fromNameLine !== '') { $fromLines[] = $fromNameLine; }
        $fromLines[] = $fromCompany;
        $addr1 = trim((string)($appConfig['from_address_line1'] ?? ''));
        $addr2 = trim((string)($appConfig['from_address_line2'] ?? ''));
        if ($addr1 !== '') { $fromLines[] = $addr1; }
        if ($addr2 !== '') { $fromLines[] = $addr2; }
        $city = trim((string)($appConfig['from_city'] ?? ''));
        $state = trim((string)($appConfig['from_state'] ?? ''));
        $postal = trim((string)($appConfig['from_postal'] ?? ''));
        $parts = [];
        if ($city !== '') { $parts[] = $city; }
        if ($state !== '') { $parts[] = $state; }
        if ($postal !== '') { $parts[] = $postal; }
        $cityLine = implode(', ', $parts);
        if ($cityLine !== '') { $fromLines[] = $cityLine; }
      ?>
      <div><?php foreach ($fromLines as $ln) { echo '<div>'.htmlspecialchars($ln).'</div>'; } ?></div>
      <?php if ($fromPhone || $fromEmail): ?>
        <div style="margin-top:6px;color:#4b5563;font-size:13px">
          <?php if ($fromPhone): ?><div><?php echo format_phone($fromPhone); ?></div><?php endif; ?>
          <?php if ($fromEmail): ?><div><?php echo htmlspecialchars($fromEmail); ?></div><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <div style="flex:1">
      <div style="font-weight:600">To</div>
      <?php 
        $toLines = [];
        if (!empty($contract['client_name'])) { $toLines[] = (string)$contract['client_name']; }
        if (!empty($contract['client_org'])) { $toLines[] = (string)$contract['client_org']; }
        if (!empty($contract['address_line1'])) { $toLines[] = (string)$contract['address_line1']; }
        if (!empty($contract['address_line2'])) { $toLines[] = (string)$contract['address_line2']; }
        $c = trim((string)($contract['city'] ?? ''));
        $s = trim((string)($contract['state'] ?? ''));
        $p = trim((string)($contract['postal'] ?? ''));
        $parts2 = [];
        if ($c !== '') { $parts2[] = $c; }
        if ($s !== '') { $parts2[] = $s; }
        if ($p !== '') { $parts2[] = $p; }
        $cityStatePostal = implode(', ', $parts2);
        if ($cityStatePostal !== '') { $toLines[] = $cityStatePostal; }
      ?>
      <div><?php foreach ($toLines as $ln) { echo '<div>'.htmlspecialchars($ln).'</div>'; } ?></div>
      <?php if (!empty($contract['client_phone']) || !empty($contract['client_email'])): ?>
        <div style="margin-top:6px;color:#4b5563;font-size:13px">
          <?php if (!empty($contract['client_phone'])): ?><div><?php echo format_phone($contract['client_phone']); ?></div><?php endif; ?>
          <?php if (!empty($contract['client_email'])): ?><div><?php echo htmlspecialchars($contract['client_email']); ?></div><?php endif; ?>
        </div>
      <?php endif; ?>
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
<style>
  .no-print{display:flex}
  .print-footer{display:none}
  @media print {
    .no-print{display:none !important}
    .side-nav,.nav-footer{display:none}
    .main-content{margin-left:0}
    body{background:#fff}
    .print-footer{display:block; position:fixed; bottom:6px; left:12px; color:#374151; font-size:12px}
  }
</style>
<div class="print-footer">Powered by Project Alpha</div>
