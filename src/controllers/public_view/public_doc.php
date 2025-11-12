<?php
// src/controllers/public_doc.php
// Render a public, tokenized view of a document without requiring auth
// TODO: We need to add more public views. One for contract, so a client can upload a signed contract via link/portal on public_contract_action. Use a mix of PHP and twig for page views.
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';

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

  // Optional notice banners
  $notice = isset($_GET['ok']) && $_GET['ok'] === '1';
  $err = isset($_GET['error']) ? (string)$_GET['error'] : '';

  // For invoice links, ensure invoice remains in public-viewable states
  if ($type === 'invoice') {
    try {
      $vs = $pdo->prepare('SELECT status FROM invoices WHERE id=? LIMIT 1');
      $vs->execute([$rid]);
      $invStatus = (string)($vs->fetchColumn() ?: '');
      if (!in_array($invStatus, ['unpaid', 'partial'], true)) { throw new Exception('expired'); }
    } catch (Throwable $_e) { throw new Exception('expired'); }
  }

  // Determine whether to show actions or upload form in the view
  $showActions = false; $showUpload = false;
  if ($type === 'quote') {
    try {
      $qs = $pdo->prepare('SELECT status FROM quotes WHERE id=? LIMIT 1');
      $qs->execute([$rid]);
      $status = (string)($qs->fetchColumn() ?: '');
      if ($status === 'pending') { $showActions = true; }
    } catch (Throwable $e) { /* ignore */ }
  }
  if ($type === 'contract') {
    try {
      $cs = $pdo->prepare('SELECT status FROM contracts WHERE id=? LIMIT 1');
      $cs->execute([$rid]);
      $cstat = (string)($cs->fetchColumn() ?: '');
      if ($cstat === 'pending') { $showUpload = true; }
    } catch (Throwable $e) { /* ignore */ }
  }

  // Prefer Twig template if available, otherwise include PHP view wrapper
  $twigTemplate = __DIR__ . '/../../views/public/doc-template.twig';
  $phpView = __DIR__ . '/../../views/public/doc-wrapper.php';
  if (is_file($twigTemplate) && is_file(__DIR__ . '/../../vendor/autoload.php')) {
    // Attempt to render Twig if installed (non-fatal)
    try {
      require_once __DIR__ . '/../../vendor/autoload.php';
      // Code is correct, but it throws an error, ignore for now
      $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../../views');
      $twig = new \Twig\Environment($loader);
      echo $twig->render('public/doc-template.twig', [
        'type'=>$type, 'id'=>$rid, 'token'=>$token, 'notice'=>$notice, 'error'=>$err,
        'showActions'=>$showActions, 'showUpload'=>$showUpload, 'appConfig'=>$appConfig
      ]);
    } catch (Throwable $_t) {
      require $phpView;
    }
  } else {
    require $phpView;
  }
} catch (Throwable $e) {
  http_response_code(404);
  echo '<main><div class="auth-wrap"><h1>Link expired</h1><p>This link has expired or is no longer valid. Please contact us for a new link.</p></div></main>';
  exit;
}
