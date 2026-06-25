<?php
// src/views/pages/contract/long-term-contract-print.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf.php';
$id = (int)($_GET['id'] ?? 0);
$c = $pdo->prepare('SELECT ltc.*, cl.name client_name, o.name AS client_org, cl.email client_email, cl.phone client_phone, cl.address_line1, cl.address_line2, cl.city, cl.state, cl.postal_code, cl.country FROM contracts ltc JOIN clients cl ON cl.id=ltc.client_id LEFT JOIN organizations o ON o.id=cl.organization_id WHERE ltc.id=? AND ltc.contract_type="long_term"');
$c->execute([$id]);
$contract = $c->fetch(PDO::FETCH_ASSOC);
if(!$contract){ echo '<p>Long-term contract not found</p>'; return; }

// Get items if fixed_total pricing
$items = [];
if ($contract['pricing_type'] === 'fixed_total') {
    $itemsQuery = $pdo->prepare('SELECT item, description, quantity, unit_price, line_total FROM contract_items WHERE contract_id=?');
    $itemsQuery->execute([$id]);
    $items = $itemsQuery->fetchAll();
}

require_once __DIR__ . '/../../../utils/format.php';
$fromName = ($appConfig['from_name'] ?? '') ?: ($appConfig['brand_name'] ?? 'Project Alpha');
$fromAddress = trim(($appConfig['from_address_line1'] ?? '')."\n".($appConfig['from_address_line2'] ?? '')."\n".($appConfig['from_city'] ?? '').' '.($appConfig['from_state'] ?? '').' '.($appConfig['from_postal'] ?? '')."\n".($appConfig['from_country'] ?? ''));
$fromPhone = $appConfig['from_phone'] ?? '';
$fromEmail = $appConfig['from_email'] ?? '';

// Resolve terms
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

// Calculate billing display
$billingInterval = $contract['billing_interval_count'] . ' ' . ucfirst($contract['billing_interval_unit']);
if ($contract['billing_interval_count'] > 1) $billingInterval .= 's';

$pricingLabel = '';
$invoiceAmount = 0;
if ($contract['pricing_type'] === 'per_invoice') {
    $pricingLabel = 'Recurring Amount (per invoice)';
    $invoiceAmount = (float)$contract['price_per_invoice'];
} else {
    $pricingLabel = 'Fixed Total (billed over time)';
    // Calculate invoice amount with tax and discount
    $subtotal = (float)$contract['subtotal'];
    $discountType = $contract['discount_type'] ?? 'none';
    $discountValue = (float)($contract['discount_value'] ?? 0);
    $discount = 0;
    if ($discountType === 'percent') {
        $discount = max(0, min(100, $discountValue)) * $subtotal / 100;
    } elseif ($discountType === 'fixed') {
        $discount = $discountValue;
    }
    $taxable = max(0, $subtotal - $discount);
    $tax = max(0, (float)$contract['tax_percent']) * $taxable / 100;
    $invoiceAmount = max(0, $taxable + $tax);
}

$depositType = $contract['deposit_type'] ?? 'none';
$depositValue = (float)($contract['deposit_amount'] ?? 0);
$contractTotal = (float)($contract['total'] ?? 0);
$depositCalc = 0;
if ($depositType === 'percent') {
    $depositCalc = max(0, min(100, $depositValue)) * $contractTotal / 100;
} elseif ($depositType === 'fixed') {
    $depositCalc = $depositValue;
}

$showDepositInfo = $depositType !== 'none' && $depositCalc > 0;
$isOngoing = empty($contract['end_date']);
?>
<section>
  <div class="doc-type" style="text-align:center;font-weight:700;font-size:22px;margin-bottom:6px">Long-term Service Contract</div>
  <div style="text-align:center;color:#6b7280;margin-bottom:16px;font-size:13px">Recurring Billing Agreement</div>
  
  <?php if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW')): ?>
  <div class="no-print" style="display:flex;gap:8px;margin-bottom:8px">
    <a href="javascript:history.back()" class="btn btn-sm">Back</a>
    <a href="/?page=contract/long-term-contract-pdf&id=<?php echo (int)$id; ?>" target="_blank" rel="noopener" class="btn btn-sm">View PDF</a>
    <a href="/?page=contract/long-term-contract-pdf&id=<?php echo (int)$id; ?>" download="longterm-contract-<?php echo htmlspecialchars($contract['doc_number'] ?? $contract['id']); ?>.pdf" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium; margin-left:4px;">Download</a>
  </div>
  <?php endif; ?>

  <?php
  $brand = $appConfig['brand_name'] ?? 'Project Alpha';
  $logoConf = trim((string)($appConfig['logo_path'] ?? ''));
  $projectRoot = realpath(__DIR__ . '/../../../../');
  $defaultLogo = $projectRoot ? ($projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'default-logo.png') : '';
  $logoPath = $logoConf !== '' ? $logoConf : $defaultLogo;
  $isUrl = preg_match('/^(https?:\/\/|data:)/i', $logoPath) === 1;
  
  if (preg_match('/page=serve-upload/i', $logoPath)) {
    $parsed = parse_url($logoPath);
    if (!empty($parsed['query'])) {
      parse_str($parsed['query'], $q);
      if (!empty($q['file'])) {
        $fname = basename($q['file']);
        $bases = ['/var/www/config/uploads'];
        foreach ($bases as $b) {
          $candidate = @realpath(rtrim($b, '/\\') . DIRECTORY_SEPARATOR . $fname);
          if ($candidate !== false && is_file($candidate)) { $logoPath = $candidate; $isUrl = false; break; }
        }
      }
    }
  }
  
  if (!$isUrl) {
    $root = $projectRoot ?: realpath(__DIR__ . '/../../../../');
    if ($logoPath !== '' && ($logoPath[0] === '/' || $logoPath[0] === '\\')) {
      if ($root) {
        $candidate = @realpath($root . $logoPath);
        if ($candidate) { $logoPath = $candidate; }
      }
    }
  }
  $canShowLogo = $isUrl || @is_file($logoPath);
  $logoSrc = $logoPath;
  if ($canShowLogo && !$isUrl) {
    $imgContents = @file_get_contents($logoPath);
    if ($imgContents !== false) {
      $mime = preg_match('/\.svg$/i', $logoPath) ? 'image/svg+xml' : 'image/png';
      $logoSrc = 'data:' . $mime . ';base64,' . base64_encode($imgContents);
    }
  }
  ?>
  
  <table style="width:100%;table-layout:fixed;margin-bottom:8px;border-collapse:collapse">
    <tr>
      <td style="vertical-align:middle;width:70%">
        <div style="font-weight:700;font-size:20px"><?php echo htmlspecialchars($brand); ?></div>
        <?php if (!empty($contract['project_code'])): ?><div style="color:#374151;font-size:13px;margin-top:2px">Job <?php echo htmlspecialchars($contract['project_code']); ?></div><?php endif; ?>
        <?php if (!empty($contract['project_id'])): ?><div style="color:#374151;font-size:13px;margin-top:2px">Project <?php echo htmlspecialchars($contract['project_id']); ?></div><?php endif; ?>
      </td>
      <td style="vertical-align:middle;width:30%;text-align:right">
        <?php if ($canShowLogo): ?>
          <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="<?php echo htmlspecialchars($brand); ?>" style="height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px">
        <?php endif; ?>
      </td>
    </tr>
  </table>

  <!-- Contract Details Box -->
  <table style="width:100%;table-layout:fixed;margin-bottom:16px;border-collapse:collapse;border:1px solid #e5e7eb;background:#f9fafb">
    <tr>
      <td style="width:25%;padding:8px;border-right:1px solid #e5e7eb;vertical-align:top">
        <div style="font-size:11px;color:#6b7280">Start Date</div>
        <div style="font-weight:600;color:#111"><?php echo date('M j, Y', strtotime($contract['start_date'])); ?></div>
      </td>
      <td style="width:25%;padding:8px;border-right:1px solid #e5e7eb;vertical-align:top">
        <div style="font-size:11px;color:#6b7280">End Date</div>
        <div style="font-weight:600;color:#111"><?php echo $isOngoing ? 'Ongoing' : date('M j, Y', strtotime($contract['end_date'])); ?></div>
      </td>
      <td style="width:25%;padding:8px;border-right:1px solid #e5e7eb;vertical-align:top">
        <div style="font-size:11px;color:#6b7280">Billing Frequency</div>
        <div style="font-weight:600;color:#111"><?php echo htmlspecialchars($billingInterval); ?></div>
      </td>
      <td style="width:25%;padding:8px;vertical-align:top">
        <div style="font-size:11px;color:#6b7280">Status</div>
        <div style="font-weight:600;color:#111;text-transform:capitalize"><?php echo htmlspecialchars($contract['status']); ?></div>
      </td>
    </tr>
  </table>

  <?php if ($showDepositInfo): ?>
  <table style="width:100%;table-layout:fixed;margin-bottom:16px;border-collapse:collapse;border:1px solid #a7f3d0;background:#ecfdf5">
    <tr>
      <td style="padding:8px;vertical-align:top">
        <div style="font-size:11px;color:#065f46">Initial Deposit Due: <span style="font-weight:700;font-size:14px">$<?php echo number_format($depositCalc, 2); ?></span></div>
      </td>
    </tr>
  </table>
  <?php endif; ?>

  <table style="width:100%;table-layout:fixed;margin:12px 0 16px;border-collapse:collapse">
    <tr>
      <td style="vertical-align:top;width:50%;padding-right:12px">
        <div class="font-600">Service Provider</div>
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
      </td>
      <td style="vertical-align:top;width:50%;padding-left:12px">
        <div class="font-600">Client</div>
        <?php 
          $toLines = [];
          if (!empty($contract['client_name'])) { $toLines[] = (string)$contract['client_name']; }
          if (!empty($contract['client_org'])) { $toLines[] = (string)$contract['client_org']; }
          if (!empty($contract['address_line1'])) { $toLines[] = (string)$contract['address_line1']; }
          if (!empty($contract['address_line2'])) { $toLines[] = (string)$contract['address_line2']; }
          $c = trim((string)($contract['city'] ?? ''));
          $s = trim((string)($contract['state'] ?? ''));
          $p = trim((string)($contract['postal_code'] ?? ''));
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
      </td>
    </tr>
  </table>

  <?php if (!empty($contract['scope'])): ?>
  <div style="margin:16px 0;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px">
    <div style="font-weight:600;margin-bottom:8px">Scope of Work</div>
    <div style="white-space:pre-wrap;font-size:14px;line-height:1.6;color:#374151"><?php echo nl2br(htmlspecialchars($contract['scope'])); ?></div>
  </div>
  <?php endif; ?>

  <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06);margin-top:16px">
    <thead>
      <tr style="text-align:left;border-bottom:1px solid #eee;background:#f9fafb">
        <th colspan="4" style="padding:12px;font-size:15px"><?php echo htmlspecialchars($pricingLabel); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($contract['pricing_type'] === 'per_invoice'): ?>
        <tr>
          <td colspan="3" style="padding:12px">Recurring service fee (billed <?php echo htmlspecialchars(strtolower($billingInterval)); ?>)</td>
          <td style="padding:12px;text-align:right;font-weight:600">$<?php echo number_format($contract['price_per_invoice'], 2); ?></td>
        </tr>
      <?php else: ?>
        <?php if ($items): ?>
          <tr style="border-bottom:1px solid #eee">
            <th style="padding:10px">Description</th>
            <th style="padding:10px">Qty</th>
            <th style="padding:10px">Unit</th>
            <th style="padding:10px">Line Total</th>
          </tr>
          <?php foreach ($items as $it): ?>
          <tr style="border-top:1px solid #f3f4f6">
            <td style="padding:10px"><?php echo htmlspecialchars($it['description']); ?></td>
            <td style="padding:10px"><?php echo number_format($it['quantity'],2); ?></td>
            <td style="padding:10px">$<?php echo number_format($it['unit_price'],2); ?></td>
            <td style="padding:10px">$<?php echo number_format($it['line_total'],2); ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
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
        <?php if (!$isOngoing): ?>
        <tr>
          <td></td><td></td>
          <td style="padding:10px;font-weight:700">Contract Total</td>
          <td style="padding:10px;font-weight:700">$<?php echo number_format($contract['total'] ?? 0,2); ?></td>
        </tr>
        <?php endif; ?>
      <?php endif; ?>
      <tr style="background:#ecfdf5;border-top:2px solid #10b981">
        <td colspan="3" style="padding:12px;font-weight:700;font-size:15px;color:#065f46">Amount Per Invoice</td>
        <td style="padding:12px;font-weight:700;font-size:16px;color:#065f46;text-align:right">$<?php echo number_format($invoiceAmount,2); ?></td>
      </tr>
      <tr><td colspan="4" style="border-top:1px solid #eee"></td></tr>
      <tr>
        <td colspan="4" style="padding:12px 10px;color:#374151;font-size:13px;line-height:1.4">
          <?php echo htmlspecialchars($appConfig['signature_agreement'] ?? 'By signing below, I acknowledge that this is a multi-page contract and that I have read and agree to the recurring billing terms and conditions.'); ?>
        </td>
      </tr>
      <tr>
        <td colspan="4" style="padding:20px 10px 40px">
          <div style="border-top:2px solid #111;width:50%;margin-top:40px"></div>
          <div style="margin-top:4px;color:#4b5563">Client Signature</div>
        </td>
      </tr>
    </tbody>
  </table>

  <div style="page-break-after:always"></div>
  <h3>Terms and Conditions</h3>
  <?php if ($termsText !== ''): ?>
    <div style="white-space:pre-wrap;padding:6px 0;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#222;"><?php echo nl2br(htmlspecialchars($termsText)); ?></div>
  <?php else: ?>
    <ul>
      <li>This is a recurring billing agreement. Invoices will be generated automatically every <?php echo htmlspecialchars(strtolower($billingInterval)); ?>.</li>
      <li>Contract begins on <?php echo date('M j, Y', strtotime($contract['start_date'])); ?> and <?php echo $isOngoing ? 'continues until terminated by either party' : 'ends on ' . date('M j, Y', strtotime($contract['end_date'])); ?>.</li>
      <li>Payment due NET 30 unless otherwise specified.</li>
      <li>Termination requires written notice.</li>
      <li>Work product ownership and usage rights per agreement.</li>
    </ul>
  <?php endif; ?>
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
<div class="print-footer"><a href="https://project-alpha.tech" target="_blank" rel="noopener" style="color:inherit;text-decoration:none">Powered by Project Alpha</a></div>
