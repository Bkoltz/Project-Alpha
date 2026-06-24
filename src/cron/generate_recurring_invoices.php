<?php
// src/cron/generate_recurring_invoices.php
// Run this script via cron: php /var/www/src/cron/generate_recurring_invoices.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/cron_state.php';
require_once __DIR__ . '/../utils/org_resolver.php';

$logPrefix = '[generate_recurring_invoices]';
$jobName = 'generate_recurring_invoices';

// Check if cron is enabled in settings
if (empty($appConfig['cron_enabled'])) {
    @error_log("$logPrefix Cron is disabled in settings. Skipping invoice generation.");
    exit(0);
}

@error_log("$logPrefix Starting invoice generation run at " . date('Y-m-d H:i:s'));

try {
    $today = date('Y-m-d');
    $invoicesGenerated = 0;
    $errors = 0;
    $catchUpPasses = 0;
    $maxCatchUpPasses = 36;
    
    do {
    $catchUpPasses++;

    // Refetch after each pass so contracts that remain overdue generate the
    // next missed invoice during the same cron run.
    $query = 'SELECT * FROM contracts
              WHERE status = ? 
              AND contract_type = "long_term"
              AND next_invoice_date IS NOT NULL 
              AND next_invoice_date <= ?
              ORDER BY next_invoice_date ASC';

    $stmt = $pdo->prepare($query);
    $stmt->execute(['active', $today]);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($contracts as $contract) {
        $pdo->beginTransaction();
        
        try {
            $contractId = $contract['id'];
            $clientId = $contract['client_id'];
            $projectCode = $contract['project_code'];
            $projectId = !empty($contract['project_id']) ? (int)$contract['project_id'] : null;
            
            // Resolve organization_id: inherit from contract, or fall back to client
            $orgId = (int)($contract['organization_id'] ?? 0) ?: org_id_for_client($pdo, (int)$clientId);
            $orgId = $orgId ?: null;
            
            // Calculate invoice amount
            $subtotal = 0;
            $items = [];
            
            if ($contract['pricing_type'] === 'per_invoice') {
                // Simple per-invoice pricing - recurring amount
                $subtotal = (float)$contract['price_per_invoice'];
            } elseif ($contract['pricing_type'] === 'fixed_total') {
                // Fixed total - divide total by invoice_count
                $invoiceCount = (int)($contract['invoice_count'] ?? 1);
                $contractInvoicesGenerated = (int)($contract['invoices_generated'] ?? 0);
                $contractTotal = (float)$contract['total'];
                $depositPaid = (float)$contract['deposit_paid'];
                
                // Calculate amount to invoice: (total - deposit) / invoice_count
                $amountToInvoice = ($contractTotal - $depositPaid) / $invoiceCount;
                $subtotal = $amountToInvoice;
                
                // Load items for display (will be shown proportionally)
                $itemsQuery = $pdo->prepare('SELECT * FROM contract_items WHERE contract_id=?');
                $itemsQuery->execute([$contractId]);
                $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Apply discount and tax (already factored into subtotal for fixed_total)
            $discountType = $contract['discount_type'] ?? 'none';
            $discountValue = (float)($contract['discount_value'] ?? 0);
            $taxPercent = (float)$contract['tax_percent'];
            
            // For fixed_total, discount and tax are already calculated in the contract total
            // For per_invoice, apply them per invoice
            if ($contract['pricing_type'] === 'per_invoice') {
                $discount = 0;
                if ($discountType === 'percent') {
                    $discount = max(0, min(100, $discountValue)) * $subtotal / 100;
                } elseif ($discountType === 'fixed') {
                    $discount = $discountValue;
                }
                $taxable = max(0, $subtotal - $discount);
                $tax = max(0, $taxPercent) * $taxable / 100;
                $total = max(0, $taxable + $tax);
            } else {
                // fixed_total: subtotal already has discount/tax baked in
                $total = $subtotal;
            }
            
            // Check if we've reached the invoice limit (for fixed_total pricing)
            if ($contract['pricing_type'] === 'fixed_total') {
                $invoiceCount = (int)($contract['invoice_count'] ?? 1);
                $contractInvoicesGenerated = (int)($contract['invoices_generated'] ?? 0);
                
                if ($contractInvoicesGenerated >= $invoiceCount) {
                    // All invoices generated - mark as completed
                    $pdo->prepare('UPDATE contracts SET status=?, next_invoice_date=NULL WHERE id=? AND contract_type="long_term"')
                        ->execute(['completed', $contractId]);
                    @error_log("$logPrefix Contract LTC-{$contract['doc_number']} all {$invoiceCount} invoices generated, marked as completed");
                    $pdo->commit();
                    continue;
                }
            }
            
            // Create invoice
            $dueDate = date('Y-m-d', strtotime('+' . ($appConfig['net_terms_days'] ?? 30) . ' days'));
            
            $insertInvoice = $pdo->prepare('
                INSERT INTO invoices (
                    contract_id, client_id, project_id, project_code, organization_id, invoice_type,
                    discount_type, discount_value, tax_percent, 
                    subtotal, total, status, due_date, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            
            $insertInvoice->execute([
                $contractId, // Link to long-term contract
                $clientId,
                $projectId,
                $projectCode,
                $orgId,
                'long_term',
                $discountType,
                $discountValue,
                $contract['tax_percent'],
                $subtotal,
                $total,
                'unpaid',
                $dueDate
            ]);
            
            $invoiceId = (int)$pdo->lastInsertId();
            
            // Assign doc number
            $maxDoc = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices WHERE invoice_type = "long_term"')->fetchColumn();
            $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$maxDoc + 1, $invoiceId]);
            
            // Add invoice items
            if ($contract['pricing_type'] === 'per_invoice') {
                // Single line item for recurring service
                $billingInterval = $contract['billing_interval_count'] . ' ' . ucfirst($contract['billing_interval_unit']);
                if ($contract['billing_interval_count'] > 1) $billingInterval .= 's';
                
                $description = 'Recurring service fee (' . strtolower($billingInterval) . ')';
                if (!empty($contract['scope'])) {
                    $description .= ' - ' . substr($contract['scope'], 0, 100);
                }
                
                $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)')
                    ->execute([$invoiceId, $description, 1, $total, $total]);
            } elseif ($contract['pricing_type'] === 'fixed_total') {
                // For fixed_total, show items proportionally divided
                $invoiceCount = (int)($contract['invoice_count'] ?? 1);
                $contractInvoicesGenerated = (int)($contract['invoices_generated'] ?? 0);
                $invoiceNum = $contractInvoicesGenerated + 1;
                
                // Calculate proportion for this invoice
                foreach ($items as $item) {
                    $proportionalQty = (float)$item['quantity'] / $invoiceCount;
                    $proportionalTotal = (float)$item['line_total'] / $invoiceCount;
                    
                    $description = $item['description'] . ' (Payment ' . $invoiceNum . ' of ' . $invoiceCount . ')';
                    
                    $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)')
                        ->execute([
                            $invoiceId,
                            $description,
                            $proportionalQty,
                            $item['unit_price'],
                            $proportionalTotal
                        ]);
                }
            }
            
            // Calculate next invoice date
            $currentDate = $contract['next_invoice_date'];
            $intervalCount = (int)$contract['billing_interval_count'];
            $intervalUnit = $contract['billing_interval_unit'];
            
            $nextDate = date('Y-m-d', strtotime($currentDate . ' +' . $intervalCount . ' ' . $intervalUnit));
            
            // Check if we should continue invoicing
            $shouldContinue = true;
            if (!empty($contract['end_date'])) {
                if ($nextDate > $contract['end_date']) {
                    $shouldContinue = false;
                    $nextDate = null;
                }
            }
            
            // Update contract
            $newTotalInvoiced = (float)$contract['total_invoiced'] + $total;
            $newInvoicesGenerated = (int)($contract['invoices_generated'] ?? 0) + 1;
            
            if ($shouldContinue) {
                $pdo->prepare('UPDATE contracts SET next_invoice_date=?, last_invoice_date=?, total_invoiced=?, invoices_generated=? WHERE id=? AND contract_type="long_term"')
                    ->execute([$nextDate, $today, $newTotalInvoiced, $newInvoicesGenerated, $contractId]);
            } else {
                $pdo->prepare('UPDATE contracts SET status=?, next_invoice_date=NULL, last_invoice_date=?, total_invoiced=?, invoices_generated=? WHERE id=? AND contract_type="long_term"')
                    ->execute(['completed', $today, $newTotalInvoiced, $newInvoicesGenerated, $contractId]);
            }
            
            $pdo->commit();
            $invoicesGenerated++;
            
            @error_log("$logPrefix Generated invoice INV-$maxDoc for contract LTC-{$contract['doc_number']} (\${$total})");
            
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors++;
            @error_log("$logPrefix Error processing contract {$contract['id']}: " . $e->getMessage());
        }
    }

    } while (!empty($contracts) && $catchUpPasses < $maxCatchUpPasses);

    if ($catchUpPasses >= $maxCatchUpPasses && !empty($contracts)) {
        $errors++;
        @error_log("$logPrefix Catch-up stopped at {$maxCatchUpPasses} passes to avoid an infinite loop.");
    }
    
    @error_log("$logPrefix Completed: $invoicesGenerated invoices generated across $catchUpPasses catch-up pass(es), $errors errors");
    
    // Automatic invoice notification deliveries (due reminders & overdue weekly reminders)
    try {
        require_once __DIR__ . '/../utils/mailer.php';
        require_once __DIR__ . '/../utils/crypto.php';

        // helper: build SMTP config from app settings
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

        // small helper to create a short public link for invoice viewing
        $createPublicLink = function(int $invoiceId) use ($pdo, $appConfig) {
            $token = bin2hex(random_bytes(16));
            $days = (int)($appConfig['documents_valid_days'] ?? 14);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . max(0,$days) . ' days'));
            $ins = $pdo->prepare('INSERT INTO public_links (document_type, document_id, token, expires_at, revoked, created_at) VALUES (?,?,?,?,0,NOW())');
            $ins->execute(['invoice', $invoiceId, $token, $expiresAt]);
            return $token;
        };

        // 1) 7-day due reminders
        if (!empty($appConfig['invoice_auto_send_due_7days'])) {
            $due7 = date('Y-m-d', strtotime('+7 days'));
            $stmt = $pdo->prepare("SELECT i.id,i.doc_number,i.total,i.due_date,c.email,c.name FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.due_date = ? AND i.status IN ('unpaid','partial')");
            $stmt->execute([$due7]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $inv) {
                $iid = (int)$inv['id'];
                // skip if we've already sent this reminder
                $chk = $pdo->prepare('SELECT COUNT(*) FROM invoice_notifications WHERE invoice_id=? AND notification_type=?');
                $chk->execute([$iid, 'due_7']);
                if ((int)$chk->fetchColumn() > 0) { continue; }

                // create short-lived public link
                $token = $createPublicLink($iid);
                $link = sprintf('%s/?page=public_doc&type=invoice&token=%s', rtrim(($appConfig['app_host'] ?? ''), '/'), rawurlencode($token));
                if ($link === '/?page=public_doc&type=invoice&token=' . rawurlencode($token)) {
                    // fallback to relative path if app_host not set
                    $link = '/?page=public_doc&type=invoice&token=' . rawurlencode($token);
                }

                $to = (string)$inv['email'];
                if ($to === '') continue;
                $subject = sprintf('Invoice I-%s due %s', $inv['doc_number'] ?? $iid, date('M j, Y', strtotime($inv['due_date'])));
                $body = '<p>Dear ' . htmlspecialchars($inv['name'] ?? '') . ',</p>';
                $body .= '<p>This is a reminder that invoice <strong>I-' . htmlspecialchars($inv['doc_number'] ?? $iid) . '</strong> for <strong>$' . number_format((float)$inv['total'],2) . '</strong> is due on <strong>' . htmlspecialchars($inv['due_date']) . '</strong>.</p>';
                $body .= '<p>You can view this invoice here: <a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>';
                $body .= '<p>If you have already paid, please disregard this message.</p>';

                try {
                    [$ok, $err] = mailer_send($mailCfg, $to, $subject, $body, $fromEmail, $fromName, ($mailCfg['username'] ?: $fromEmail));
                    if ($ok) {
                        $insn = $pdo->prepare('INSERT INTO invoice_notifications (invoice_id, notification_type, sent_at) VALUES (?,?,NOW())');
                        $insn->execute([$iid, 'due_7']);
                    } else {
                        @error_log("$logPrefix Failed to send due-7 reminder for invoice $iid: $err");
                    }
                } catch (Throwable $e) {
                    @error_log("$logPrefix Exception sending due-7 reminder for invoice $iid: " . $e->getMessage());
                }
            }
        }

        // 2) Weekly overdue reminders (at most once per 7 days for each invoice)
        if (!empty($appConfig['invoice_auto_send_overdue_weekly'])) {
            $todayDate = date('Y-m-d');
            $stmt = $pdo->prepare("SELECT i.id,i.doc_number,i.total,i.due_date,c.email,c.name FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.due_date < ? AND i.status IN ('unpaid','partial')");
            $stmt->execute([$todayDate]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $inv) {
                $iid = (int)$inv['id'];
                // check last sent timestamp
                $chk = $pdo->prepare('SELECT MAX(sent_at) AS last_sent FROM invoice_notifications WHERE invoice_id=? AND notification_type=?');
                $chk->execute([$iid, 'overdue_weekly']);
                $last = $chk->fetchColumn();
                $send = false;
                if ($last === null || $last === false) { $send = true; }
                else {
                    $lastTs = strtotime($last);
                    if ($lastTs === false) { $send = true; }
                    elseif ($lastTs <= strtotime('-7 days')) { $send = true; }
                }
                if (!$send) continue;

                // create public link for convenience
                $token = $createPublicLink($iid);
                $link = sprintf('%s/?page=public_doc&type=invoice&token=%s', rtrim(($appConfig['app_host'] ?? ''), '/'), rawurlencode($token));
                if ($link === '/?page=public_doc&type=invoice&token=' . rawurlencode($token)) {
                    $link = '/?page=public_doc&type=invoice&token=' . rawurlencode($token);
                }

                $to = (string)$inv['email'];
                if ($to === '') continue;
                $subject = sprintf('Overdue invoice I-%s', $inv['doc_number'] ?? $iid);
                $body = '<p>Dear ' . htmlspecialchars($inv['name'] ?? '') . ',</p>';
                $body .= '<p>This is a reminder that invoice <strong>I-' . htmlspecialchars($inv['doc_number'] ?? $iid) . '</strong> for <strong>$' . number_format((float)$inv['total'],2) . '</strong> became overdue on <strong>' . htmlspecialchars($inv['due_date']) . '</strong>.</p>';
                $body .= '<p>Please view and pay the invoice here: <a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>';
                $body .= '<p>If you have already paid, please disregard this message.</p>';

                try {
                    [$ok, $err] = mailer_send($mailCfg, $to, $subject, $body, $fromEmail, $fromName, ($mailCfg['username'] ?: $fromEmail));
                    if ($ok) {
                        $insn = $pdo->prepare('INSERT INTO invoice_notifications (invoice_id, notification_type, sent_at) VALUES (?,?,NOW())');
                        $insn->execute([$iid, 'overdue_weekly']);
                    } else {
                        @error_log("$logPrefix Failed to send overdue-weekly reminder for invoice $iid: $err");
                    }
                } catch (Throwable $e) {
                    @error_log("$logPrefix Exception sending overdue-weekly reminder for invoice $iid: " . $e->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        @error_log("$logPrefix Notification pass error: " . $e->getMessage());
    }

    cron_state_mark_success($pdo, $jobName, "Generated {$invoicesGenerated} invoice(s); {$errors} error(s); {$catchUpPasses} catch-up pass(es)");
    
    // Update last run timestamp in settings (legacy support)
    $configMount = '/var/www/config';
    $projectConfig = __DIR__ . '/../../config';
    $configDir = is_dir($configMount) ? $configMount : $projectConfig;
    $settingsFile = $configDir . '/settings.json';
    
    if (is_readable($settingsFile) && is_writable($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
        $settings['cron_last_run'] = date('Y-m-d H:i:s');
        @file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
} catch (Throwable $e) {
    @error_log("$logPrefix Fatal error: " . $e->getMessage());
    cron_state_mark_failure($pdo, $jobName, $e);
    exit(1);
}

exit(0);
