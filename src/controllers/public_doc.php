<?php
// src/controllers/public_doc.php
// Render a public, tokenized view of a document without requiring auth
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if ($token === '') {
  http_response_code(400);
  echo '<main><div class="auth-wrap"><h1>Invalid link</h1><p>This link is not valid.</p></div></main>';
  exit;
}

try {
  $st = $pdo->prepare('SELECT type, record_id, expires_at, revoked FROM public_links WHERE token=? LIMIT 1');
  $st->execute([$token]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { throw new Exception('notfound'); }
  if ((int)$row['revoked'] === 1 || strtotime((string)$row['expires_at']) < time()) { throw new Exception('expired'); }

  $type = (string)$row['type'];
  $rid = (int)$row['record_id'];

  if (!defined('PUBLIC_VIEW')) define('PUBLIC_VIEW', true);
  $_GET['id'] = (string)$rid;

  // Render the appropriate print view in-place
  if ($type === 'quote') {
    require __DIR__ . '/../views/pages/quote-print.php';
  } elseif ($type === 'contract') {
    require __DIR__ . '/../views/pages/contract-print.php';
  } elseif ($type === 'invoice') {
    require __DIR__ . '/../views/pages/invoice-print.php';
  } else {
    throw new Exception('badtype');
  }
} catch (Throwable $e) {
  http_response_code(404);
  echo '<main><div class="auth-wrap"><h1>Link expired</h1><p>This link has expired or is no longer valid. Please contact us for a new link.</p></div></main>';
  exit;
}