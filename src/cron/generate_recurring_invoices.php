<?php
// src/cron/generate_recurring_invoices.php
// Updated: uses unified contracts table instead of long_term_contracts
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

$logPrefix = '[generate_recurring_invoices]';
$jobName = 'generate_recurring_invoices';

if (empty($appConfig['cron_enabled'])) {
    @error_log("$logPrefix Cron is disabled in settings. Skipping invoice generation.");
    exit(0);
}

@error_log("$logPrefix Starting invoice generation run at " . date('Y-m-d H:i:s'));

try {
    $today = date('Y-m-d');
    
    // Query unified contracts table with contract_type='long_term'
    $query = 'SELECT * FROM contracts 
              WHERE contract_type = ? AND status = ? 
              AND next_invoice_date IS NOT NULL 
              AND next_invoice_date <= ?
              ORDER BY next_invoice_date ASC';
    
    $stmt = $pdo->prepare($query);
    $stmt->execute(['long_term', 'active', $today]);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $invoicesGenerated = 0;
    $errors = 0;
    
    foreach ($contracts as $contract) {
        $pdo->beginTransaction();
        
        try {
            $contractId = $contract['id'];
            $clientId = $contract['client_id'];
            $projectCode = $contract['project_code'];
            $projectId = !empty($contract['project_id']) ? (int)$contract['project_id'] : null;
            
            $subtotal = 0;
            $items = [];
            
            if ($contract['pricing_type'] === 'per_invoice') {
                $subtotal = (float)$contract['price_per_invoice'];
            } elseif ($contract['pricing_type'] === 'fixed_total') {
                $invoiceCount = (int)($contract['invoice_count'] ?? 1);
                $invoicesGenerated = (int)($contract['invoices_generated'] ?? 0);
                $contractTotal = (float)$contract['total'];
                $depositPaid = (float)$contract['deposit_paid'];
                
                $amountToInvoice = ($contractTotal - $depositPaid) / $invoiceCount;
                $subtotal = $amountToInvoice;
                
                // Load items from unified contract_items table
                $itemsQuery = $pdo->prepare('SELECT * FROM contract_items WHERE contract_id=?');
                $itemsQuery->execute([$contractId]);
                $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
            }
            
            $discountType = $contract['discount_type'] ?? 'none';
            $discountValue = (float)($contract['discount_value'] ?? 0);
            $taxPercent = (float)$contract['tax_percent'];
            
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
                $total = $subtotal;
            }
            
            if ($contract['pricing_type'] === 'fixed_total') {
                $invoiceCount = (int)($contract['invoice_count'] ?? 1);
                $invoicesGenerated = (int)($contract['invoices_generated'] ?? 0);
                
                if ($invoicesGenerated >= $invoiceCount) {
                    $pdo->prepare('UPDATE contracts SET status=?, next_invoice_date=NULL WHERE id=?')
                        ->execute(['completed', $contractId]);
                    @error_log("$logPrefix Contract LTC-{$contract['doc_number']} all {$invoiceCount} invoices generated, marked as completed");
                    $pdo->commit();
                    continue;
                }
            }
            
            $dueDate = date('Y-m-d', strtotime('+' . ($appConfig['net_terms_days'] ?? 30) . ' days'));
            
            // Create invoice linked to unified contract
            $insertInvoice = $pdo->prepare('
                INSERT INTO invoices (
                    contract_id, contract_type, client_id, project_id, project_code, 
                    discount_type, discount_value, tax_percent, 
                    subtotal, total, status, due_date, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            
            $insertInvoice->execute([
                $contractId,
                'long_term',
                $clientId,
                $projectId,
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
            
            $maxDoc = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices')->fetchColumn();
            $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$maxDoc + 1, $invoiceId]);
            
            if ($contract['pricing_type'] === 'per_invoice') {
                $billingInterval = $contract['billing_interval_count'] . ' ' . ucfirst($contract['billing_interval_unit']);
                if ($contract['billing_interval_count'] > 1) $billingInterval .= 's';
                
                $description = 'Recurring service fee (' . strtolower($billingInterval) . ')';
                if (!empty($contract['scope'])) {
                    $description .= ' - ' . substr($contract['scope'], 0, 100);
                }
                
                $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)')
                    ->execute([$invoiceId, $description, 1, $total, $total]);
            } elseif ($contract['pricing_type'] === 'fixed_total') {
                $invoiceCount = (int)($contract['invoice_count'] ?? 1);
                $invoicesGenerated = (int)($contract['invoices_generated'] ?? 0);
                $invoiceNum = $invoicesGenerated + 1;
                
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
            
            $currentDate = $contract['next_invoice_date'];
            $intervalCount = (int)$contract['billing_interval_count'];
            $intervalUnit = $contract['billing_interval_unit'];
            
            $nextDate = date('Y-m-d', strtotime($currentDate . ' +' . $intervalCount . ' ' . $intervalUnit));
            
            $shouldContinue = true;
            if (!empty($contract['end_date'])) {
                if ($nextDate > $contract['end_date']) {
                    $shouldContinue = false;
                    $nextDate = null;
                }
            }
            
            $newTotalInvoiced = (float)$contract['total_invoiced'] + $total;
            $newInvoicesGenerated = (int)($contract['invoices_generated'] ?? 0) + 1;
            
            if ($shouldContinue) {
                $pdo->prepare('UPDATE contracts SET next_invoice_date=?, last_invoice_date=?, total_invoiced=?, invoices_generated=? WHERE id=?')
                    ->execute([$nextDate, $today, $newTotalInvoiced, $newInvoicesGenerated, $contractId]);
            } else {
                $pdo->prepare('UPDATE contracts SET status=?, next_invoice_date=NULL, last_invoice_date=?, total_invoiced=?, invoices_generated=? WHERE id=?')
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
    
    @error_log("$logPrefix Completed: $invoicesGenerated invoices generated, $errors errors");

} catch (Throwable $e) {
    @error_log("$logPrefix Fatal error: " . $e->getMessage());
    exit(1);
}

exit(0);
