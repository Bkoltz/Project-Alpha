<?php
// src/views/pages/quote/quote-details.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/format.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/document_fields.php';
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT q.*, c.name client_name, o.name AS client_org, c.email client_email, c.phone client_phone, c.address_line1, c.address_line2, c.city, c.state, c.postal, c.country FROM quotes q JOIN clients c ON c.id=q.client_id LEFT JOIN organizations o ON o.id=c.organization_id WHERE q.id=?');
$stmt->execute([$id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$quote){ echo '<p>Quote not found</p>'; return; }
$items = $pdo->prepare('SELECT item, description, quantity, unit_price, line_total FROM quote_items WHERE quote_id=?');
$items->execute([$id]);
$items = $items->fetchAll();
require_once __DIR__ . '/../../../utils/format.php';
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
if ($termsText === '' && !empty($quote['is_on_demand'])) { $termsText = trim((string)($appConfig['on_demand_terms'] ?? '')); }
if ($termsText === '') { $termsText = trim((string)($appConfig['terms'] ?? '')); }
// Detect PDF mode for conditional page breaks
$isPdf = defined('PDF_MODE');
?>
<section>
  <div class="doc-type" style="text-align:center;font-weight:700;font-size:22px;margin-bottom:6px">Quote</div>
  <div style="text-align:center;color:#6b7280;margin-bottom:6px;font-size:13px">Valid for <?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?> days</div>
  <?php if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW')): ?>
  <?php 
    // Status banner styling
    $status = strtolower($quote['status'] ?? 'pending');
    $statusColors = [
      'pending' => ['bg' => '#fffbeb', 'text' => '#92400e', 'border' => '#fbbf24'],
      'approved' => ['bg' => '#ecfdf5', 'text' => '#065f46', 'border' => '#10b981'],
      'rejected' => ['bg' => '#fef2f2', 'text' => '#991b1b', 'border' => '#ef4444']
    ];
    $colors = $statusColors[$status] ?? ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#9ca3af'];
  ?>
  <div class="no-print" style="padding:12px 16px;background:<?php echo $colors['bg']; ?>;color:<?php echo $colors['text']; ?>;border-left:4px solid <?php echo $colors['border']; ?>;border-radius:6px;margin-bottom:12px;font-weight:600;text-transform:uppercase;font-size:14px;letter-spacing:0.5px">
    Status: <?php echo htmlspecialchars($quote['status']); ?>
  </div>
  <div class="no-print" style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap">
    <a href="javascript:history.back()" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">Back</a>
    <a href="/?page=quote/quote-pdf&id=<?php echo (int)$id; ?>" target="_blank" rel="noopener" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">View PDF</a>
    <a href="/?page=quote/quote-pdf&id=<?php echo (int)$id; ?>" download="quote-<?php echo htmlspecialchars($quote['doc_number'] ?? $quote['id']); ?>.pdf" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">Download</a>
    <?php if ($quote['status'] === 'pending'): ?>
      <a href="/?page=quote/quotes-edit&id=<?php echo (int)$id; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">Edit</a>
    <?php endif; ?>
    <?php if (!empty($quote['status']) && strtolower($quote['status']) !== 'rejected'): ?>
    <form method="post" action="/?page=email-send" style="display:inline">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="quote">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
      <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">Email</button>
    </form>
    <?php endif; ?>
    <?php if ($quote['status'] === 'pending'): ?>
      <form method="post" action="/?page=quote/quote-approve" style="display:inline" onsubmit="return confirm('Approve this quote and generate contract + invoice?');">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#16a34a;color:#fff; font-size: medium;">Approve</button>
      </form>
      <form method="post" action="/?page=quote/quote-reject" style="display:inline" onsubmit="return confirm('Deny this quote?');">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
        <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#ef4444;color:#fff; font-size: medium;">Deny</button>
      </form>
    <?php endif; ?>
    <?php if (!empty($quote['status']) && strtolower($quote['status']) === 'rejected'): ?>
    <form method="post" action="/?page=document-reenable" style="display:inline" onsubmit="return confirm('Re-enable this quote? It will be set back to pending status.');">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="quote">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fef3c7;color:#92400e; font-size: medium;">Re-enable</button>
    </form>
    <?php endif; ?>
    <form method="post" action="/?page=document-date-update" style="display:inline" onsubmit="return confirm('Update document date to today? This will refresh the date shown on the PDF.');">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="quote">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#dbeafe;color:#1e40af; font-size: medium;">Update Document Date</button>
    </form>
  </div>
  <?php if (!empty($_GET['error'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#fee2e2;color:#991b1b;border-radius:6px;margin-bottom:8px;font-size:14px">⚠ Error: <?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['reenabled'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#d1fae5;color:#065f46;border-radius:6px;margin-bottom:8px;font-size:14px">✓ Quote re-enabled successfully</div>
  <?php endif; ?>
  <?php if (!empty($_GET['date_updated'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#dbeafe;color:#1e3a8a;border-radius:6px;margin-bottom:8px;font-size:14px">✓ Document date updated successfully</div>
  <?php endif; ?>
  <div class="no-print" style="padding:8px 12px;background:#f3f4f6;border-radius:6px;margin-bottom:8px;font-size:13px;color:#374151">
    <strong>Created:</strong> <?php echo !empty($quote['created_at']) ? date('M j, Y g:i A', strtotime($quote['created_at'])) : 'N/A'; ?>
    <span style="margin:0 8px">|</span>
    <strong>Document Date:</strong> <?php echo !empty($quote['document_date']) ? date('M j, Y g:i A', strtotime($quote['document_date'])) : 'N/A'; ?>
    <?php if (!empty($quote['document_date_updated_at'])): ?>
      <span style="margin-left:8px;color:#6b7280;font-size:12px">(Updated: <?php echo date('M j, Y g:i A', strtotime($quote['document_date_updated_at'])); ?>)</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php
  $brand = $appConfig['brand_name'] ?? 'Project Alpha';
  $logoConf = trim((string)($appConfig['logo_path'] ?? ''));
  // Resolve default logo under project root public/assets
  $projectRoot = realpath(__DIR__ . '/../../../../');
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
        $projectRoot = realpath(__DIR__ . '/../../../../');
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
    $root = $projectRoot ?: realpath(__DIR__ . '/../../../../');
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
        <?php if (!empty($quote['project_code'])): ?><div style="color:#374151;font-size:13px;margin-top:2px">Job <?php echo htmlspecialchars($quote['project_code']); ?></div><?php endif; ?>
        <?php if (!empty($quote['project_id'])): ?><div style="color:#374151;font-size:13px;margin-top:2px">Project <?php echo htmlspecialchars($quote['project_id']); ?></div><?php endif; ?>
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

  <?php 
    $depositType = $quote['deposit_type'] ?? 'none';
    $depositValue = (float)($quote['deposit_amount'] ?? 0);
    $quoteTotal = (float)($quote['total'] ?? 0);
    $depositCalc = 0;
    if ($depositType === 'percent') {
      $depositCalc = max(0, min(100, $depositValue)) * $quoteTotal / 100;
    } elseif ($depositType === 'fixed') {
      $depositCalc = $depositValue;
    }
    $fulfillmentDate = $quote['fulfillment_date'] ?? null;
    $showDepositInfo = $depositType !== 'none' && $depositCalc > 0;
    $showFulfillmentDate = !empty($fulfillmentDate);
    
    // Get custom fields for display
    $documentType = 'regular';
    if (!empty($quote['is_long_term'])) $documentType = 'long_term';
    elseif (!empty($quote['is_on_demand'])) $documentType = 'on_demand';
    
    $customFieldValues = !empty($quote['custom_fields']) ? json_decode($quote['custom_fields'], true) : [];
    if (!is_array($customFieldValues)) $customFieldValues = [];
    
    // Fetch custom field definitions (non-builtin only)
    $customFieldDefs = [];
    try {
      $cfStmt = $pdo->prepare('SELECT * FROM document_custom_fields WHERE document_type = ? AND is_enabled = 1 AND is_builtin = 0 ORDER BY display_order, id');
      $cfStmt->execute([$documentType]);
      $customFieldDefs = $cfStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* ignore */ }
    
    // Build array of custom fields with values to display
    $displayCustomFields = [];
    foreach ($customFieldDefs as $cf) {
      $key = $cf['field_key'];
      if (isset($customFieldValues[$key]) && $customFieldValues[$key] !== '') {
        $val = $customFieldValues[$key];
        // Format based on type
        if ($cf['field_type'] === 'date' && !empty($val)) {
          $val = date('M j, Y', strtotime($val));
        } elseif ($cf['field_type'] === 'number' && is_numeric($val)) {
          $val = number_format((float)$val, 2);
        }
        $displayCustomFields[] = ['label' => $cf['field_label'], 'value' => $val];
      }
    }
    $hasCustomFields = !empty($displayCustomFields);
  ?>
  <?php if ($showDepositInfo || $showFulfillmentDate || $hasCustomFields): ?>
  <table style="width:100%;table-layout:fixed;margin-bottom:16px;border-collapse:collapse;border:1px solid #e5e7eb">
    <tr>
      <?php if ($showDepositInfo): ?>
      <td style="padding:8px;border-right:1px solid #e5e7eb;vertical-align:top">
        <div style="font-size:11px;color:#6b7280">Deposit Due: <span style="font-weight:600;color:#059669">$<?php echo number_format($depositCalc, 2); ?></span></div>
      </td>
      <?php endif; ?>
      <?php if ($showFulfillmentDate): ?>
      <td style="padding:8px;<?php echo $hasCustomFields ? 'border-right:1px solid #e5e7eb;' : ''; ?>vertical-align:top">
        <div style="font-size:11px;color:#6b7280">Fulfillment Date: <span style="font-weight:600;color:#2563eb"><?php echo date('M j, Y', strtotime($fulfillmentDate)); ?></span></div>
      </td>
      <?php endif; ?>
      <?php foreach ($displayCustomFields as $idx => $cf): ?>
      <td style="padding:8px;<?php echo $idx < count($displayCustomFields) - 1 ? 'border-right:1px solid #e5e7eb;' : ''; ?>vertical-align:top">
        <div style="font-size:11px;color:#6b7280"><?php echo htmlspecialchars($cf['label']); ?>: <span style="font-weight:600;color:#374151"><?php echo htmlspecialchars($cf['value']); ?></span></div>
      </td>
      <?php endforeach; ?>
    </tr>
  </table>
  <?php endif; ?>

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
      </td>
    </tr>
  </table>

  <?php
    $scopeText = trim((string)($quote['scope'] ?? ''));
    $scopeEnabled = !isset($appConfig['quote_scope_enabled']) || !empty($appConfig['quote_scope_enabled']);
    if ($scopeEnabled && $scopeText !== ''): 
  ?>
  <div style="page-break-before:auto;margin-top:20px">
    <h3 style="font-size:18px;font-weight:700;margin-bottom:12px;color:#111">Scope of Project</h3>
    <div style="white-space:pre-wrap;padding:12px;background:#f9fafb;border-left:4px solid #3b82f6;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#374151;border-radius:4px"><?php echo nl2br(htmlspecialchars($scopeText)); ?></div>
  </div>
  <div style="page-break-after:always"></div>
  <?php endif; ?>

  <table style="width:100%;table-layout:fixed;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
    <thead>
      <tr style="text-align:left;border-bottom:1px solid #eee">
        <th style="padding:10px;width:25%;vertical-align:top;text-align:center">Item</th>
        <th style="padding:10px;width:35%;vertical-align:top">Description</th>
        <th style="padding:10px;width:10%;text-align:right;vertical-align:top">Qty</th>
        <th style="padding:10px;width:15%;text-align:right;vertical-align:top">Unit Price</th>
        <th style="padding:10px;width:15%;text-align:right;vertical-align:top">Line Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr style="border-top:1px solid #f3f4f6">
        <td style="padding:10px;font-weight:600;vertical-align:top;text-align:center"><?php echo htmlspecialchars($it['item'] ?? ''); ?></td>
        <td style="padding:10px;color:#6b7280;font-size:13px;vertical-align:top"><?php echo htmlspecialchars($it['description'] ?? ''); ?></td>
        <td style="padding:10px;text-align:right;vertical-align:top"><?php echo number_format($it['quantity'],2); ?></td>
        <td style="padding:10px;text-align:right;vertical-align:top">$<?php echo number_format($it['unit_price'],2); ?></td>
        <td style="padding:10px;text-align:right;vertical-align:top">$<?php echo number_format($it['line_total'],2); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Totals Section - uses table for PDF compatibility -->
  <table style="width:100%;border-collapse:collapse;margin-top:12px">
    <tr>
      <td style="width:60%"></td>
      <td style="width:40%">
        <table style="width:100%;border-collapse:collapse">
          <tr>
            <td style="padding:8px 12px;text-align:right;color:#6b7280">Subtotal</td>
            <td style="padding:8px 12px;text-align:right;width:120px">$<?php echo number_format($quote['subtotal'],2); ?></td>
          </tr>
          <tr>
            <td style="padding:8px 12px;text-align:right;color:#6b7280">Discount</td>
            <td style="padding:8px 12px;text-align:right">
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
            <td style="padding:8px 12px;text-align:right;color:#6b7280">Tax</td>
            <td style="padding:8px 12px;text-align:right"><?php echo number_format($quote['tax_percent'],2); ?>%</td>
          </tr>
          <tr style="border-top:2px solid #e5e7eb">
            <td style="padding:8px 12px;text-align:right;font-weight:700">Total</td>
            <td style="padding:8px 12px;text-align:right;font-weight:700;font-size:16px">$<?php echo number_format($quote['total'],2); ?></td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
<?php if (!isset($appConfig['quotes_show_terms']) || (int)$appConfig['quotes_show_terms'] === 1): ?>
    <div style="page-break-after:always"></div>
    <h3>Terms and Conditions</h3>
    <?php if ($termsText !== ''): ?>
      <div style="white-space:pre-wrap;padding:6px 0;font-family: Georgia, 'Times New Roman', serif; font-size:13px; line-height:1.6; color:#222"><?php echo nl2br(htmlspecialchars($termsText)); ?></div>
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
