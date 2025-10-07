<?php
// src/controllers/email_send.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/crypto.php';
require_once __DIR__ . '/../utils/smtp.php';
require_once __DIR__ . '/../utils/logger.php';

// PDF generation temporarily disabled (dompdf)
$dompdfAvailable = false;

$type = $_POST['type'] ?? '';
$id = (int)($_POST['id'] ?? 0);
$redirectTo = isset($_POST['redirect_to']) ? (string)$_POST['redirect_to'] : null;
if (!in_array($type, ['quote','contract','invoice'], true) || $id <= 0) {
  $toUrl = $redirectTo ?: '/?page=home';
  header('Location: ' . $toUrl . (strpos($toUrl,'?')!==false?'&':'?') . 'email_err=' . urlencode('Invalid email request'));
  exit;
}

try {
  if ($type === 'quote') {
    $st = $pdo->prepare('SELECT q.id, q.doc_number, q.project_code, c.email, c.name FROM quotes q JOIN clients c ON c.id=q.client_id WHERE q.id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $docnum = (string)($row['doc_number'] ?? $row['id'] ?? '');
    $clientName = (string)($row['name'] ?? 'client');
    $subject = 'Quote Q-' . $docnum . ' for ' . $clientName;
    $printUrl = '/?page=quote-print&id='.$id;
  } elseif ($type === 'contract') {
    $st = $pdo->prepare('SELECT co.id, co.doc_number, co.project_code, c.email, c.name FROM contracts co JOIN clients c ON c.id=co.client_id WHERE co.id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $docnum = (string)($row['doc_number'] ?? $row['id'] ?? '');
    $clientName = (string)($row['name'] ?? 'client');
    $subject = 'Contract C-' . $docnum . ' for ' . $clientName;
    $printUrl = '/?page=contract-print&id='.$id;
  } else { // invoice
    $st = $pdo->prepare('SELECT i.id, i.doc_number, i.project_code, c.email, c.name FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $docnum = (string)($row['doc_number'] ?? $row['id'] ?? '');
    $clientName = (string)($row['name'] ?? 'client');
    $subject = 'Invoice I-' . $docnum . ' for ' . $clientName;
    $printUrl = '/?page=invoice-print&id='.$id;
  }

  if (!$row || empty($row['email'])) {
    $toUrl = $redirectTo ?: $printUrl;
    app_log('email', 'missing client email', ['type'=>$type, 'id'=>$id]);
    header('Location: '.$toUrl.(strpos($toUrl,'?')!==false?'&':'?').'email_err=' . urlencode('No client email on file'));
    exit;
  }

  $to = $row['email'];

  // Compose body
  $body = '<p>Hello '.htmlspecialchars($row['name']).',</p>' .
          '<p>Please find your document at the link below:</p>' .
          '<p><a href="'.htmlspecialchars($printUrl).'">View Document</a></p>' .
          '<p>Thank you.</p>';

  // Try SMTP if configured
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
    [$ok, $msg] = smtp_send($cfg, $to, $subject, $body, $fromEmail, $fromName);
    $sent = $ok; $err = $ok ? '' : ($msg ?: 'SMTP send failed');
  }

  if (!$sent) {
    // Fallback: PHP mail()
    $headers = "MIME-Version: 1.0\r\n" .
               "Content-type: text/html; charset=UTF-8\r\n" .
               "From: ".($fromName?($fromName.' <'.$fromEmail.'>'):$fromEmail)."\r\n";
    $mailOk = @mail($to, $subject, $body, $headers);
    $sent = $sent || $mailOk;
    if (!$sent && $err === '') { $err = 'Email send failed'; }
  }

  $toUrl = $redirectTo ?: $printUrl;
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
