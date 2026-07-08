<?php
// src/controllers/public_view/public_redirect.php
// Renders a friendly status page after a public link reaches a terminal state.
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/app.php';

$type = isset($_GET['type']) ? (string)$_GET['type'] : '';
$reason = isset($_GET['reason']) ? (string)$_GET['reason'] : '';

$title = 'Document Update';
$msg = 'This document has been updated.';

if ($type === 'invoice' || $type === 'project_invoice') {
  $label = $type === 'project_invoice' ? 'Project Invoice' : 'Invoice';
  if ($reason === 'paid') { $title = $label . ' Paid'; $msg = 'This invoice has been paid in full.'; }
  elseif ($reason === 'void') { $title = $label . ' Voided'; $msg = 'This invoice has been voided and is no longer available.'; }
  elseif ($reason === 'cancelled') { $title = $label . ' Cancelled'; $msg = 'This invoice has been cancelled and is no longer available.'; }
  elseif ($reason === 'denied') { $title = $label . ' Unavailable'; $msg = 'This invoice is no longer available.'; }
}

if ($type === 'contract') {
  if ($reason === 'active' || $reason === 'signed') { $title = 'Contract Signed'; $msg = 'This contract has been signed and uploaded.'; }
  elseif ($reason === 'completed') { $title = 'Contract Completed'; $msg = 'This contract has been completed.'; }
  elseif ($reason === 'denied') { $title = 'Contract Denied'; $msg = 'This contract has been denied and is no longer available.'; }
  elseif ($reason === 'cancelled') { $title = 'Contract Cancelled'; $msg = 'This contract has been cancelled and is no longer available.'; }
  elseif ($reason === 'void') { $title = 'Contract Voided'; $msg = 'This contract has been voided and is no longer available.'; }
}

if ($type === 'quote') {
  if ($reason === 'approved') { $title = 'Quote Approved'; $msg = 'This quote has been approved.'; }
  elseif ($reason === 'denied') { $title = 'Quote Denied'; $msg = 'This quote has been denied.'; }
}

echo '<section style="max-width:760px;margin:48px auto;padding:16px">';
echo '<h1>' . htmlspecialchars($title) . '</h1>';
echo '<p style="color:#374151">' . htmlspecialchars($msg) . '</p>';
echo '<p>If you believe this is an error, please contact us for assistance.</p>';
echo '</section>';
exit;
