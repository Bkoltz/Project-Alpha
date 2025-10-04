<?php
// src/controllers/email_send.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/crypto.php';
require_once __DIR__ . '/../utils/smtp.php';

// PDF generation temporarily disabled (dompdf)
$dompdfAvailable = false;

$type = $_POST['type'] ?? '';
$id = (int)($_POST['id'] ?? 0);
if (!in_array($type, ['quote','contract','invoice'], true) || $id <= 0) {
  header('Location: /?page=home&error=Invalid%20email%20request');
  exit;
}

try {
  if ($type === 'quote') {
    $st = $pdo->prepare('SELECT q.id, q.doc_number, q.project_code, c.email, c.name FROM quotes q JOIN clients c ON c.id=q.client_id WHERE q.id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $subject = 'Quote Q-'.(($row['doc_number'] ?? $row['id'])).' from Project Alpha';
    $printUrl = '/?page=quote-print&id='.$id;
  } elseif ($type === 'contract') {
    $st = $pdo->prepare('SELECT co.id, co.doc_number, co.project_code, c.email, c.name FROM contracts co JOIN clients c ON c.id=co.client_id WHERE co.id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $subject = 'Contract C-'.(($row['doc_number'] ?? $row['id'])).' from Project Alpha';
    $printUrl = '/?page=contract-print&id='.$id;
  } else { // invoice
    $st = $pdo->prepare('SELECT i.id, i.doc_number, i.project_code, c.email, c.name FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $subject = 'Invoice I-'.(($row['doc_number'] ?? $row['id'])).' from Project Alpha';
    $printUrl = '/?page=invoice-print&id='.$id;
  }

  if (!$row || empty($row['email'])) {
    header('Location: '.$printUrl.'&error=No%20client%20email%20on%20file');
    exit;
  }

  $to = $row['email'];

  // Compose body
  $body = '<p>Hello '.htmlspecialchars($row['name']).',</p>'.
          '<p>Please find your document at the link below:</p>'.
          '<p><a href="'.htmlspecialchars($printUrl).'">View Document</a></p>'.
          '<p>Thank you.</p>';

  // Try SMTP if configured
  $smtpHost = $appConfig['smtp_host'] ?? null;
  $fromEmail = $appConfig['from_email'] ?? 'no-reply@localhost';
  $fromName = $appConfig['from_name'] ?? 'Project Alpha';
  $sent = false; $err = '';
  if ($smtpHost) {
    $pass = null;
    if (!empty($appConfig['smtp_password_enc']) && is_string($appConfig['smtp_password_enc'])) {
      $pt = crypto_decrypt($appConfig['smtp_password_enc']);
      if (is_string($pt)) { $pass = $pt; }
    }
    $cfg = [
      'host' => $smtpHost,
      'port' => (int)($appConfig['smtp_port'] ?? 587),
      'secure' => strtolower((string)($appConfig['smtp_secure'] ?? 'tls')),
      'username' => (string)($appConfig['smtp_username'] ?? ''),
      'password' => (string)($pass ?? ''),
    ];
    [$ok, $msg] = smtp_send($cfg, $to, $subject, $body, $fromEmail, $fromName);
    $sent = $ok; $err = $msg;
  }

  if (!$sent) {
    // Fallback HTML-only email via mail()
    $headers = "MIME-Version: 1.0\r\n".
               "Content-type: text/html; charset=UTF-8\r\n".
               "From: ".($fromName?($fromName.' <'.$fromEmail.'>'):$fromEmail)."\r\n";
    @mail($to, $subject, $body, $headers);
  }

  header('Location: '.$printUrl.'&emailed=1');
  exit;
} catch (Throwable $e) {
  header('Location: /?page=home&error=Email%20failed');
  exit;
}
