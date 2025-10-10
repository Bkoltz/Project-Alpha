<?php
// src/views/pages/quote-print.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/format.php';
require_once __DIR__ . '/../../utils/csrf.php';
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT q.*, c.name client_name, c.organization client_org, c.email client_email, c.phone client_phone, c.address_line1, c.address_line2, c.city, c.state, c.postal, c.country FROM quotes q JOIN clients c ON c.id=q.client_id WHERE q.id=?');
$stmt->execute([$id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$quote){ echo '<p>Quote not found</p>'; return; }
$items = $pdo->prepare('SELECT description, quantity, unit_price, line_total FROM quote_items WHERE quote_id=?');
$items->execute([$id]);
$items = $items->fetchAll();
require_once __DIR__ . '/../../utils/format.php';
$fromName = ($appConfig['from_name'] ?? '') ?: ($appConfig['brand_name'] ?? 'Project Alpha');
$fromAddress = trim(($appConfig['from_address_line1'] ?? '')."\n".($appConfig['from_address_line2'] ?? '')."\n".($appConfig['from_city'] ?? '').' '.($appConfig['from_state'] ?? '').' '.($appConfig['from_postal'] ?? '')."\n".($appConfig['from_country'] ?? ''));
$fromPhone = $appConfig['from_phone'] ?? '';
$fromEmail = $appConfig['from_email'] ?? '';
// Resolve terms: project-level terms override quote terms override app settings
$termsText = '';
if (!empty($quote['project_code'])) {
  try {
    $pm = $pdo->prepare('SELECT terms FROM project_meta WHERE project_code=?');
    $pm->execute([$quote['project_code']]);
    $pt = (string)$pm->fetchColumn();
    if (trim($pt) !== '') { $termsText = trim($pt); }
  } catch (Throwable $e) { /* ignore */ }
}
if ($termsText === '') { $termsText = trim((string)($quote['terms'] ?? '')); }
if ($termsText === '') { $termsText = trim((string)($appConfig['terms'] ?? '')); }
// Detect PDF mode for conditional page breaks
$isPdf = defined('PDF_MODE');
?>
<section>
  <div class="doc-type" style="text-align:center;font-weight:700;font-size:22px;margin-bottom:6px">Quote</div>
  <div style="text-align:center;color:#6b7280;margin-bottom:6px;font-size:13px">Valid for <?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?> days</div>
  <?php if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW')): ?>
  <div class="no-print" style="display:flex;gap:8px;margin-bottom:8px">
    <a href="javascript:history.back()" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">Back</a>
    <a href="/?page=quote-pdf&id=<?php echo (int)$id; ?>" target="_blank" rel="noopener" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">View PDF</a>
    <?php if (!empty($quote['status']) && strtolower($quote['status']) !== 'rejected'): ?>
    <form method="post" action="/?page=email-send" style="display:inline">
<input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="quote">
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
  // Resolve default logo under project root public/assets
  $projectRoot = realpath(__DIR__ . '/../../../');
  $defaultLogo = $projectRoot ? ($projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'default-logo.svg') : '';
  $logoPath = $logoConf !== '' ? $logoConf : $defaultLogo;
  $isUrl = preg_match('/^(https?:\/\/|data:)/i', $logoPath) === 1;
  // If the configured logo is an internal routed URL like "/?page=serve-upload&file=...",
  // resolve it to the actual uploaded file so we can embed it for Dompdf.
  if (preg_match('/page=serve-upload/i', $logoPath)) {
    $parsed = parse_url($logoPath);
    if (!empty($parsed['query'])) {
      parse_str($parsed['query'], $q);
      if (!empty($q['file'])) {
        $fname = basename($q['file']);
        $bases = [];
        // Prefer project-root config/uploads
        $projectRoot = realpath(__DIR__ . '/../../../');
        if ($projectRoot) {
          $cfg = realpath($projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'uploads');
          if ($cfg) { $bases[] = $cfg; } else { $bases[] = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'uploads'; }
          $internal = realpath(__DIR__ . '/../uploads');
          $bases[] = $internal ? $internal : (__DIR__ . '/../uploads');
        }
        // Container path
        $bases[] = '/var/www/config/uploads';
        foreach ($bases as $b) {
          $candidate = @realpath(rtrim($b, '/\\') . DIRECTORY_SEPARATOR . $fname);
          if ($candidate !== false && is_file($candidate)) { $logoPath = $candidate; $isUrl = false; break; }
        }
      }
    }
  }
  if (!$isUrl) {
    // Map leading-slash paths like /public/... and /config/uploads/... to the project root
    $root = $projectRoot ?: realpath(__DIR__ . '/../../../');
    if ($logoPath !== '' && ($logoPath[0] === '/' || $logoPath[0] === '\\')) {
      if ($root) {
        $candidate = @realpath($root . $logoPath);
        if ($candidate) { $logoPath = $candidate; }
      }
    } else {
      // For relative paths (e.g., public/assets/logo.png or config/uploads/logo.png)
      if ($root) {
        $candidate = @realpath($root . DIRECTORY_SEPARATOR . $logoPath);
        if ($candidate) { $logoPath = $candidate; }
      }
    }
  }
  $canShowLogo = $isUrl || @is_file($logoPath);
  // Prefer embedding local images as data URIs so Dompdf can render them reliably
  $logoSrc = $logoPath;
  if ($canShowLogo && !$isUrl) {
    // Try to read the file and build a data URI (base64). This avoids file:// or remote restrictions
    $imgContents = @file_get_contents($logoPath);
    if ($imgContents !== false) {
      $mime = null;
      // Prefer explicit SVG mime type when extension indicates SVG
      if (preg_match('/\.svg$/i', $logoPath)) {
        $mime = 'image/svg+xml';
      } else {
        if (function_exists('finfo_open')) {
          $finfo = @finfo_open(FILEINFO_MIME_TYPE);
          if ($finfo) {
            $det = @finfo_buffer($finfo, $imgContents);
            if ($det) { $mime = $det; }
            @finfo_close($finfo);
          }
        }
        if ($mime === null) { $mime = 'image/png'; }
      }
      $logoSrc = 'data:' . $mime . ';base64,' . base64_encode($imgContents);
    } else {
      // If embedding failed, fall back to a file:/// URL which Dompdf can sometimes read
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
        <div style="color:#374151;font-size:13px;margin-top:2px">Quote Q-<?php echo htmlspecialchars($quote['doc_number'] ?? $quote['id']); ?></div>
        <?php if (!empty($quote['project_code'])): ?><div style="color:#374151;font-size:13px;margin-top:2px">Project: <?php echo htmlspecialchars($quote['project_code']); ?></div><?php endif; ?>
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
          if (!empty($quote['client_name'])) { $toLines[] = (string)$quote['client_name']; }
          if (!empty($quote['client_org'])) { $toLines[] = (string)$quote['client_org']; }
          if (!empty($quote['address_line1'])) { $toLines[] = (string)$quote['address_line1']; }
          if (!empty($quote['address_line2'])) { $toLines[] = (string)$quote['address_line2']; }
          $c = trim((string)($quote['city'] ?? ''));
          $s = trim((string)($quote['state'] ?? ''));
          $p = trim((string)($quote['postal'] ?? ''));
          $parts2 = [];
          if ($c !== '') { $parts2[] = $c; }
          if ($s !== '') { $parts2[] = $s; }
          if ($p !== '') { $parts2[] = $p; }
          $cityStatePostal = implode(', ', $parts2);
          if ($cityStatePostal !== '') { $toLines[] = $cityStatePostal; }
        ?>
        <div><?php foreach ($toLines as $ln) { echo '<div>'.htmlspecialchars($ln).'</div>'; } ?></div>
        <?php if (!empty($quote['client_phone']) || !empty($quote['client_email'])): ?>
          <div style="margin-top:6px;color:#4b5563;font-size:13px">
            <?php if (!empty($quote['client_phone'])): ?><div><?php echo format_phone($quote['client_phone']); ?></div><?php endif; ?>
            <?php if (!empty($quote['client_email'])): ?><div><?php echo htmlspecialchars($quote['client_email']); ?></div><?php endif; ?>
          </div>
<?php endif; ?>
  </table>

  <?php // Only add a terms section if non-empty to avoid blank extra pages in PDFs
  if ($termsText !== ''): ?>
    <div style="page-break-inside:avoid; margin-top:16px;">
      <div style="font-weight:600;margin-bottom:6px">Terms</div>
      <div style="white-space:pre-wrap;line-height:1.4;color:#374151"><?php echo nl2br(htmlspecialchars($termsText)); ?></div>
    </div>
  <?php endif; ?>
  </table>



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
        <td style="padding:10px">$<?php echo number_format($quote['subtotal'],2); ?></td>
      </tr>
      <tr>
        <td></td><td></td>
        <td style="padding:10px;font-weight:600">Discount</td>
        <td style="padding:10px">
          <?php if ($quote['discount_type']==='percent'): ?>
            <?php echo number_format($quote['discount_value'],2); ?>%
          <?php elseif ($quote['discount_type']==='fixed'): ?>
            $<?php echo number_format($quote['discount_value'],2); ?>
          <?php else: ?>
            $0.00
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td></td><td></td>
        <td style="padding:10px;font-weight:600">Tax</td>
        <td style="padding:10px"><?php echo number_format($quote['tax_percent'],2); ?>%</td>
      </tr>
      <tr>
        <td></td><td></td>
        <td style="padding:10px;font-weight:700">Total</td>
        <td style="padding:10px;font-weight:700">$<?php echo number_format($quote['total'],2); ?></td>
      </tr>
    </tbody>
</table>
<?php if (!isset($appConfig['quotes_show_terms']) || (int)$appConfig['quotes_show_terms'] === 1): ?>
    <div style="page-break-after:always"></div>
    <h3>Terms and Conditions</h3>
    <?php if ($termsText !== ''): ?>
      <pre style="white-space:pre-wrap;background:#fff;padding:12px;border:1px solid #eee;border-radius:8px"><?php echo htmlspecialchars($termsText); ?></pre>
    <?php else: ?>
      <p class="lead">By accepting this quote, the client agrees to the scope and payment terms. Additional terms can be customized in Settings.</p>
    <?php endif; ?>
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
