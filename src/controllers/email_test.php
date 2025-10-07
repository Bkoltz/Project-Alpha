<?php
// src/controllers/email_test.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/crypto.php';
require_once __DIR__ . '/../utils/smtp.php';
require_once __DIR__ . '/../utils/mailer.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Prefer posted values to test current inputs, without requiring a prior Save
$postHost = trim((string)($_POST['smtp_host'] ?? ''));
$postPort = isset($_POST['smtp_port']) ? (int)$_POST['smtp_port'] : null;
$postSecure = strtolower(trim((string)($_POST['smtp_secure'] ?? '')));
$postUser = trim((string)($_POST['smtp_username'] ?? ''));
$postPass = (string)($_POST['smtp_password'] ?? '');
$postFrom = trim((string)($_POST['from_email'] ?? ''));

// Determine recipient: posted from_email, else posted smtp_username, else current user email
$to = $postFrom !== '' ? $postFrom : ($postUser !== '' ? $postUser : ((string)($_SESSION['user']['email'] ?? '')));
if ($to === '') { $to = (string)($appConfig['from_email'] ?? ''); }
if ($to === '') { $to = (string)($appConfig['smtp_username'] ?? ''); }
if ($to === '') {
  header('Location: /?page=settings&tab=email&email_err=' . urlencode('No recipient email available'));
  exit;
}

$subject = 'Testing';
$body = 'testing';

$smtpHost = $postHost !== '' ? $postHost : ($appConfig['smtp_host'] ?? null);
$smtpHostL = $smtpHost ? strtolower((string)$smtpHost) : null;
$fromEmail = $postFrom !== '' ? $postFrom : ($appConfig['from_email'] ?? '');
$fromName = $appConfig['from_name'] ?? 'Project Alpha';
$sent = false; $err = '';
if ($smtpHost) {
  $pass = $postPass !== '' ? $postPass : null;
  if ($pass === null && !empty($appConfig['smtp_password_enc']) && is_string($appConfig['smtp_password_enc'])) {
    $pt = crypto_decrypt($appConfig['smtp_password_enc']);
    if (is_string($pt)) { $pass = $pt; }
  }
  $username = $postUser !== '' ? $postUser : (string)($appConfig['smtp_username'] ?? '');
  if ($fromEmail === '' && $username !== '') { $fromEmail = $username; }
  if ($fromEmail === '') { $fromEmail = 'no-reply@localhost'; }
  $cfg = [
    'host' => $smtpHost,
    'port' => (int)($postPort ?? ($appConfig['smtp_port'] ?? 587)),
    'secure' => ($postSecure !== '' ? $postSecure : strtolower((string)($appConfig['smtp_secure'] ?? 'tls'))),
    'username' => $username,
    'password' => (string)($pass ?? ''),
  ];
  $envelopeFrom = $fromEmail;
  if ($smtpHostL === 'smtp.gmail.com') {
    if ($username === '' || ($pass ?? '') === '') {
      $sent = false; $err = 'Gmail SMTP requires username and app password';
    } else {
      $envelopeFrom = $username;
    }
  }
  if ($err === '') {
    // Try PHPMailer first
    [$ok, $msg] = mailer_send($cfg, $to, $subject, $body, $fromEmail, $fromName, $envelopeFrom);
    if ($ok) {
      $sent = true;
    } else {
      [$ok2, $msg2] = smtp_send($cfg, $to, $subject, $body, $fromEmail, $fromName, $envelopeFrom);
      $sent = $ok2; $err = $ok2 ? '' : ($msg2 ?: $msg);
    }
  }
}

if (!$sent) {
  $headers = "MIME-Version: 1.0\r\n".
             "Content-type: text/plain; charset=UTF-8\r\n".
             "From: ".($fromName?($fromName.' <'.$fromEmail.'>'):$fromEmail)."\r\n";
  $mailOk = @mail($to, $subject, $body, $headers);
  $sent = $mailOk;
  if (!$sent && $err === '') { $err = 'Email send failed'; }
}

if ($sent) {
  header('Location: /?page=settings&tab=email&email_test=1');
} else {
  header('Location: /?page=settings&tab=email&email_err=' . urlencode($err));
}
exit;
