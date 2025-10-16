<?php
// src/controllers/public_doc.php
// Render a public, tokenized view of a document without requiring auth
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/csrf.php';

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
  // Optional notice banners
  $notice = isset($_GET['ok']) && $_GET['ok'] === '1';
  $err = isset($_GET['error']) ? (string)$_GET['error'] : '';

echo '<style>.public-doc-wrap{max-width:816px;margin:24px auto;padding:0 16px 96px}.notice{margin:10px 0;padding:10px 12px;border-radius:8px}.n-ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.n-err{background:#fff1f2;color:#881337;border:1px solid #fca5a5}</style>';
  echo '<div class="public-doc-wrap">';
  if ($notice) { echo '<div class="notice n-ok">Thank you! Your response has been recorded.</div>'; }
  if ($err) { echo '<div class="notice n-err">'.htmlspecialchars($err).'</div>'; }

  if ($type === 'quote') {
    require __DIR__ . '/../views/pages/quote-print.php';
  } elseif ($type === 'contract') {
    require __DIR__ . '/../views/pages/contract-print.php';
  } elseif ($type === 'invoice') {
    require __DIR__ . '/../views/pages/invoice-print.php';
  } else {
    throw new Exception('badtype');
  }

  // For quotes only, render Approve / Deny actions when status is pending
  if ($type === 'quote') {
    $showActions = false;
    try {
      $qs = $pdo->prepare('SELECT status FROM quotes WHERE id=? LIMIT 1');
      $qs->execute([$rid]);
      $status = (string)($qs->fetchColumn() ?: '');
      if ($status === 'pending') { $showActions = true; }
    } catch (Throwable $e) { /* ignore */ }

    if ($showActions) {
    require_once __DIR__ . '/../utils/csrf_sf.php';
    $csrf = csrf_sf_token('public_quote_action');
      echo '<div style="margin:16px 0 64px; display:flex; gap:8px">';
      echo '<form method="post" action="/?page=public-quote-action">'
         . '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf).'">'
         . '<input type="hidden" name="token" value="'.htmlspecialchars($token).'">'
         . '<input type="hidden" name="action" value="approve">'
         . '<button type="submit" style="padding:8px 12px;border-radius:8px;border:0;background:#16a34a;color:#fff">Approve</button>'
         . '</form>';
      echo '<form method="post" action="/?page=public-quote-action">'
         . '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf).'">'
         . '<input type="hidden" name="token" value="'.htmlspecialchars($token).'">'
         . '<input type="hidden" name="action" value="deny">'
         . '<button type="submit" style="padding:8px 12px;border-radius:8px;border:0;background:#ef4444;color:#fff">Deny</button>'
         . '</form>';
      echo '</div>';
    }
  }

  echo '</div>';
} catch (Throwable $e) {
  http_response_code(404);
  echo '<main><div class="auth-wrap"><h1>Link expired</h1><p>This link has expired or is no longer valid. Please contact us for a new link.</p></div></main>';
  exit;
}
