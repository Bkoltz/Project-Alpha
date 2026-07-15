<?php
// src/controllers/email_test.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/EmailService.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$isAjax = ((string)($_POST['ajax'] ?? '') === '1')
  || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
  || (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false);

$postFrom = trim((string)($_POST['from_email'] ?? ''));

// Determine recipient: posted from_email, else posted smtp_username, else current user email
$to = $postFrom !== '' ? $postFrom : (string)($_SESSION['user']['email'] ?? '');
if ($to === '') { $to = (string)($appConfig['from_email'] ?? ''); }
if ($to === '') { $to = (string)($appConfig['smtp_username'] ?? ''); }
if ($to === '') {
  if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'No recipient email available']);
    exit;
  }
  header('Location: /?page=settings&tab=system&email_err=' . urlencode('No recipient email available'));
  exit;
}

[$sent, $err] = EmailService::sendEmail($to, 'Project Alpha outgoing email test', 'Your active outgoing email provider is working.', [
  'is_html'=>false,'document_type'=>'notification'
]);

if ($isAjax) {
  header('Content-Type: application/json');
  echo json_encode(['ok' => (bool)$sent, 'error' => $sent ? '' : (string)$err]);
  exit;
}

if ($sent) {
  header('Location: /?page=settings&tab=system&email_test=1');
} else {
  header('Location: /?page=settings&tab=system&email_err=' . urlencode($err));
}
exit;
