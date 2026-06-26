<?php
// src/views/pages/invoice-print.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/format.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/acl.php';
$id = (int)($_GET['id'] ?? 0);
if (!defined('PDF_MODE')) {
    require_record_ownership($pdo, 'invoices', $id);
}
$st = $pdo->prepare('SELECT i.*, c.name client_name, o.name AS client_org, c.email client_email, c.phone client_phone, c.address_line1, c.address_line2, c.city, c.state, c.postal_code, c.country FROM invoices i JOIN clients c ON c.id=i.client_id LEFT JOIN organizations o ON o.id=c.organization_id WHERE i.id=?');
$st->execute([$id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if(!$inv){ echo '<p>Invoice not found</p>'; return; }
$items = $pdo->prepare('SELECT item, description, quantity, unit_price, line_total, is_extra_charge FROM invoice_items WHERE invoice_id=?');
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
  } catch (Throwable $e) {
    // Fallback for older schemas without 'terms'
    try {
      $pm = $pdo->prepare('SELECT notes FROM project_meta WHERE project_code=?');
      $pm->execute([$inv['project_code']]);
      $row = $pm->fetch(PDO::FETCH_ASSOC);
      if ($row && !empty($row['notes'])) { $projectNotes = $row['notes']; }
    } catch (Throwable $e2) { /* ignore */ }
  }
}
if ($termsText === '') { $termsText = trim((string)($inv['terms'] ?? '')); }
if ($termsText === '' && ($inv['invoice_type'] ?? '') === 'on_demand') { $termsText = trim((string)($appConfig['on_demand_terms'] ?? '')); }
// Compute outstanding balance
$total = (float) ($inv['total'] ?? 0);
$paid = (float) ($inv['amount_paid'] ?? 0);
$outstanding = max(0, $total - $paid);

if ($termsText === '') { $termsText = trim((string)($appConfig['terms'] ?? '')); }
?>
<section>
  <div class="doc-type" style="text-align:center;font-weight:700;font-size:22px;margin-bottom:6px">Invoice</div>
  <?php if (!defined('PDF_MODE') && !defined('PUBLIC_VIEW')): ?>
  <?php 
    // Status banner styling
    $istatus = strtolower($inv['status'] ?? 'unpaid');
    $istatusColors = [
      'unpaid' => ['bg' => '#fffbeb', 'text' => '#92400e', 'border' => '#fbbf24'],
      'partial' => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#f59e0b'],
      'paid' => ['bg' => '#ecfdf5', 'text' => '#065f46', 'border' => '#10b981'],
      'void' => ['bg' => '#f3f4f6', 'text' => '#6b7280', 'border' => '#9ca3af']
    ];
    $icolors = $istatusColors[$istatus] ?? ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#9ca3af'];
  ?>
  <div class="no-print" style="padding:12px 16px;background:<?php echo $icolors['bg']; ?>;color:<?php echo $icolors['text']; ?>;border-left:4px solid <?php echo $icolors['border']; ?>;border-radius:6px;margin-bottom:12px;font-weight:600;text-transform:uppercase;font-size:14px;letter-spacing:0.5px">
    Status: <?php echo htmlspecialchars($inv['status']); ?>
  </div>
  <div class="no-print flex flex-wrap">
    <a href="javascript:history.back()" class="btn btn-sm">Back</a>
    <a href="/?page=invoice/invoice-pdf&id=<?php echo (int)$id; ?>" target="_blank" rel="noopener" class="btn btn-sm">View PDF</a>
    <a href="/?page=invoice/invoice-pdf&id=<?php echo (int)$id; ?>" download="invoice-<?php echo htmlspecialchars($inv['doc_number'] ?? $inv['id']); ?>.pdf" class="btn btn-sm">Download</a>
    <?php if ($inv['status'] !== 'paid'): ?>
      <a href="/?page=invoice/invoices-edit&id=<?php echo (int)$id; ?>" class="btn btn-sm">Edit</a>
    <?php endif; ?>
    <?php if (!empty($inv['status']) && strtolower($inv['status']) !== 'void'): ?>
    <form method="post" action="/?page=email-send" style="display:inline">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="invoice">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
      <button type="submit" class="btn btn-sm">Email</button>
    </form>
    <?php endif; ?>
    <?php if ($inv['status'] !== 'paid' && $inv['status'] !== 'void'): ?>
      <a href="/?page=payments/payments-create&invoice_id=<?php echo (int)$id; ?>&amount=<?php echo urlencode(number_format($outstanding, 2, '.', '')); ?>" 
         style="padding:6px 10px;border:0;border-radius:8px;background:#d1fae5;color:#065f46; font-size: medium;text-decoration:none;display:inline-block;margin-right:6px;">Mark as Paid</a>
    <?php endif; ?>
    <?php if (!empty($inv['status']) && strtolower($inv['status']) === 'void'): ?>
    <form method="post" action="/?page=document-reenable" style="display:inline" onsubmit="return confirm('Re-enable this invoice? It will be set back to unpaid status.');">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="invoice">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fef3c7;color:#92400e; font-size: medium;">Re-enable</button>
    </form>
    <?php endif; ?>
    <form method="post" action="/?page=document-date-update" style="display:inline" onsubmit="return confirm('Update document date to today? This will refresh the date shown on the PDF.');">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="type" value="invoice">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
      <button type="submit" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#dbeafe;color:#1e40af; font-size: medium;">Update Document Date</button>
    </form>
    <button type="button" onclick="generatePublicLink()" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#f0fdf4;color:#166534; font-size: medium;">🔗 Share Link</button>
    <?php 
      require_once __DIR__ . '/../../../services/StripeService.php';
      if (StripeService::isConfigured($appConfig) && in_array(strtolower($inv['status']), ['unpaid', 'partial'])): 
    ?>
    <form method="post" action="/?page=stripe-charge" style="display:inline" target="_blank">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="invoice_id" value="<?php echo (int)$id; ?>">
      <button type="submit" style="padding:6px 10px;border:0;border-radius:8px;background:#4f46e5;color:#fff; font-size: medium;cursor:pointer">💳 Charge Card</button>
    </form>
    <?php endif; ?>
  </div>
  <?php if (!empty($_GET['reenabled'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#d1fae5;color:#065f46;border-radius:6px;margin-bottom:8px;font-size:14px">✓ Invoice re-enabled successfully</div>
  <?php endif; ?>
  <?php if (!empty($_GET['payment']) && $_GET['payment'] === 'success'): ?>
    <div class="no-print" style="padding:8px 12px;background:#d1fae5;color:#065f46;border-radius:6px;margin-bottom:8px;font-size:14px">✓ Payment processed successfully! The invoice status will update shortly.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['payment']) && $_GET['payment'] === 'cancelled'): ?>
    <div class="no-print" style="padding:8px 12px;background:#fef3c7;color:#92400e;border-radius:6px;margin-bottom:8px;font-size:14px">Payment was cancelled.</div>
  <?php endif; ?>
  <?php if (!empty($_GET['stripe_error'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#fee2e2;color:#991b1b;border-radius:6px;margin-bottom:8px;font-size:14px">Stripe error: <?php echo htmlspecialchars($_GET['stripe_error']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_GET['date_updated'])): ?>
    <div class="no-print" style="padding:8px 12px;background:#dbeafe;color:#1e3a8a;border-radius:6px;margin-bottom:8px;font-size:14px">✓ Document date updated successfully</div>
  <?php endif; ?>
  <div class="no-print" style="padding:8px 12px;background:#f3f4f6;border-radius:6px;margin-bottom:8px;font-size:13px;color:#374151">
    <strong>Created:</strong> <?php echo !empty($inv['created_at']) ? date('M j, Y g:i A', strtotime($inv['created_at'])) : 'N/A'; ?>
    <span style="margin:0 8px">|</span>
    <strong>Document Date:</strong> <?php echo !empty($inv['document_date']) ? date('M j, Y g:i A', strtotime($inv['document_date'])) : 'N/A'; ?>
    <?php if (!empty($inv['document_date_updated_at'])): ?>
      <span style="margin-left:8px;color:#6b7280;font-size:12px">(Updated: <?php echo date('M j, Y g:i A', strtotime($inv['document_date_updated_at'])); ?>)</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php
    $brand = $appConfig['brand_name'] ?? 'Project Alpha';
    $logoConf = trim((string)($appConfig['logo_path'] ?? ''));
    $projectRoot = realpath(__DIR__ . '/../../../');
    $defaultLogo = $projectRoot ? ($projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'default-logo.png') : '';
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
        <?php if (!empty($inv['project_code'])): ?><div style="color:#374151;font-size:13px;margin-top:2px">Job <?php echo htmlspecialchars($inv['project_code']); ?></div><?php endif; ?>
        <?php if (!empty($inv['project_id'])): ?><div style="color:#374151;font-size:13px;margin-top:2px">Project <?php echo htmlspecialchars($inv['project_id']); ?></div><?php endif; ?>
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
    // Get custom fields for display
    $documentType = 'regular';
    
    $customFieldValues = !empty($inv['custom_fields']) ? json_decode($inv['custom_fields'], true) : [];
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
    $showFulfillmentDate = !empty($inv['fulfillment_date']);
  ?>
  <?php if ($hasCustomFields || $showFulfillmentDate): ?>
  <table style="width:100%;table-layout:fixed;margin-bottom:16px;border-collapse:collapse;border:1px solid #e5e7eb">
    <tr>
      <?php if ($showFulfillmentDate): ?>
      <td style="padding:8px;<?php echo $hasCustomFields ? 'border-right:1px solid #e5e7eb;' : ''; ?>vertical-align:top">
        <div style="font-size:11px;color:#6b7280">Fulfillment Date: <span style="font-weight:600;color:#2563eb"><?php echo date('M j, Y', strtotime($inv['fulfillment_date'])); ?></span></div>
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
        <div class="font-600">From</div>
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
            <?php if ($fromPhone): ?><div><?php echo htmlspecialchars(format_phone($fromPhone), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($fromEmail): ?><div><?php echo htmlspecialchars($fromEmail); ?></div><?php endif; ?>
          </div>
        <?php endif; ?>
      </td>
      <td style="vertical-align:top;width:50%;padding-left:12px">
        <div class="font-600">To</div>
        <?php 
          $toLines = [];
          if (!empty($inv['client_name'])) { $toLines[] = (string)$inv['client_name']; }
          if (!empty($inv['client_org'])) { $toLines[] = (string)$inv['client_org']; }
          if (!empty($inv['address_line1'])) { $toLines[] = (string)$inv['address_line1']; }
          if (!empty($inv['address_line2'])) { $toLines[] = (string)$inv['address_line2']; }
          $c = trim((string)($inv['city'] ?? ''));
          $s = trim((string)($inv['state'] ?? ''));
          $p = trim((string)($inv['postal_code'] ?? ''));
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
            <?php if (!empty($inv['client_phone'])): ?><div><?php echo htmlspecialchars(format_phone($inv['client_phone']), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endif; ?>
            <?php if (!empty($inv['client_email'])): ?><div><?php echo htmlspecialchars($inv['client_email']); ?></div><?php endif; ?>
          </div>
        <?php endif; ?>
      </td>
    </tr>
  </table>

  <?php if (!empty($projectNotes)): ?>
  <div style="margin:12px 0;padding:10px;border:1px solid #eee;border-radius:8px;background:#f8fafc">
          <div style="font-weight:600;margin-bottom:6px">Job Notes</div>
    <pre style="white-space:pre-wrap;margin:0"><?php echo htmlspecialchars($projectNotes); ?></pre>
  </div>
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
      <tr style="border-top:1px solid #f3f4f6<?php echo (int)($it['is_extra_charge'] ?? 0) ? ';background:#fffbeb' : ''; ?>">
        <td style="padding:10px;vertical-align:top;text-align:center">
          <div class="font-600"><?php echo htmlspecialchars($it['item'] ?? ''); ?></div>
          <?php if ((int)($it['is_extra_charge'] ?? 0) === 1): ?>
            <span style="display:inline-block;margin-top:4px;padding:2px 6px;background:#fbbf24;color:#92400e;border-radius:3px;font-size:10px;font-weight:600">Extra Charge</span>
          <?php endif; ?>
        </td>
        <td style="padding:10px;color:#6b7280;font-size:13px;vertical-align:top"><?php echo htmlspecialchars($it['description'] ?? ''); ?></td>
        <td style="padding:10px;text-align:right;vertical-align:top"><?php echo number_format($it['quantity'],2); ?></td>
        <td style="padding:10px;text-align:right;vertical-align:top">$<?php echo number_format($it['unit_price'],2); ?></td>
        <td style="padding:10px;text-align:right;vertical-align:top">$<?php echo number_format($it['line_total'],2); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Totals section - uses table for PDF compatibility -->
  <?php
    $invoiceTotal = (float)($inv['total'] ?? 0);
    // Calculate amount_paid from payments table for accuracy
    $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND status = "succeeded"');
    $paidStmt->execute([$id]);
    $amountPaid = (float)$paidStmt->fetchColumn();
    $amountDue = $invoiceTotal - $amountPaid;
    $invStatus = strtolower($inv['status'] ?? 'unpaid');
    $isPartial = $invStatus === 'partial';
    $isPaid = $invStatus === 'paid';
  ?>
  <table style="width:100%;border-collapse:collapse;margin-top:16px">
    <tr>
      <td style="width:60%"></td>
      <td style="width:40%">
        <table style="width:100%;border-collapse:collapse">
          <tr>
            <td style="padding:8px 10px;font-weight:600;text-align:right">Subtotal</td>
            <td style="padding:8px 10px;text-align:right;width:120px">$<?php echo number_format($inv['subtotal'],2); ?></td>
          </tr>
          <tr>
            <td style="padding:8px 10px;font-weight:600;text-align:right">Discount</td>
            <td style="padding:8px 10px;text-align:right">
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
            <td style="padding:8px 10px;font-weight:600;text-align:right">Tax</td>
            <td style="padding:8px 10px;text-align:right"><?php echo number_format($inv['tax_percent'],2); ?>%</td>
          </tr>
          <tr style="border-top:1px solid #e5e7eb">
            <td style="padding:8px 10px;font-weight:700;text-align:right"><?php echo $isPartial ? 'Invoice Total' : 'Total'; ?></td>
            <td style="padding:8px 10px;font-weight:700;text-align:right">$<?php echo number_format($invoiceTotal,2); ?></td>
          </tr>
          <?php if ($isPartial): ?>
          <!-- Only show payment breakdown for partial invoices -->
          <tr style="background:#ecfdf5">
            <td style="padding:8px 10px;font-weight:600;text-align:right;color:#065f46">Amount Paid</td>
            <td style="padding:8px 10px;text-align:right;color:#065f46">- $<?php echo number_format($amountPaid,2); ?></td>
          </tr>
          <tr style="background:#fef3c7;border-top:2px solid #f59e0b">
            <td style="padding:10px;font-weight:700;text-align:right;color:#92400e;font-size:15px">Amount Due</td>
            <td style="padding:10px;font-weight:700;text-align:right;color:#92400e;font-size:15px">$<?php echo number_format($amountDue,2); ?></td>
          </tr>
          <?php elseif ($isPaid): ?>
          <!-- Paid in full -->
          <tr style="background:#ecfdf5;border-top:2px solid #10b981">
            <td style="padding:10px;font-weight:700;text-align:right;color:#065f46;font-size:15px">✓ Paid in Full</td>
            <td style="padding:10px;font-weight:700;text-align:right;color:#065f46;font-size:15px">$0.00</td>
          </tr>
          <?php endif; ?>
          <?php // For unpaid invoices, the Total row above is sufficient ?>
        </table>
      </td>
    </tr>
  </table>
</section>

<?php
  // Review link section - show if configured and invoice is paid
  $reviewLink = trim($appConfig['review_link'] ?? '');
  if ($reviewLink !== '' && $isPaid):
?>
<div style="margin-top:24px;padding:16px;background:linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);border-radius:12px;text-align:center">
  <div style="font-size:16px;font-weight:600;color:#92400e;margin-bottom:8px">⭐ Enjoyed our service?</div>
  <div style="color:#78350f;margin-bottom:12px">We'd love to hear your feedback!</div>
  <a href="<?php echo htmlspecialchars($reviewLink); ?>" target="_blank" rel="noopener" style="display:inline-block;padding:10px 20px;background:#f59e0b;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Leave a Review</a>
</div>
<?php endif; ?>

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

<!-- Share Link Modal -->
<div id="shareLinkModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:24px;max-width:500px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <h3 style="margin:0;font-size:18px">🔗 Share Invoice Link</h3>
      <button onclick="closeShareModal()" style="border:0;background:none;font-size:20px;cursor:pointer;color:#6b7280">&times;</button>
    </div>
    <div id="shareLinkContent">
      <p style="color:#6b7280;margin:0 0 16px">Generate a public link that clients can use to view and pay this invoice.</p>
      <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;cursor:pointer">
        <input type="checkbox" id="expireWhenPaid" onchange="toggleDaysInput()" style="width:18px;height:18px">
        <span style="font-weight:500">Expire when invoice is paid in full</span>
      </label>
      <label id="daysLabel" style="display:block;margin-bottom:12px">
        <div style="font-weight:500;margin-bottom:4px">Link expires in (days)</div>
        <input type="number" id="linkDays" value="<?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?>" min="1" max="365" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
      </label>
      <button onclick="createPublicLink()" style="width:100%;padding:12px;background:#4f46e5;color:#fff;border:0;border-radius:8px;font-weight:600;cursor:pointer">Generate Link</button>
    </div>
    <div id="shareLinkResult" style="display:none">
      <div style="padding:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;margin-bottom:12px">
        <div style="font-weight:600;color:#166534;margin-bottom:4px" id="linkStatus">✓ Link Generated!</div>
        <div style="font-size:13px;color:#15803d" id="linkExpiry"></div>
      </div>
      <div style="position:relative">
        <input type="text" id="generatedLink" readonly style="width:100%;padding:10px;padding-right:80px;border:1px solid #ddd;border-radius:8px;font-size:13px;background:#f9fafb">
        <button onclick="copyLink()" style="position:absolute;right:4px;top:4px;padding:6px 12px;background:#4f46e5;color:#fff;border:0;border-radius:6px;font-size:12px;cursor:pointer">Copy</button>
      </div>
      <div style="margin-top:12px;display:flex;gap:8px">
        <button id="revokeBtn" onclick="revokeAndCreateNew()" style="flex:1;padding:10px;background:#fee2e2;color:#991b1b;border:0;border-radius:8px;cursor:pointer;display:none">Revoke & Create New</button>
        <button onclick="closeShareModal()" style="flex:1;padding:10px;background:#4f46e5;color:#fff;border:0;border-radius:8px;cursor:pointer">Done</button>
      </div>
    </div>
  </div>
</div>

<script>
function generatePublicLink() {
  document.getElementById('shareLinkModal').style.display = 'flex';
  
  // First, check if a link already exists
  const formData = new FormData();
  formData.append('type', 'invoice');
  formData.append('id', '<?php echo (int)$id; ?>');
  formData.append('days', '<?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?>');
  formData.append('expire_when_paid', '0');
  formData.append('csrf', '<?php echo htmlspecialchars(csrf_token()); ?>');
  
  fetch('/?page=public-link-create', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success && data.existing) {
      // Show the existing link directly
      document.getElementById('generatedLink').value = data.url;
      document.getElementById('linkStatus').textContent = '\u2713 Existing Link';
      document.getElementById('revokeBtn').style.display = 'block';
      
      if (data.expire_when_paid) {
        document.getElementById('linkExpiry').textContent = 'Expires when invoice is paid in full';
      } else {
        document.getElementById('linkExpiry').textContent = 'Expires: ' + data.expires_at + ' (' + data.expires_in_days + ' days remaining)';
      }
      
      document.getElementById('shareLinkContent').style.display = 'none';
      document.getElementById('shareLinkResult').style.display = 'block';
    } else {
      // No existing link, show the create form
      document.getElementById('shareLinkContent').style.display = 'block';
      document.getElementById('shareLinkResult').style.display = 'none';
      document.getElementById('expireWhenPaid').checked = false;
      toggleDaysInput();
    }
  })
  .catch(err => {
    // On error, show the create form
    document.getElementById('shareLinkContent').style.display = 'block';
    document.getElementById('shareLinkResult').style.display = 'none';
    document.getElementById('expireWhenPaid').checked = false;
    toggleDaysInput();
  });
}

function closeShareModal() {
  document.getElementById('shareLinkModal').style.display = 'none';
}

function toggleDaysInput() {
  const expireWhenPaid = document.getElementById('expireWhenPaid').checked;
  const daysLabel = document.getElementById('daysLabel');
  const daysInput = document.getElementById('linkDays');
  if (expireWhenPaid) {
    daysLabel.style.opacity = '0.5';
    daysInput.disabled = true;
  } else {
    daysLabel.style.opacity = '1';
    daysInput.disabled = false;
  }
}

function createPublicLink() {
  const expireWhenPaid = document.getElementById('expireWhenPaid').checked;
  const days = document.getElementById('linkDays').value || 14;
  const formData = new FormData();
  formData.append('type', 'invoice');
  formData.append('id', '<?php echo (int)$id; ?>');
  formData.append('days', days);
  formData.append('expire_when_paid', expireWhenPaid ? '1' : '0');
  formData.append('csrf', '<?php echo htmlspecialchars(csrf_token()); ?>');
  
  fetch('/?page=public-link-create', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      document.getElementById('generatedLink').value = data.url;
      
      // Update status and expiry display
      const statusEl = document.getElementById('linkStatus');
      const expiryEl = document.getElementById('linkExpiry');
      const revokeBtn = document.getElementById('revokeBtn');
      
      if (data.existing) {
        statusEl.textContent = '✓ Existing Link Found';
        revokeBtn.style.display = 'block';
      } else {
        statusEl.textContent = '✓ Link Generated!';
        revokeBtn.style.display = 'none';
      }
      
      if (data.expire_when_paid) {
        expiryEl.textContent = 'Expires when invoice is paid in full';
      } else {
        expiryEl.textContent = 'Expires: ' + data.expires_at + ' (' + data.expires_in_days + ' days)';
      }
      
      document.getElementById('shareLinkContent').style.display = 'none';
      document.getElementById('shareLinkResult').style.display = 'block';
    } else {
      alert('Error: ' + (data.error || 'Failed to generate link'));
    }
  })
  .catch(err => {
    alert('Error generating link: ' + err.message);
  });
}

function copyLink() {
  const input = document.getElementById('generatedLink');
  input.select();
  document.execCommand('copy');
  const btn = event.target;
  btn.textContent = 'Copied!';
  setTimeout(() => { btn.textContent = 'Copy'; }, 2000);
}

function revokeAndCreateNew() {
  if (!confirm('This will revoke the existing link (it will no longer work). Continue?')) {
    return;
  }
  
  // Revoke the existing link
  const formData = new FormData();
  formData.append('type', 'invoice');
  formData.append('id', '<?php echo (int)$id; ?>');
  formData.append('csrf', '<?php echo htmlspecialchars(csrf_token()); ?>');
  
  fetch('/?page=public-link-revoke', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      // Reset to creation form view
      document.getElementById('shareLinkContent').style.display = 'block';
      document.getElementById('shareLinkResult').style.display = 'none';
      document.getElementById('generatedLink').value = '';
      document.getElementById('linkStatus').textContent = '✓ Link Generated!';
      document.getElementById('revokeBtn').style.display = 'none';
      document.getElementById('linkExpiry').textContent = '';
      // Reset to default days
      document.getElementById('linkDays').value = '<?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?>';
      document.getElementById('expireWhenPaid').checked = false;
      toggleDaysInput();
    } else {
      alert('Error: ' + (data.error || 'Failed to revoke link'));
    }
  })
  .catch(err => {
    alert('Error: ' + err.message);
  });
}

// Close modal on outside click
document.getElementById('shareLinkModal').addEventListener('click', function(e) {
  if (e.target === this) closeShareModal();
});
</script>
