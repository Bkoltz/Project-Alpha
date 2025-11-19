<?php
// src/views/pages/quote/quote-print-wrapper.php
// Thin wrapper: prepare data and render the Twig template `pages/quote/quote-print.twig`.
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/format.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT q.*, c.name client_name, c.organization client_org, c.email client_email, c.phone client_phone, c.address_line1, c.address_line2, c.city, c.state, c.postal, c.country FROM quotes q JOIN clients c ON c.id=q.client_id WHERE q.id=?');
$stmt->execute([$id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$quote) { echo '<p>Quote not found</p>'; return; }
$itemsSt = $pdo->prepare('SELECT description, quantity, unit_price, line_total FROM quote_items WHERE quote_id=?');
$itemsSt->execute([$id]);
$items = $itemsSt->fetchAll();

// Derived values
$fromName = ($appConfig['from_name'] ?? '') ?: ($appConfig['brand_name'] ?? 'Project Alpha');
$fromPhone = $appConfig['from_phone'] ?? '';
$fromEmail = $appConfig['from_email'] ?? '';

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

// Logo resolution / data URI
$brand = $appConfig['brand_name'] ?? 'Project Alpha';
$logoConf = trim((string)($appConfig['logo_path'] ?? ''));
$projectRoot = realpath(__DIR__ . '/../../../../');
$defaultLogo = $projectRoot ? ($projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'default-logo.svg') : '';
$logoPath = $logoConf !== '' ? $logoConf : $defaultLogo;
$isUrl = preg_match('/^(https?:\/\/|data:)/i', $logoPath) === 1;
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
if (!$isUrl) {
  $root = $projectRoot ?: realpath(__DIR__ . '/../../../../');
  if ($logoPath !== '' && ($logoPath[0] === '/' || $logoPath[0] === '\\')) {
    if ($root) {
      $candidate = @realpath($root . $logoPath);
      if ($candidate) { $logoPath = $candidate; }
    }
  } else {
    if ($root) {
      $candidate = @realpath($root . DIRECTORY_SEPARATOR . $logoPath);
      if ($candidate) { $logoPath = $candidate; }
    }
  }
}
$canShowLogo = $isUrl || @is_file($logoPath);
$logoSrc = $logoPath;
if ($canShowLogo && !$isUrl) {
  $imgContents = @file_get_contents($logoPath);
  if ($imgContents !== false) {
    $mime = null;
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
    $normalized = str_replace('\\', '/', $logoPath);
    if (preg_match('/^[A-Za-z]:\//', $normalized) === 1 || strpos($normalized, '/') === 0) {
      $logoSrc = 'file:///' . ltrim($normalized, '/');
    }
  }
}

// Try rendering via Twig
$rendered = false;
$autoload = '';
$dir = __DIR__;
for ($i = 0; $i < 8; $i++) {
  $candidate = $dir . '/vendor/autoload.php';
  if (@is_file($candidate)) { $autoload = $candidate; break; }
  $parent = dirname($dir);
  if ($parent === $dir) break;
  $dir = $parent;
}
if ($autoload) {
  require_once $autoload;
  if (class_exists('\Twig\Environment')) {
    try {
      $loaderPath = $projectRoot ? ($projectRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'views') : __DIR__ . '/../../..';
      $loader = new \Twig\Loader\FilesystemLoader($loaderPath);
      $twig = new \Twig\Environment($loader, ['cache' => false]);
      echo $twig->render('pages/quote/quote-print.twig', [
        'appConfig' => $appConfig,
        'quote' => $quote,
        'items' => $items,
        'fromName' => $fromName,
        'fromPhone' => $fromPhone,
        'fromEmail' => $fromEmail,
        'depositCalc' => $depositCalc,
        'fulfillmentDate' => $fulfillmentDate,
        'showDepositInfo' => $showDepositInfo,
        'showFulfillmentDate' => $showFulfillmentDate,
        'logoPath' => $logoSrc,
        'scopeText' => trim((string)($quote['scope'] ?? '')),
        'scopeEnabled' => !isset($appConfig['quote_scope_enabled']) || !empty($appConfig['quote_scope_enabled']),
        'termsText' => $termsText,
      ]);
      $rendered = true;
    } catch (Throwable $e) {
      // fall through to fallback
    }
  }
}

if (!$rendered) {
  // Minimal fallback to keep functionality when Twig is not present
  ?>
  <section>
    <div class="doc-type" style="text-align:center;font-weight:700;font-size:22px;margin-bottom:6px">Quote</div>
    <div style="text-align:center;color:#6b7280;margin-bottom:6px;font-size:13px">Valid for <?php echo (int)($appConfig['documents_valid_days'] ?? 14); ?> days</div>
    <div style="margin:8px 0;padding:8px;border:1px solid #eee;border-radius:8px;background:#fff">
      <strong>Client:</strong> <?php echo htmlspecialchars($quote['client_name'] ?? ''); ?>
      <div><strong>Total:</strong> $<?php echo number_format((float)($quote['total'] ?? 0), 2); ?></div>
    </div>
  </section>
  <?php
}
