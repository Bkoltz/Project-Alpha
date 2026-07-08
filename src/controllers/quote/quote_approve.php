<?php
// src/controllers/quote_approve.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/project_id.php';
require_once __DIR__ . '/../../utils/project_billing.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/public_links.php';

// Auto-create settings (default to true/on when not explicitly set)
$autoCreateContract = !isset($appConfig['quote_auto_create_contract']) || !empty($appConfig['quote_auto_create_contract']);
$autoCreateInvoice = !isset($appConfig['quote_auto_create_invoice']) || !empty($appConfig['quote_auto_create_invoice']);

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: /?page=quote/quotes-list&error=Invalid%20quote');
  exit;
}
require_record_ownership($pdo, 'quotes', $id);

$pdo->beginTransaction();
try {
  // Load quote + items
  $q = $pdo->prepare('SELECT * FROM quotes WHERE id=? FOR UPDATE');
  $q->execute([$id]);
  $quote = $q->fetch(PDO::FETCH_ASSOC);
  if (!$quote) throw new Exception('Quote not found');

  // Resolve creator + organization for derived records
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
  $fallbackUserId = (int)($_SESSION['user']['id'] ?? 0) ?: 1;
  $fallbackOrgId  = request_client_org_id() ?: null;
  $quoteCreator = (int)($quote['created_by'] ?? 0) ?: $fallbackUserId;
  $quoteOrgId   = !empty($quote['organization_id']) ? (int)$quote['organization_id'] : $fallbackOrgId;

  if ($quote['status'] !== 'pending') throw new Exception('Quote not pending');
  $items = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id=?');
  $items->execute([$id]);
  $qitems = $items->fetchAll(PDO::FETCH_ASSOC);

  // Ensure project_code
  $projectCode = $quote['project_code'] ?? null;
  if (!$projectCode) {
    $projectCode = project_next_code($pdo, (int)$quote['client_id']);
    $pdo->prepare('UPDATE quotes SET project_code=? WHERE id=?')->execute([$projectCode, $id]);
  }

  // Mark quote approved
  $pdo->prepare('UPDATE quotes SET status="approved" WHERE id=?')->execute([$id]);
  pa_public_link_terminalize($pdo, 'quote', $id, 'approved');

  // Get project_id from quote for inheritance
  $projectId = !empty($quote['project_id']) ? (int)$quote['project_id'] : null;

  $quoteType = $quote['quote_type'] ?? 'regular';
  $billingMode = ($quote['billing_mode'] ?? 'fixed') === 'hourly' ? 'hourly' : 'fixed';
  $depositType = $quote['deposit_type'] ?? 'none';
  $depositValue = (float)($quote['deposit_amount'] ?? 0);
  $quoteTotal = (float)($quote['total'] ?? 0);
  $contractDepositAmount = 0.0;
  if ($depositType === 'percent') {
    $contractDepositAmount = max(0, min(100, $depositValue)) * $quoteTotal / 100;
  } elseif ($depositType === 'fixed') {
    $contractDepositAmount = min(max(0, $depositValue), $quoteTotal);
  }
  if (in_array($quoteType, ['long_term', 'on_demand'], true)) {
    // For LT/OD quotes, only create contract if auto-create is enabled
    if ($autoCreateContract) {
      $pdo->prepare('INSERT INTO contracts (quote_id, client_id, project_id, status, contract_type, billing_mode, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_amount, deposit_paid, start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice, billing_start_mode, scope, organization_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([
            $id,
            (int)$quote['client_id'],
            $projectId,
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
            0,
            $quote['start_date'] ?? null,
            $quote['end_date'] ?? null,
            $quote['billing_interval_count'] ?? 1,
            $quote['billing_interval_unit'] ?? 'month',
            $quote['pricing_type'] ?? ($quoteType === 'on_demand' ? 'on_demand' : null),
            $quote['price_per_invoice'] ?? null,
            $quoteType === 'long_term' ? 'on_upload' : null,
            $quote['scope'] ?? null,
            $quoteOrgId,
            $quoteCreator
          ]);
      $contract_id = (int)$pdo->lastInsertId();

      if (!empty($qitems)) {
        $ci = $pdo->prepare('INSERT INTO contract_items (contract_id, item, description, quantity, unit_price, line_total, billing_unit) VALUES (?,?,?,?,?,?,?)');
        foreach ($qitems as $it) {
          $ci->execute([$contract_id, $it['item'] ?? ($it['description'] ?? 'Item'), $it['description'], $it['quantity'], $it['unit_price'], $it['line_total'], $it['billing_unit'] ?? ($billingMode === 'hourly' ? 'hour' : 'each')]);
        }
      }

      $cMaxStmt = $pdo->prepare('SELECT COALESCE(MAX(doc_number),0) FROM contracts WHERE contract_type = ?');
      $cMaxStmt->execute([$quoteType]);
      $cMax = (int)$cMaxStmt->fetchColumn();
      $pdo->prepare('UPDATE contracts SET doc_number=? WHERE id=?')->execute([$cMax + 1, $contract_id]);
    }
  } else {
    // Regular quote: create contract and/or invoice based on settings
    if ($autoCreateContract) {
      $pdo->prepare('INSERT INTO contracts (quote_id, client_id, project_id, status, billing_mode, discount_type, discount_value, tax_percent, subtotal, total, project_code, deposit_type, deposit_amount, deposit_paid, fulfillment_date, organization_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$id, (int)$quote['client_id'], $projectId, 'pending', $billingMode, $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total'], $projectCode, $depositType, $contractDepositAmount, 0, $quote['fulfillment_date'] ?? null, $quoteOrgId, $quoteCreator]);
      $contract_id = (int)$pdo->lastInsertId();

      $ci = $pdo->prepare('INSERT INTO contract_items (contract_id, item, description, quantity, unit_price, line_total, billing_unit) VALUES (?,?,?,?,?,?,?)');
      foreach ($qitems as $it) {
        $ci->execute([$contract_id, $it['description'] ?? 'Item', $it['description'], $it['quantity'], $it['unit_price'], $it['line_total'], $it['billing_unit'] ?? ($billingMode === 'hourly' ? 'hour' : 'each')]);
      }

      $cMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM contracts WHERE contract_type = "regular"')->fetchColumn();
      $pdo->prepare('UPDATE contracts SET doc_number=? WHERE id=?')->execute([$cMax + 1, $contract_id]);
    }

    if ($autoCreateInvoice) {
      $pdo->prepare('INSERT INTO invoices (contract_id, quote_id, client_id, project_id, billing_mode, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, project_code, fulfillment_date, organization_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$contract_id ?? null, $id, (int)$quote['client_id'], $projectId, $billingMode, $quote['discount_type'], $quote['discount_value'], $quote['tax_percent'], $quote['subtotal'], $quote['total'], 'draft', null, $projectCode, $quote['fulfillment_date'] ?? null, $quoteOrgId, $quoteCreator]);
      $invoice_id = (int)$pdo->lastInsertId();
      if ($projectId && project_uses_monthly_invoice_billing($pdo, $projectId)) {
        $pdo->prepare('UPDATE invoices SET collection_mode="project_aggregate" WHERE id=?')->execute([$invoice_id]);
      }

      $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total, billing_unit) VALUES (?,?,?,?,?,?,?)');
      foreach ($qitems as $it) {
        $ii->execute([$invoice_id, $it['description'] ?? 'Item', $it['description'], $it['quantity'], $it['unit_price'], $it['line_total'], $it['billing_unit'] ?? ($billingMode === 'hourly' ? 'hour' : 'each')]);
      }

      $iMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices WHERE invoice_type = "regular"')->fetchColumn();
      $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$iMax + 1, $invoice_id]);
    }
  }

  $pdo->commit();

  // Notify client that their quote has been approved (best-effort; don't fail approval)
  try {
    if (!empty($quote['client_id'])) {
      $clientStmt = $pdo->prepare('SELECT email, name FROM clients WHERE id = ?');
      $clientStmt->execute([(int)$quote['client_id']]);
      $clientRow = $clientStmt->fetch(PDO::FETCH_ASSOC);
      if ($clientRow && !empty($clientRow['email']) && filter_var($clientRow['email'], FILTER_VALIDATE_EMAIL)) {
        require_once __DIR__ . '/../../services/EmailService.php';
        $docnum = (string)($quote['doc_number'] ?? $id);
        $clientName = trim((string)($clientRow['name'] ?? ''));
        $firstName = $clientName !== '' ? preg_split('/\s+/', $clientName)[0] : 'there';
        $subject = 'Your quote Q-' . $docnum . ' has been approved';
        $body = '<p>Hello ' . htmlspecialchars($firstName) . ',</p>'
              . '<p>Your quote <strong>Q-' . htmlspecialchars($docnum) . '</strong> has been approved.</p>'
              . '<p>We will be in touch shortly with the next steps. Thank you for your business!</p>';
        EmailService::sendEmail($clientRow['email'], $subject, $body);

        // Derived invoices remain private drafts until the contract is completed.
      }
    }
  } catch (Throwable $e) {
    @error_log('[quote_approve] Client notification email failed: ' . $e->getMessage());
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  error_log('[quote_approve] Failed: ' . $e->getMessage());
  
  // Determine redirect page based on quote type
  $redirectPage = 'quote/quotes-list';
  if (isset($quoteType)) {
    if ($quoteType === 'long_term') {
      $redirectPage = 'quote/long-term-quotes-list';
    } elseif ($quoteType === 'on_demand') {
      $redirectPage = 'quote/on-demand-quotes-list';
    }
  }
  
  header('Location: /?page=' . $redirectPage . '&error=' . urlencode('Failed to approve: ' . $e->getMessage()));
  exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Determine redirect page based on quote type
$redirectPage = 'quote/quotes-list';
if ($quoteType === 'long_term') {
  $redirectPage = 'quote/long-term-quotes-list';
} elseif ($quoteType === 'on_demand') {
  $redirectPage = 'quote/on-demand-quotes-list';
}

$redirect = '/?page=' . $redirectPage . '&approved=1';

// Flash message based on whether auto-creation is enabled
if (!$autoCreateContract && !$autoCreateInvoice) {
  $_SESSION['flash_quote_approve'] = [
    'type' => 'info',
    'message' => 'Quote approved. Contract and invoice were not auto-generated (disabled in Settings). You can create them manually from the quote.'
  ];
} elseif (!$autoCreateContract || !$autoCreateInvoice) {
  $skipped = [];
  if (!$autoCreateContract) { $skipped[] = 'contract'; }
  if (!$autoCreateInvoice) { $skipped[] = 'invoice'; }
  $_SESSION['flash_quote_approve'] = [
    'type' => 'info',
    'message' => 'Quote approved, but auto-create ' . implode(' and ', $skipped) . ' ' . (count($skipped) === 1 ? 'is' : 'are') . ' disabled in settings.'
  ];
} else {
  $_SESSION['flash_quote_approve'] = [
    'type' => 'success',
    'message' => 'Quote approved. Contract and invoice have been created.'
  ];
}
header('Location: ' . $redirect);
exit;
