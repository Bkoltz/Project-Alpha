<?php
// src/controllers/contract/on_demand_invoice_generate.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/crypto.php';
require_once __DIR__ . '/../../utils/project_billing.php';
require_once __DIR__ . '/../../utils/invoice_numbers.php';
require_once __DIR__ . '/../../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../../utils/acl.php';

@error_log('[on_demand_invoice_generate] POST received', 0);

$contract_id = (int)($_POST['id'] ?? 0);
$sendEmail = !empty($_POST['send_email']);

if ($contract_id <= 0) {
    @error_log('[on_demand_invoice_generate] invalid contract_id', 0);
    header('Location: /?page=contract/on-demand-contracts-list&error=Invalid%20contract%20ID');
    exit;
}

require_record_ownership($pdo, 'contracts', $contract_id);

$pdo->beginTransaction();

try {
    // Fetch the on-demand contract
    $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id = ? AND contract_type = "on_demand"');
    $stmt->execute([$contract_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contract) {
        throw new Exception('Contract not found');
    }
    
    // Check if contract is active
    if ($contract['status'] !== 'active') {
        throw new Exception('Contract must be active to generate invoices');
    }
    
    // Check if contract has ended
    if (!empty($contract['end_date']) && $contract['end_date'] < date('Y-m-d')) {
        throw new Exception('Contract has ended');
    }
    
    $clientId = $contract['client_id'];
    $projectCode = $contract['project_code'];
    $projectId = !empty($contract['project_id']) ? (int)$contract['project_id'] : null;
    $billingMode = ($contract['billing_mode'] ?? 'fixed') === 'hourly' ? 'hourly' : 'fixed';
    
    // Resolve creator + organization for derived invoice (no session in on-demand generator)
    $fallbackUserId = 1;
    $contractCreator = (int)($contract['created_by'] ?? 0) ?: $fallbackUserId;
    $contractOrgId   = !empty($contract['organization_id']) ? (int)$contract['organization_id'] : null;
    
    // Calculate invoice amount. Older on-demand contracts may have a zero
    // price_per_invoice even though subtotal/contract_items were saved.
    $subtotal = (float)($contract['price_per_invoice'] ?? 0);
    if ($subtotal <= 0) {
        $subtotal = (float)($contract['subtotal'] ?? 0);
    }
    
    // Apply discount and tax
    $discountType = $contract['discount_type'] ?? 'none';
    $discountValue = (float)($contract['discount_value'] ?? 0);
    $discount = 0;
    
    if ($discountType === 'percent') {
        $discount = max(0, min(100, $discountValue)) * $subtotal / 100;
    } elseif ($discountType === 'fixed') {
        $discount = $discountValue;
    }
    
    $taxable = max(0, $subtotal - $discount);
    $tax = max(0, (float)$contract['tax_percent']) * $taxable / 100;
    $total = max(0, $taxable + $tax);
    
    // Create invoice
    $projectMonthlyBilling = project_uses_monthly_invoice_billing($pdo, $projectId);
    $dueDate = project_invoice_due_date($pdo, $projectId, $appConfig);
    
    $insertInvoice = $pdo->prepare('
        INSERT INTO invoices (
            contract_id, client_id, project_id, project_code, invoice_type, billing_mode,
            discount_type, discount_value, tax_percent, 
            subtotal, total, status, due_date, created_at, organization_id, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    
    $insertInvoice->execute([
        $contract_id,
        $clientId,
        $projectId,
        $projectCode,
        'on_demand',
        $billingMode,
        $discountType,
        $discountValue,
        $contract['tax_percent'],
        $subtotal,
        $total,
        'draft',
        $dueDate,
        date('Y-m-d H:i:s'),
        $contractOrgId,
        $contractCreator
    ]);
    
    $invoiceId = (int)$pdo->lastInsertId();
    if ($projectMonthlyBilling) {
        $pdo->prepare('UPDATE invoices SET collection_mode="project_aggregate" WHERE id=?')->execute([$invoiceId]);
    }
    
    // Assign doc number
    $docNumber = pa_next_invoice_doc_number($pdo, 'on_demand');
    $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$docNumber, $invoiceId]);
    
    // Add invoice items. Preserve itemized contracts; flat contracts get one line.
    $contractItemsStmt = $pdo->prepare('SELECT item, description, quantity, unit_price, line_total, billing_unit FROM contract_items WHERE contract_id = ? ORDER BY sort_order, id');
    $contractItemsStmt->execute([$contract_id]);
    $contractItems = $contractItemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($contractItems)) {
        $insertItem = $pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total, billing_unit) VALUES (?,?,?,?,?,?,?)');
        foreach ($contractItems as $item) {
            $insertItem->execute([
                $invoiceId,
                $item['item'] ?? '',
                $item['description'] ?? '',
                (float)($item['quantity'] ?? 0),
                (float)($item['unit_price'] ?? 0),
                (float)($item['line_total'] ?? 0),
                $item['billing_unit'] ?? ($billingMode === 'hourly' ? 'hour' : 'each'),
            ]);
        }
    } else {
        $description = 'On-demand service fee';
        if (!empty($contract['scope'])) {
            $description .= ' - ' . substr($contract['scope'], 0, 100);
        }

        $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total, billing_unit) VALUES (?,?,?,?,?,?)')
            ->execute([$invoiceId, $description, 1, $subtotal, $subtotal, $billingMode === 'hourly' ? 'hour' : 'each']);
    }
    
    // Update contract
    $newTotalInvoiced = (float)$contract['total_invoiced'] + $total;
    $newInvoiceCount = (int)$contract['invoice_count'] + 1;
    
    $pdo->prepare('UPDATE contracts SET total_invoiced=?, invoice_count=?, last_invoice_date=? WHERE id=? AND contract_type = "on_demand"')
        ->execute([$newTotalInvoiced, $newInvoiceCount, date('Y-m-d'), $contract_id]);
    
    $pdo->commit();
    
    @error_log("[on_demand_invoice_generate] Generated invoice ODI-$docNumber for contract ODC-{$contract['doc_number']} (\${$total})");

    // Generate-only remains private. The send choice finalizes first; monthly
    // project children are collected later through the project statement.
    if ($sendEmail) {
        invoice_finalize($pdo, $invoiceId, $appConfig, 'on_demand_send', $contractCreator);
    }
    if ($sendEmail && !$projectMonthlyBilling) {
        try {
            $clientStmt = $pdo->prepare('SELECT email, name FROM clients WHERE id = ?');
            $clientStmt->execute([$clientId]);
            $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
            $to = (string)($client['email'] ?? '');

            if ($to !== '') {
                // Build SMTP config from app settings (same pattern as generate_recurring_invoices.php)
                $smtpPass = '';
                if (!empty($appConfig['smtp_password_enc']) && is_string($appConfig['smtp_password_enc'])) {
                    $encVal = $appConfig['smtp_password_enc'];
                    if (strpos($encVal, 'plain::') === 0) {
                        $smtpPass = substr($encVal, 7);
                    } else {
                        $pt = crypto_decrypt($encVal);
                        if (is_string($pt)) { $smtpPass = $pt; }
                    }
                }
                $mailCfg = [
                    'host' => (string)($appConfig['smtp_host'] ?? ''),
                    'port' => (int)($appConfig['smtp_port'] ?? 587),
                    'secure' => strtolower((string)($appConfig['smtp_secure'] ?? 'tls')),
                    'username' => (string)($appConfig['smtp_username'] ?? ''),
                    'password' => $smtpPass,
                ];
                $fromEmail = (string)($appConfig['from_email'] ?? 'no-reply@localhost');
                $fromName = (string)($appConfig['from_name'] ?? ($appConfig['brand_name'] ?? 'Project Alpha'));

                // Duplicate prevention: skip if an on_generate notification already exists
                $dupStmt = $pdo->prepare('SELECT 1 FROM invoice_notifications WHERE invoice_id = ? AND notification_type = ?');
                $dupStmt->execute([$invoiceId, 'on_generate']);
                if (!$dupStmt->fetch()) {
                    $token = bin2hex(random_bytes(32));
                    $days = (int)($appConfig['documents_valid_days'] ?? 14);
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . max(0, $days) . ' days'));
                    $pdo->prepare('INSERT INTO public_links (document_type, document_id, token, expires_at, revoked, created_at) VALUES (?,?,?,?,0,NOW())')
                        ->execute(['invoice', $invoiceId, $token, $expiresAt]);
                    $link = '/?page=public-doc&type=invoice&token=' . rawurlencode($token);
                    $host = rtrim(($appConfig['app_host'] ?? ''), '/');
                    if ($host !== '') { $link = $host . $link; }

                    $subject = sprintf('Invoice I-%s has been generated', $docNumber);
                    $body = '<p>Dear ' . htmlspecialchars($client['name'] ?? '') . ',</p>';
                    $body .= '<p>A new invoice <strong>I-' . htmlspecialchars((string)$docNumber) . '</strong> for <strong>$' . number_format($total, 2) . '</strong> has been generated';
                    if (!empty($dueDate)) {
                        $body .= ', due on <strong>' . htmlspecialchars($dueDate) . '</strong>';
                    }
                    $body .= '.</p>';
                    $body .= '<p>You can view and pay the invoice here: <a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>';
                    $body .= '<p>Thank you for your business!</p>';

                    [$ok, $err] = mailer_send($mailCfg, $to, $subject, $body, $fromEmail, $fromName, ($mailCfg['username'] ?: $fromEmail));
                    if ($ok) {
                        $insNotif = $pdo->prepare('INSERT IGNORE INTO invoice_notifications (invoice_id, notification_type, sent_at) VALUES (?,?,NOW())');
                        $insNotif->execute([$invoiceId, 'on_generate']);
                        @error_log("[on_demand_invoice_generate] Sent on-generate email for invoice I-" . $docNumber);
                    } else {
                        @error_log("[on_demand_invoice_generate] Failed to send on-generate email for invoice I-" . $docNumber . ": $err");
                    }
                }
            }
        } catch (Throwable $e) {
            @error_log('[on_demand_invoice_generate] Auto-email exception: ' . $e->getMessage());
        }
    }

    $redirect = '/?page=contract/on-demand-invoices-list&contract_id=' . $contract_id . '&invoice_generated=1';
    if ($sendEmail) {
        $redirect .= '&email_requested=1';
    }
    header('Location: ' . $redirect);
    exit;
    
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @error_log('[on_demand_invoice_generate] exception: ' . $e->getMessage(), 0);
    $msg = substr($e->getMessage(), 0, 200);
    header('Location: /?page=contract/on-demand-contracts-list&error=' . urlencode($msg));
    exit;
}
