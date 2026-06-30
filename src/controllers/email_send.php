<?php
// src/controllers/email_send.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/crypto.php';
require_once __DIR__ . '/../utils/smtp.php';
require_once __DIR__ . '/../utils/logger.php';
require_once __DIR__ . '/../utils/mailer.php';
require_once __DIR__ . '/../utils/acl.php';
require_once __DIR__ . '/../utils/invoice_content_links.php';

// Determine if Dompdf is available
$dompdfAvailable = is_file(__DIR__ . '/../../vendor/autoload.php');

$type = $_POST['type'] ?? '';
$id = (int)($_POST['id'] ?? 0);
$redirectTo = isset($_POST['redirect_to']) ? (string)$_POST['redirect_to'] : null;
if (!in_array($type, ['quote','contract','invoice'], true) || $id <= 0) {
  $toUrl = $redirectTo ?: '/?page=home';
  header('Location: ' . $toUrl . (strpos($toUrl,'?')!==false?'&':'?') . 'email_err=' . urlencode('Invalid email request'));
  exit;
}

$ownershipTable = ['quote' => 'quotes', 'contract' => 'contracts', 'invoice' => 'invoices'][$type];
require_record_ownership($pdo, $ownershipTable, $id);

try {
  if ($type === 'quote') {
    $st = $pdo->prepare('SELECT q.id, q.doc_number, q.project_code, q.status, c.email, c.name FROM quotes q JOIN clients c ON c.id=q.client_id WHERE q.id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $docnum = (string)($row['doc_number'] ?? $row['id'] ?? '');
    $clientName = (string)($row['name'] ?? 'client');
    $subject = 'Quote Q-' . $docnum . ' for ' . $clientName;
    $baseView = '/?page=quote-print&id='.$id;
  } elseif ($type === 'contract') {
    $st = $pdo->prepare('SELECT co.id, co.doc_number, co.project_code, co.status, c.email, c.name FROM contracts co JOIN clients c ON c.id=co.client_id WHERE co.id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $docnum = (string)($row['doc_number'] ?? $row['id'] ?? '');
    $clientName = (string)($row['name'] ?? 'client');
    $subject = 'Contract C-' . $docnum . ' for ' . $clientName;
    $baseView = '/?page=contract-print&id='.$id;
  } else { // invoice
    $st = $pdo->prepare('SELECT i.id, i.doc_number, i.project_code, i.status, c.email, c.name FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $docnum = (string)($row['doc_number'] ?? $row['id'] ?? '');
    $clientName = (string)($row['name'] ?? 'client');
    $subject = 'Invoice I-' . $docnum . ' for ' . $clientName;
    $baseView = '/?page=invoice-print&id='.$id;
  }

  if (!$row || empty($row['email'])) {
    $toUrl = $redirectTo ?: $baseView;
    app_log('email', 'missing client email', ['type'=>$type, 'id'=>$id]);
    header('Location: '.$toUrl.(strpos($toUrl,'?')!==false?'&':'?').'email_err=' . urlencode('No client email on file'));
    exit;
  }

  // Block emailing for non-emailable statuses
  $st = strtolower((string)($row['status'] ?? ''));
  if (($type==='quote' && $st==='rejected') || ($type==='invoice' && in_array($st, ['draft','void','cancelled'], true)) || ($type==='contract' && in_array($st, ['denied','cancelled','void'], true))) {
    $toUrl = $redirectTo ?: $baseView;
    header('Location: '.$toUrl.(strpos($toUrl,'?')!==false?'&':'?').'email_err=' . urlencode('Document status does not allow emailing'));
    exit;
  }
  if ($type === 'invoice') {
    $eligibility = $pdo->prepare('SELECT finalized_at, collection_mode FROM invoices WHERE id=?');
    $eligibility->execute([$id]);
    $invoiceEligibility = $eligibility->fetch(PDO::FETCH_ASSOC);
    if (!$invoiceEligibility || empty($invoiceEligibility['finalized_at']) || ($invoiceEligibility['collection_mode'] ?? 'direct') !== 'direct') {
      $toUrl = $redirectTo ?: $baseView;
      header('Location: '.$toUrl.(strpos($toUrl,'?')!==false?'&':'?').'email_err=' . urlencode('Finalize this invoice before emailing it. Project-billed invoices are sent through the project statement.'));
      exit;
    }
  }

  $to = $row['email'];

  // First name from client name
  $clientName = trim((string)($row['name'] ?? ''));
  $firstName = $clientName !== '' ? preg_split('/\s+/', $clientName)[0] : 'there';

  // Create/ensure public link table exists
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS public_links (
      id INT AUTO_INCREMENT PRIMARY KEY,
      token VARCHAR(64) NOT NULL,
      document_type VARCHAR(50) NOT NULL,
      document_id INT NOT NULL,
      redirect VARCHAR(255) NULL,
      expires_at DATETIME NULL,
      expire_when_paid TINYINT(1) NOT NULL DEFAULT 0,
      revoked TINYINT(1) NOT NULL DEFAULT 0,
      access_count INT NOT NULL DEFAULT 0,
      last_accessed_at TIMESTAMP NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_public_token (token),
      INDEX idx_public_type_document (document_type, document_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
  } catch (Throwable $e) { /* ignore */ }
  try {
    $pdo->exec('ALTER TABLE public_links MODIFY COLUMN expires_at DATETIME NULL');
  } catch (Throwable $e) { /* ignore */ }
  try {
    $pdo->exec('ALTER TABLE public_links ADD COLUMN expire_when_paid TINYINT(1) NOT NULL DEFAULT 0');
  } catch (Throwable $e) { /* ignore */ }

  // Insert a fresh token for this share (do not reuse old links).
  // Invoice links stay valid until payment or manual revocation; other
  // document links keep the configured date-based expiration.
  $token = bin2hex(random_bytes(16));
  $days = isset($appConfig['documents_valid_days']) ? (int)$appConfig['documents_valid_days'] : 14;
  if ($days <= 0) { $days = 14; }
  $expireWhenPaid = $type === 'invoice';
  $exp = $expireWhenPaid ? null : date('Y-m-d H:i:s', time() + ($days * 24 * 60 * 60));
  try {
  $ins = $pdo->prepare('INSERT INTO public_links (document_type, document_id, token, redirect, expires_at, expire_when_paid) VALUES (?,?,?,?,?,?)');
  // No redirect by default; callers may update this row later if desired
  $ins->execute([$type, $id, $token, null, $exp, $expireWhenPaid ? 1 : 0]);
  } catch (Throwable $e) { /* ignore */ }

  // Build absolute URL to public view
  // Use configured app_host first (user's domain), fall back to HTTP_HOST (internal IP)
  $host = '';
  if (!empty($appConfig['app_host'])) { $host = (string)$appConfig['app_host']; }
  if ($host === '') { $host = $_SERVER['HTTP_HOST'] ?? ''; }
  if ($host === '') { $host = 'localhost'; }
  // Detect scheme: check HTTPS, then X-Forwarded-Proto (Cloudflare proxy), then app_host prefix
  $scheme = 'http';
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') { $scheme = 'https'; }
  elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strpos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0) { $scheme = 'https'; }
  elseif (!empty($appConfig['app_host']) && (strpos((string)$appConfig['app_host'], 'https://') === 0 || strpos((string)$appConfig['app_host'], 'http://') === 0)) {
    // app_host includes scheme — extract it and strip from host
    $parsedHost = parse_url((string)$appConfig['app_host']);
    if (isset($parsedHost['scheme'])) { $scheme = $parsedHost['scheme']; }
    if (isset($parsedHost['host'])) { $host = $parsedHost['host']; if (!empty($parsedHost['port'])) { $host .= ':' . $parsedHost['port']; } }
  }
  $publicUrl = '/?page=public-doc&token=' . rawurlencode($token);
  $absoluteUrl = $scheme . '://' . $host . $publicUrl;

  // Compose body
  $body = '<p>Hello '.htmlspecialchars($firstName).',</p>' .
    '<p>Please find your document attached and available at the link below:</p>' .
    '<p><a href="'.htmlspecialchars($absoluteUrl).'">View Document</a></p>';

  // Add Pay via Credit Card button for invoices if Stripe is configured
  if ($type === 'invoice') {
    $contentLinks = invoice_content_links_for_invoice($pdo, $id, $appConfig);
    $contentLinksHtml = invoice_content_links_html($contentLinks);
    if ($contentLinksHtml !== '') {
      $body .= $contentLinksHtml;
    }

    require_once __DIR__ . '/../services/StripeService.php';
    if (StripeService::isConfigured($appConfig)) {
      // Check if invoice is payable
      try {
        $invSt = $pdo->prepare('SELECT status, total, amount_paid FROM invoices WHERE id = ?');
        $invSt->execute([$id]);
        $invRow = $invSt->fetch(PDO::FETCH_ASSOC);
        if ($invRow) {
          $invStatus = strtolower($invRow['status'] ?? '');
          $amountDue = (float)($invRow['total'] ?? 0) - (float)($invRow['amount_paid'] ?? 0);
          if (in_array($invStatus, ['sent', 'unpaid', 'partial', 'overdue'], true) && $amountDue > 0) {
            $payUrl = $scheme . '://' . $host . '/?page=stripe-checkout&token=' . rawurlencode($token);
            $body .= '<div style="margin:24px 0;padding:20px;background:#f0f7ff;border:1px solid #93c5fd;border-radius:8px;text-align:center">';
            $body .= '<p style="margin:0 0 12px;color:#1e40af;font-weight:600;font-size:16px">Ready to pay? Use our secure online payment option:</p>';
            $body .= '<a href="'.htmlspecialchars($payUrl).'" style="display:inline-block;padding:12px 24px;background:#4f46e5;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">💳 Pay via Credit Card</a>';
            $body .= '<p style="margin:12px 0 0;color:#6b7280;font-size:13px">Secure payment powered by Stripe</p>';
            $body .= '</div>';
          }
        }
      } catch (Throwable $e) { /* ignore */ }
    }
  }

  $expiryCopy = $expireWhenPaid
    ? 'This invoice link remains active until the invoice is paid in full or manually revoked.'
    : 'This link will expire in ' . htmlspecialchars((string)$days) . ' day' . ($days===1?'':'s') . '.';
  $body .= '<p>' . $expiryCopy . ' Do not share this link with untrusted parties!</p>' .
    '<p>Thank you.</p>';

  // Optionally render PDF attachment using Dompdf
  $attachments = [];
  if ($dompdfAvailable) {
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (is_file($autoload)) {
      require_once $autoload;
      if (!class_exists('Dompdf\\Cpdf')) {
        $cpdf = __DIR__ . '/../../vendor/dompdf/dompdf/lib/Cpdf.php';
        if (is_file($cpdf)) { require_once $cpdf; }
      }
      try {
        $viewFile = null;
  if ($type === 'quote') { $viewFile = __DIR__ . '/../views/pages/quote/quote-details.php'; }
  elseif ($type === 'contract') { $viewFile = __DIR__ . '/../views/pages/contract/contract-details.php'; }
  else { $viewFile = __DIR__ . '/../views/pages/invoice/invoice-details.php'; }
        if (is_file($viewFile)) {
          ob_start();
          if (!defined('PDF_MODE')) define('PDF_MODE', true);
          $_GET['id'] = (string)$id;
          require $viewFile;
          $content = ob_get_clean();

          $brand = htmlspecialchars($appConfig['brand_name'] ?? 'Project Alpha');
          $html = "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><title>Document - {$brand}</title>\n<style>\n  @page { margin: 72px 54px 72px 54px; }\n  body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 12px; color: #111; }\n</style>\n</head><body>" . $content . "</body></html>";

          $options = new Dompdf\Options();
          $options->set('isRemoteEnabled', true);
          $options->set('isHtml5ParserEnabled', true);
          $projectRoot = realpath(__DIR__ . '/..' . '/..');
          if ($projectRoot) { $options->set('chroot', $projectRoot); }
          $dompdf = new Dompdf\Dompdf($options);
          if ($projectRoot) {
            $publicDir = realpath($projectRoot . DIRECTORY_SEPARATOR . 'public');
            if ($publicDir) { $dompdf->setBasePath($publicDir); } else { $dompdf->setBasePath($projectRoot); }
          }
          $dompdf->setProtocol('file://');
          $dompdf->loadHtml($html, 'UTF-8');
          $dompdf->setPaper('letter', 'portrait');
          $dompdf->render();
          // Add header (date + page X of Y) and footer: powered-by text on every page
          try {
            $canvas = $dompdf->getCanvas();
            $font = $dompdf->getFontMetrics()->getFont('Helvetica', 'normal');
            // Header: date at top-left, page X of Y at top-right
            $w = $canvas->get_width();
            $dateStr = date('m/d/Y');
            $canvas->page_text(54, 22, $dateStr, $font, 10, [0,0,0]);
            $pageText = 'Page {PAGE_NUM} of {PAGE_COUNT}';
            $canvas->page_text($w - 140, 22, $pageText, $font, 10, [0,0,0]);
            // Footer
            $h = $canvas->get_height();
            $canvas->page_text(54, $h - 30, 'Powered by Project Alpha', $font, 10, [0,0,0]);
          } catch (Throwable $bt) {
            // ignore canvas failures and continue sending without header/footer
          }
          $pdfBinary = $dompdf->output();
          $prefix = $type === 'quote' ? 'quote_Q-' : ($type === 'contract' ? 'contract_C-' : 'invoice_I-');
          $filename = $prefix . ($docnum ?: $id) . '.pdf';
          $attachments[] = ['filename'=>$filename, 'content'=>$pdfBinary, 'mime'=>'application/pdf'];
        }
      } catch (Throwable $e) {
        // If PDF fails, continue sending without attachment
        app_log('email', 'pdf attach failed', ['type'=>$type, 'id'=>$id, 'ex'=>$e->getMessage()]);
      }
    }
  }

  // Try PHPMailer first (supports attachments), else fallback to SMTP client, else mail()
  $smtpHost = $appConfig['smtp_host'] ?? null;
  $fromEmail = $appConfig['from_email'] ?? '';
  $fromName = $appConfig['from_name'] ?? 'Project Alpha';
  $sent = false; $err = '';
  if ($smtpHost) {
    $pass = '';
    if (!empty($appConfig['smtp_password_enc']) && is_string($appConfig['smtp_password_enc'])) {
      $encVal = $appConfig['smtp_password_enc'];
      if (strpos($encVal, 'plain::') === 0) {
        $pass = substr($encVal, 7);
      } else {
        $pt = crypto_decrypt($encVal);
        if (is_string($pt)) { $pass = $pt; }
      }
    }
    $username = (string)($appConfig['smtp_username'] ?? '');
    if ($fromEmail === '' && $username !== '') { $fromEmail = $username; }
    if ($fromEmail === '') { $fromEmail = 'no-reply@localhost'; }
    $cfg = [
      'host' => $smtpHost,
      'port' => (int)($appConfig['smtp_port'] ?? 587),
      'secure' => strtolower((string)($appConfig['smtp_secure'] ?? 'tls')),
      'username' => $username,
      'password' => $pass,
    ];

    // Prefer PHPMailer if available
    [$ok, $msg] = mailer_send($cfg, $to, $subject, $body, $fromEmail, $fromName, ($username ?: $fromEmail), $attachments);
    if (!$ok) {
      // Fallback minimal SMTP without attachments
      [$ok2, $msg2] = smtp_send($cfg, $to, $subject, $body, $fromEmail, $fromName, ($username ?: $fromEmail));
      $ok = $ok2; $msg = $ok2 ? '' : ($msg2 ?: $msg);
    }
    $sent = $ok; $err = $ok ? '' : ($msg ?: 'SMTP send failed');
  }

  if (!$sent) {
    // Fallback: PHP mail() without attachment
    $headers = "MIME-Version: 1.0\r\n" .
               "Content-type: text/html; charset=UTF-8\r\n" .
               "From: ".($fromName?($fromName.' <'.$fromEmail.'>'):$fromEmail)."\r\n";
    $mailOk = @mail($to, $subject, $body, $headers);
    $sent = $sent || $mailOk;
    if (!$sent && $err === '') { $err = 'Email send failed'; }
  }

  $toUrl = $redirectTo ?: $baseView;
  $join = (strpos($toUrl,'?')!==false)?'&':'?';
  if ($sent) {
    app_log('email', 'email sent', ['type'=>$type, 'id'=>$id, 'to'=>$to]);
  } else {
    app_log('email', 'email failed', ['type'=>$type, 'id'=>$id, 'error'=>$err]);
  }
  header('Location: ' . $toUrl . $join . ($sent ? 'emailed=1' : ('email_err=' . urlencode($err))));
  exit;
} catch (Throwable $e) {
  app_log('email', 'email exception', ['type'=>$type, 'id'=>$id, 'ex'=>$e->getMessage()]);
  $toUrl = $redirectTo ?: '/?page=home';
  $join = (strpos($toUrl,'?')!==false)?'&':'?';
  header('Location: ' . $toUrl . $join . 'email_err=' . urlencode('Email failed'));
  exit;
}
