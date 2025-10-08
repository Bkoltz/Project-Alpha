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
// Load project notes if available and resolve terms fallback
$projectNotes = null;
$termsText = '';
if (!empty($inv['project_code'])) {
  try {
    $pm = $pdo->prepare('SELECT notes, terms FROM project_meta WHERE project_code=?');
    $pm->execute([$inv['project_code']]);
    $row = $pm->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      if (!empty($row['notes'])) { $projectNotes = $row['notes']; }
      if (!empty($row['terms'])) { $termsText = trim((string)$row['terms']); }
    }
  } catch (Throwable $e) { /* ignore */ }
}
if ($termsText === '') { $termsText = trim((string)($inv['terms'] ?? '')); }
if ($termsText === '') { $termsText = trim((string)($appConfig['terms'] ?? '')); }
?>
<section>
  <div class="doc-type" style="text-align:center;font-weight:700;font-size:22px;margin-bottom:6px">Invoice</div>
  <?php if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW')): ?>
  <div class="no-print" style="display:flex;gap:8px;margin-bottom:8px">
    <a href="javascript:history.back()" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">Back</a>
    <a href="/?page=invoice-pdf&id=<?php echo (int)$id; ?>" target="_blank" rel="noopener" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">View PDF</a>
    <?php if (!empty($inv['status']) && strtolower($inv['status']) !== 'void'): ?>
    <form method="post" action="/?page=email-send" style="display:inline">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="invoice">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
      <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">Email</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php
    $brand = $appConfig['brand_name'] ?? 'Project Alpha';
    $logoConf = trim((string)($appConfig['logo_path'] ?? ''));
    $projectRoot = realpath(__DIR__ . '/../../../');
    $defaultLogo = $projectRoot ? ($projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'default-logo.svg') : '';
    $logoPath = $logoConf !== '' ? $logoConf : $defaultLogo;

    $isUrl = preg_match('/^(https?:\/\/|data:)/i', $logoPath) === 1;
    // Resolve serve-upload URLs to actual file path
    if (preg_match('/page=serve-upload/i', $logoPath)) {
      $parsed = parse_url($logoPath);
      if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $q);
        if (!empty($q['file'])) {
          $fname = basename($q['file']);
          $bases = [];
          if ($projectRoot) {
            $cfg = realpath($projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'uploads');
            if ($cfg) { $bases[] = $cfg; } else { $bases[] = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'uploads'; }
            $internal = realpath(__DIR__ . '/../uploads');
            $bases[] = $internal ? $internal : (__DIR__ . '/../uploads');
          }
          $bases[] = '/var/www/config/uploads';
          foreach ($bases as $b) {
            $candidate = @realpath(rtrim($b, '/\\') . DIRECTORY_SEPARATOR . $fname);
            if ($candidate !== false && is_file($candidate)) { $logoPath = $candidate; $isUrl = false; break; }
          }
        }
      }
    }
    // Map leading slash /public or /config relative to project root
    if (!$isUrl) {
      if ($logoPath !== '' && ($logoPath[0] === '/' || $logoPath[0] === '\\')) {
        if ($projectRoot) {
          $candidate = @realpath($projectRoot . $logoPath);
          if ($candidate) { $logoPath = $candidate; }
        }
      } else {
        if ($projectRoot) {
          $candidate = @realpath($projectRoot . DIRECTORY_SEPARATOR . $logoPath);
          if ($candidate) { $logoPath = $candidate; }
        }
      }
    }

    $canShowLogo = $isUrl || ($logoPath !== '' && @is_file($logoPath));
    $logoSrc = $logoPath;
    if ($canShowLogo && !$isUrl) {
      $imgContents = @file_get_contents($logoPath);
      if ($imgContents !== false) {
        $mime = null;
        if (preg_match('/\.svg$/i', $logoPath)) {
          $mime = 'image/svg+xml';
        } else if (function_exists('finfo_open')) {
          $finfo = @finfo_open(FILEINFO_MIME_TYPE);
          if ($finfo) { $det = @finfo_buffer($finfo, $imgContents); if ($det) { $mime = $det; } @finfo_close($finfo); }
        }
        if ($mime === null) { $mime = 'image/png'; }
        $logoSrc = 'data:' . $mime . ';base64,' . base64_encode($imgContents);
      } else {
        $normalized = str_replace('\\', '/', $logoPath);
        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1 || strpos($normalized, '/') === 0) {
          $logoSrc = 'file:///' . ltrim($normalized, '/');
        }
      }
    }
  ?>
  <table style="width:100%;table-layout:fixed;margin-bottom:8px;border-collapse:collapse">
    <tr>
      <td style="vertical-align:middle;width:70%">
        <div style="font-weight:700;font-size:20px"><?php echo htmlspecialchars($brand); ?></div>
        <div style="color:#374151;font-size:13px;margin-top:2px">Invoice I-<?php echo htmlspecialchars($inv['doc_number'] ?? $inv['id']); ?></div>
        <?php if (!empty($inv['project_code'])): ?><div style="color:#374151;font-size:13px;margin-top:2px">Project: <?php echo htmlspecialchars($inv['project_code']); ?></div><?php endif; ?>
      </td>
      <td style="vertical-align:middle;width:30%;text-align:right">
        <?php if ($canShowLogo): ?>
          <?php if (!$isUrl && preg_match('/\.svg$/i', $logoPath) && is_file($logoPath)): ?>
            <?php if (defined('PDF_MODE')): ?>
              <?php echo @file_get_contents($logoPath); ?>
            <?php else: ?>
              <?php $svgContents = @file_get_contents($logoPath); if ($svgContents !== false) { $svgData = 'data:image/svg+xml;base64,'.base64_encode($svgContents); ?>
                <img src="<?php echo htmlspecialchars($svgData); ?>" alt="<?php echo htmlspecialchars($brand); ?>" style="height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px">
              <?php } else { ?>
                <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="<?php echo htmlspecialchars($brand); ?>" style="height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px">
              <?php } ?>
            <?php endif; ?>
          <?php else: ?>
            <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="<?php echo htmlspecialchars($brand); ?>" style="height:80px;width:auto;object-fit:contain;border-radius:4px;background:#fff;padding:4px">
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  </table>

  <table style="width:100%;table-layout:fixed;margin:12px 0 16px;border-collapse:collapse">
    <tr>
      <td style="vertical-align:top;width:50%;padding-right:12px">
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
      </td>
      <td style="vertical-align:top;width:50%;padding-left:12px">
        <div style="font-weight:600">To</div>
        <?php 
          $toLines = [];
          if (!empty($inv['client_name'])) { $toLines[] = (string)$inv['client_name']; }
          if (!empty($inv['client_org'])) { $toLines[] = (string)$inv['client_org']; }
          if (!empty($inv['address_line1'])) { $toLines[] = (string)$inv['address_line1']; }
          if (!empty($inv['address_line2'])) { $toLines[] = (string)$inv['address_line2']; }
          $c = trim((string)($inv['city'] ?? ''));
          $s = trim((string)($inv['state'] ?? ''));
          $p = trim((string)($inv['postal'] ?? ''));
          $parts2 = [];
          if ($c !== '') { $parts2[] = $c; }
          if ($s !== '') { $parts2[] = $s; }
          if ($p !== '') { $parts2[] = $p; }
          $cityStatePostal = implode(', ', $parts2);
          if ($cityStatePostal !== '') { $toLines[] = $cityStatePostal; }
        ?>
        <div><?php foreach ($toLines as $ln) { echo '<div>'.htmlspecialchars($ln).'</div>'; } ?></div>
        <?php if (!empty($inv['client_phone']) || !empty($inv['client_email'])): ?>
          <div style="margin-top:6px;color:#4b5563;font-size:13px">
            <?php if (!empty($inv['client_phone'])): ?><div><?php echo format_phone($inv['client_phone']); ?></div><?php endif; ?>
            <?php if (!empty($inv['client_email'])): ?><div><?php echo htmlspecialchars($inv['client_email']); ?></div><?php endif; ?>
          </div>
        <?php endif; ?>
      </td>
    </tr>
  </table>

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
  <div style="page-break-after:always"></div>
  <h3>Terms and Conditions</h3>
  <?php if ($termsText !== ''): ?>
    <pre style="white-space:pre-wrap;background:#fff;padding:12px;border:1px solid #eee;border-radius:8px"><?php echo htmlspecialchars($termsText); ?></pre>
  <?php else: ?>
    <p class="lead">This invoice reflects the amounts due for goods and services provided. Payment terms and other conditions are as specified in Settings.</p>
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
<div class="print-footer"><a href="https://project-alpha.tech" target="_blank" rel="noopener" style="color:inherit;text-decoration:none">Powered by Project Alpha</a></div>
