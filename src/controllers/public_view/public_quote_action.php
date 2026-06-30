<?php
// src/controllers/public_quote_action.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Verify CSRF (we skipped global preflight intentionally)
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
if (!rate_limit_check($pdo, 'public_quote_action', 30, 60)) {
  http_response_code(429);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!DOCTYPE html><html><head><title>Rate limited</title></head><body><h1>Rate limited</h1></body></html>';
  exit;
}
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/smtp.php';
require_once __DIR__ . '/../../utils/project_billing.php';
$submitted = (string)($_POST['_token'] ?? ($_POST['csrf'] ?? ''));
if (!csrf_sf_is_valid('public_quote_action', $submitted)) {
  header('Location: /?page=public-doc&error=' . urlencode('Invalid request'));
  exit;
}

try {
  $token = isset($_POST['token']) ? (string)$_POST['token'] : '';
  $action = isset($_POST['action']) ? strtolower((string)$_POST['action']) : '';
  if (!in_array($action, ['approve','deny'], true)) { throw new Exception('badaction'); }

  // Load and validate public link
  $st = $pdo->prepare('SELECT document_type, document_id, expires_at, revoked FROM public_links WHERE token=? LIMIT 1');
  $st->execute([$token]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { throw new Exception('notfound'); }
  if ((int)$row['revoked'] === 1 || strtotime((string)$row['expires_at']) < time()) { throw new Exception('expired'); }
  if ($row['document_type'] !== 'quote') { throw new Exception('notquote'); }
  $qid = (int)$row['document_id'];

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

        $quoteType = $quote['quote_type'] ?? 'regular';

        // Resolve creator + organization for derived records (no session user for public link)
        $fallbackUserId = 1;
        $fallbackOrgId  = (int)($pdo->query('SELECT id FROM organizations ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);
        $quoteCreator = (int)($quote['created_by'] ?? 0) ?: $fallbackUserId;
        $quoteOrgId   = (int)($quote['organization_id'] ?? 0) ?: $fallbackOrgId;
        $billingMode = (($quote['billing_mode'] ?? 'fixed') === 'hourly') ? 'hourly' : 'fixed';
        $depositType = $quote['deposit_type'] ?? 'none';
        $depositValue = (float)($quote['deposit_amount'] ?? 0);
        $quoteTotal = (float)($quote['total'] ?? 0);
        $contractDepositAmount = 0.0;
        if ($depositType === 'percent') {
          $contractDepositAmount = max(0, min(100, $depositValue)) * $quoteTotal / 100;
        } elseif ($depositType === 'fixed') {
          $contractDepositAmount = min(max(0, $depositValue), $quoteTotal);
        }

        // Create contract (pending)
        $pdo->prepare('INSERT INTO contracts (quote_id, client_id, project_id, status, contract_type, billing_mode, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_amount, start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice, scope, organization_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([
             $qid,
             (int)$quote['client_id'],
             !empty($quote['project_id']) ? (int)$quote['project_id'] : null,
             'pending',
             $quoteType,
             $billingMode,
             $quote['discount_type'],
             $quote['discount_value'],
             $quote['tax_percent'],
             $quote['subtotal'],
             $quote['total'],
             $projectCode,
             $depositType,
             $contractDepositAmount,
             $quote['start_date'] ?? null,
             $quote['end_date'] ?? null,
             $quote['billing_interval_count'] ?? 1,
             $quote['billing_interval_unit'] ?? 'month',
             $quote['pricing_type'] ?? ($quoteType === 'on_demand' ? 'on_demand' : null),
             $quote['price_per_invoice'] ?? null,
             $quote['scope'] ?? null,
             $quoteOrgId,
             $quoteCreator
           ]);
        $contract_id = (int)$pdo->lastInsertId();

        $ci = $pdo->prepare('INSERT INTO contract_items (contract_id, item, description, quantity, unit_price, line_total, billing_unit) VALUES (?,?,?,?,?,?,?)');
        foreach ($qitems as $it) {
          $ci->execute([
            $contract_id,
            $it['item'] ?? ($it['description'] ?? 'Item'),
            $it['description'],
            $it['quantity'],
            $it['unit_price'],
            $it['line_total'],
            ($it['billing_unit'] ?? 'each') === 'hour' ? 'hour' : 'each'
          ]);
        }

        if ($quoteType === 'regular') {
          // Create a private draft. Contract completion is the billing event.
          $pdo->prepare('INSERT INTO invoices (contract_id, quote_id, client_id, project_id, invoice_type, billing_mode, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, project_code, fulfillment_date, organization_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
             ->execute([$contract_id, $qid, (int)$quote['client_id'], !empty($quote['project_id']) ? (int)$quote['project_id'] : null, 'regular', $billingMode, $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total'], 'draft', null, $projectCode, $quote['fulfillment_date'] ?? null, $quoteOrgId, $quoteCreator]);
          $invoice_id = (int)$pdo->lastInsertId();
          if (!empty($quote['project_id']) && project_uses_monthly_invoice_billing($pdo, (int)$quote['project_id'])) {
            $pdo->prepare('UPDATE invoices SET collection_mode="project_aggregate" WHERE id=?')->execute([$invoice_id]);
          }

          $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total, billing_unit) VALUES (?,?,?,?,?,?,?)');
          foreach ($qitems as $it) {
            $ii->execute([
              $invoice_id,
              $it['item'] ?? ($it['description'] ?? 'Item'),
              $it['description'],
              $it['quantity'],
              $it['unit_price'],
              $it['line_total'],
              ($it['billing_unit'] ?? 'each') === 'hour' ? 'hour' : 'each'
            ]);
          }
        }

        // Assign doc_numbers
        $cMaxStmt = $pdo->prepare('SELECT COALESCE(MAX(doc_number),0) FROM contracts WHERE contract_type = ?');
        $cMaxStmt->execute([$quoteType]);
        $cMax = (int)$cMaxStmt->fetchColumn();
        $pdo->prepare('UPDATE contracts SET doc_number=? WHERE id=?')->execute([$cMax + 1, $contract_id]);
        if (!empty($invoice_id)) {
          $iMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices WHERE invoice_type = "regular"')->fetchColumn();
          $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$iMax + 1, $invoice_id]);
        }

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
  require_once __DIR__ . '/../../utils/notifications.php';
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
