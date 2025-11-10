<?php
// src/cron/generate_recurring_invoices.php
// Run this script via cron: php /var/www/src/cron/generate_recurring_invoices.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

$logPrefix = '[generate_recurring_invoices]';

// Check if cron is enabled in settings
if (empty($appConfig['cron_enabled'])) {
    @error_log("$logPrefix Cron is disabled in settings. Skipping invoice generation.");
    exit(0);
}

@error_log("$logPrefix Starting invoice generation run at " . date('Y-m-d H:i:s'));

try {
    // Find all active long-term contracts that need invoicing
    $today = date('Y-m-d');
    
    $query = 'SELECT * FROM long_term_contracts 
              WHERE status = ? 
              AND next_invoice_date IS NOT NULL 
              AND next_invoice_date <= ?
              ORDER BY next_invoice_date ASC';
    
    $stmt = $pdo->prepare($query);
    $stmt->execute(['active', $today]);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $invoicesGenerated = 0;
    $errors = 0;
    
    foreach ($contracts as $contract) {
        $pdo->beginTransaction();
        
        try {
            $contractId = $contract['id'];
            $clientId = $contract['client_id'];
            $projectCode = $contract['project_code'];
            
            // Calculate invoice amount
            $subtotal = 0;
            $items = [];
            
            if ($contract['pricing_type'] === 'per_invoice') {
                // Simple per-invoice pricing
                $subtotal = (float)$contract['price_per_invoice'];
            } else {
                // Fixed total - use line items
                $itemsQuery = $pdo->prepare('SELECT * FROM long_term_contract_items WHERE long_term_contract_id=?');
                $itemsQuery->execute([$contractId]);
                $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($items as $item) {
                    $subtotal += (float)$item['line_total'];
                }
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
            
            // Check if we've hit the contract total (for fixed_total pricing)
            if ($contract['pricing_type'] === 'fixed_total') {
                $contractTotal = (float)$contract['total'];
                $alreadyInvoiced = (float)$contract['total_invoiced'];
                $depositPaid = (float)$contract['deposit_paid'];
                
                // Adjust deposit from contract total
                $remainingAmount = $contractTotal - $alreadyInvoiced - $depositPaid;
                
                if ($remainingAmount <= 0) {
                    // Contract is fully invoiced - mark as completed
                    $pdo->prepare('UPDATE long_term_contracts SET status=?, next_invoice_date=NULL WHERE id=?')
                        ->execute(['completed', $contractId]);
                    @error_log("$logPrefix Contract LTC-{$contract['doc_number']} fully invoiced, marked as completed");
                    $pdo->commit();
                    continue;
                }
                
                // Don't invoice more than remaining
                if ($total > $remainingAmount) {
                    $total = $remainingAmount;
                }
            }
            
            // Create invoice
            $dueDate = date('Y-m-d', strtotime('+' . ($appConfig['net_terms_days'] ?? 30) . ' days'));
            
            $insertInvoice = $pdo->prepare('
                INSERT INTO invoices (
                    contract_id, client_id, project_code, 
                    discount_type, discount_value, tax_percent, 
                    subtotal, total, status, due_date, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            
            $insertInvoice->execute([
                null, // No regular contract_id, this is from long-term contract
                $clientId,
                $projectCode,
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
            $maxDoc = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices')->fetchColumn();
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
            } else {
                // Copy items from contract
                foreach ($items as $item) {
                    $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)')
                        ->execute([
                            $invoiceId,
                            $item['description'],
                            $item['quantity'],
                            $item['unit_price'],
                            $item['line_total']
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
            
            if ($shouldContinue) {
                $pdo->prepare('UPDATE long_term_contracts SET next_invoice_date=?, last_invoice_date=?, total_invoiced=? WHERE id=?')
                    ->execute([$nextDate, $today, $newTotalInvoiced, $contractId]);
            } else {
                $pdo->prepare('UPDATE long_term_contracts SET status=?, next_invoice_date=NULL, last_invoice_date=?, total_invoiced=? WHERE id=?')
                    ->execute(['completed', $today, $newTotalInvoiced, $contractId]);
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
    
    @error_log("$logPrefix Completed: $invoicesGenerated invoices generated, $errors errors");
    
    // Update last run timestamp in settings
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
    exit(1);
}

exit(0);
