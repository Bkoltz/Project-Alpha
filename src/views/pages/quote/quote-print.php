<?php
// src/views/pages/quote-print.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/format.php';
require_once __DIR__ . '/../../../utils/csrf.php';
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT q.*, c.name client_name, c.organization client_org, c.email client_email, c.phone client_phone, c.address_line1, c.address_line2, c.city, c.state, c.postal, c.country FROM quotes q JOIN clients c ON c.id=q.client_id WHERE q.id=?');
$stmt->execute([$id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$quote){ echo '<p>Quote not found</p>'; return; }
$items = $pdo->prepare('SELECT description, quantity, unit_price, line_total FROM quote_items WHERE quote_id=?');
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
if ($termsText === '') { $termsText = trim((string)($appConfig['terms'] ?? '')); }
// Detect PDF mode for conditional page breaks
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

            // Try rendering via Twig if available
            $rendered = false;
            $projectRoot = $projectRoot ?: realpath(__DIR__ . '/../../../../');
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
              if (class_exists('\\Twig\\Environment')) {
                try {
                  $loaderPath = $projectRoot ? ($projectRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'views') : __DIR__ . '/../../..';
                  $loader = new \\Twig\\Loader\\FilesystemLoader($loaderPath);
                  $twig = new \\Twig\\Environment($loader, ['cache' => false]);
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
                  // Twig failed, fall through to simple fallback
                }
              }
            }

            if (!$rendered) {
              // Minimal fallback HTML rendering (keeps feature working when Twig is not installed)
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
