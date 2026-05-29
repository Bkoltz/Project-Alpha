<?php
// src/controllers/quote_reject.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';

// CSRF verification
require_once __DIR__ . '/../../utils/csrf.php';
if (!csrf_validate()) {
    header('Location: /?page=quote/quotes-list&error=' . urlencode('Invalid request (CSRF)'));
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: /?page=quote/quotes-list&error=Invalid%20quote');
  exit;
}

// Get quote type before rejecting for proper redirect
$quoteType = 'regular';
try {
  $typeStmt = $pdo->prepare('SELECT quote_type FROM quotes WHERE id=?');
  $typeStmt->execute([$id]);
  $quoteType = $typeStmt->fetchColumn() ?: 'regular';
} catch (Throwable $e) {
  // Default to regular if we can't fetch
}

// Determine redirect page based on quote type
$redirectPage = 'quote/quotes-list';
if ($quoteType === 'long_term') {
  $redirectPage = 'quote/long-term-quotes-list';
} elseif ($quoteType === 'on_demand') {
  $redirectPage = 'quote/on-demand-quotes-list';
}

try {
  // Only allow reject from pending
  $st = $pdo->prepare('UPDATE quotes SET status="rejected" WHERE id=? AND status="pending"');
  $st->execute([$id]);
  if ($st->rowCount() === 0) {
    header('Location: /?page=' . $redirectPage . '&error=Cannot%20reject%20this%20quote');
    exit;
  }
} catch (Throwable $e) {
  header('Location: /?page=' . $redirectPage . '&error=Failed%20to%20reject');
  exit;
}

header('Location: /?page=' . $redirectPage . '&rejected=1');
exit;
