<?php
// src/controllers/public_quote_action.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/csrf.php';
require_once __DIR__ . '/../utils/mailer.php';
require_once __DIR__ . '/../utils/smtp.php';

// Verify CSRF (we skipped global preflight intentionally)
$csrf = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
  header('Location: /?page=public-doc&error=' . urlencode('Invalid request'));
  exit;
}

try {
  $token = isset($_POST['token']) ? (string)$_POST['token'] : '';
  $action = isset($_POST['action']) ? strtolower((string)$_POST['action']) : '';
  if (!in_array($action, ['approve','deny'], true)) { throw new Exception('badaction'); }

  // Load and validate public link
  $st = $pdo->prepare('SELECT type, record_id, expires_at, revoked FROM public_links WHERE token=? LIMIT 1');
  $st->execute([$token]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { throw new Exception('notfound'); }
  if ((int)$row['revoked'] === 1 || strtotime((string)$row['expires_at']) < time()) { throw new Exception('expired'); }
  if ($row['type'] !== 'quote') { throw new Exception('notquote'); }
  $qid = (int)$row['record_id'];

  // Load quote, client, etc.
  $q = $pdo->prepare('SELECT q.*, c.name AS client_name, c.email AS client_email FROM quotes q JOIN clients c ON c.id=q.client_id WHERE q.id=?');
  $q->execute([$qid]);
  $quote = $q->fetch(PDO::FETCH_ASSOC);
  if (!$quote) { throw new Exception('nofile'); }

  $changed = false;
  if ($action === 'deny') {
    if ((string)$quote['status'] === 'pending') {
      $pdo->prepare('UPDATE quotes SET status="rejected" WHERE id=?')->execute([$qid]);
      $changed = true;
    }
  } else {
    if ((string)$quote['status'] === 'pending') {
      // Approve quote by reusing minimal sequence
      $pdo->beginTransaction();
      try {
        // Load items
        $items = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id=?');
        $items->execute([$qid]);
        $qitems = $items->fetchAll(PDO::FETCH_ASSOC);

        // Ensure project_code
        $projectCode = $quote['project_code'] ?? null;
        if (!$projectCode) {
          $projectCode = 'PA-' . date('Y') . '-' . str_pad((string)$qid, 4, '0', STR_PAD_LEFT);
          $pdo->prepare('UPDATE quotes SET project_code=? WHERE id=?')->execute([$projectCode, $qid]);
        }

        // Mark approved
        $pdo->prepare('UPDATE quotes SET status="approved" WHERE id=?')->execute([$qid]);

        // Create contract (pending)
        $pdo->prepare('INSERT INTO contracts (quote_id, client_id, status, discount_type, discount_value, tax_percent, subtotal, total, project_code) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([$qid, (int)$quote['client_id'], 'pending', $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total'], $projectCode]);
        $contract_id = (int)$pdo->lastInsertId();

        $ci = $pdo->prepare('INSERT INTO contract_items (contract_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
        foreach ($qitems as $it) { $ci->execute([$contract_id, $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]); }

        // Create invoice (unpaid)
        $pdo->prepare('INSERT INTO invoices (contract_id, quote_id, client_id, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, project_code) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$contract_id, $qid, (int)$quote['client_id'], $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total'], 'unpaid', null, $projectCode]);
        $invoice_id = (int)$pdo->lastInsertId();

        $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
        foreach ($qitems as $it) { $ii->execute([$invoice_id, $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]); }

        // Assign doc_numbers
        $cMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM contracts')->fetchColumn();
        $pdo->prepare('UPDATE contracts SET doc_number=? WHERE id=?')->execute([$cMax + 1, $contract_id]);
        $iMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices')->fetchColumn();
        $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$iMax + 1, $invoice_id]);

        $pdo->commit();
        $changed = true;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        // Do not treat as fatal for the public UX; we'll still redirect with error below
        throw $e;
      }
    }
  }

  // Email admin only if status changed via public link
  if ($changed) {
    // Send a notification to the first admin
    try {
      require_once __DIR__ . '/../utils/notifications.php';
      notify_admin_quote_change($pdo, $appConfig, $quote, $action === 'approve' ? 'approve' : 'deny');
    } catch (Throwable $e) {
      // Ignore notification failures but keep normal flow
    }
  }

  // Redirect back to public view with success notice always (even if no change due to non-pending)
  header('Location: /?page=public-doc&token=' . rawurlencode($token) . '&ok=1');
  exit;
} catch (Throwable $e) {
  // If an exception occurred after we updated the quote status (e.g. while creating contract/invoice),
  // prefer to show the user success if the quote actually changed. This avoids confusing the client
  // when backend follow-up tasks fail but the primary action succeeded.
  $t = isset($_POST['token']) ? (string)$_POST['token'] : '';
  try {
    if (isset($qid) && $qid > 0) {
      $chk = $pdo->prepare('SELECT status FROM quotes WHERE id=? LIMIT 1');
      $chk->execute([$qid]);
      $s = (string)($chk->fetchColumn() ?: '');
      if ($s === 'approved' || $s === 'rejected') {
        header('Location: /?page=public-doc&token=' . rawurlencode($t) . '&ok=1');
        exit;
      }
    }
  } catch (Throwable $_e) {
    // ignore and fallthrough to generic error
  }
  header('Location: /?page=public-doc&token=' . rawurlencode($t) . '&error=' . urlencode('Unable to record response'));
  exit;
}
