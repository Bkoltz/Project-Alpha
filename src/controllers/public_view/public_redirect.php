<?php
// src/controllers/public_view/public_redirect.php
// Renders a friendly page when public links have been revoked and a redirect target is provided
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/app.php';

$type = isset($_GET['type']) ? (string)$_GET['type'] : '';
$reason = isset($_GET['reason']) ? (string)$_GET['reason'] : '';
$title = 'Document Update';
$msg = 'This document has been updated.';
if ($type === 'invoice') {
  if ($reason === 'paid') { $title = 'Invoice Paid'; $msg = 'This invoice has been paid. The public link has been disabled.'; }
  elseif ($reason === 'void') { $title = 'Invoice Voided'; $msg = 'This invoice has been voided. The public link is no longer available.'; }
}
if ($type === 'contract') {
  if ($reason === 'active' || $reason === 'signed') { $title = 'Contract Signed'; $msg = 'Thank you — the contract has been signed and recorded.'; }
}
if ($type === 'quote') {
  if ($reason === 'approved') { $title = 'Quote Approved'; $msg = 'Thank you — the quote has been approved.'; }
}

echo '<section style="max-width:760px;margin:48px auto;padding:16px">';
echo '<h1>'.htmlspecialchars($title).'</h1>';
echo '<p style="color:#374151">'.htmlspecialchars($msg).'</p>';
echo '<p>If you believe this is an error, please contact us for assistance.</p>';
echo '</section>';
exit;
