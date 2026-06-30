<?php
/**
 * Stripe Reconciliation Cron Job
 * 
 * Fetches Payment Intents from Stripe and reconciles with local database.
 * Catches missed webhooks when PA was offline.
 * 
 * Run via cron: php /var/www/src/cron/stripe_reconciliation.php
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../services/StripeService.php';
require_once __DIR__ . '/../utils/cron_state.php';
require_once __DIR__ . '/../utils/stripe_financial_events.php';

$logPrefix = '[stripe_reconciliation]';
$jobName = 'stripe_reconciliation';

// Check if cron is enabled
if (empty($appConfig['cron_enabled'])) {
    @error_log("$logPrefix Cron is disabled in settings. Skipping.");
    exit(0);
}

// Check if Stripe is configured
if (!StripeService::isConfigured($appConfig)) {
    @error_log("$logPrefix Stripe is not configured. Skipping.");
    exit(0);
}

@error_log("$logPrefix Starting reconciliation at " . date('Y-m-d H:i:s'));

try {
    $stripe = StripeService::fromAppConfig($appConfig);
    if (!$stripe) {
        throw new Exception('Failed to initialize Stripe service');
    }
    
    // Get last run time
    $lastRun = cron_state_last_run($pdo, $jobName);
    
    // Default to 7 days ago if never run
    $since = $lastRun ? strtotime($lastRun) : strtotime('-7 days');
    $since = max($since, strtotime('-30 days')); // Never look back more than 30 days
    
    @error_log("$logPrefix Fetching Payment Intents since " . date('Y-m-d H:i:s', $since));
    
    // Fetch Payment Intents from Stripe
    $paymentIntents = $stripe->listPaymentIntents($since);
    
    $reconciled = 0;
    $skipped = 0;
    $errors = 0;
    
    foreach ($paymentIntents as $pi) {
        try {
            // Only process succeeded payments
            if ($pi['status'] !== 'succeeded') {
                $skipped++;
                continue;
            }

            if (!empty($pi['metadata']['pa_project_invoice_id']) || !empty($pi['metadata']['project_invoice_id'])) {
                require_once __DIR__ . '/../utils/project_invoice_billing.php';
                if (project_invoice_record_stripe_payment($pdo, $pi)) {
                    $reconciled++;
                } else {
                    $errors++;
                }
                continue;
            }
            
            // Get invoice ID from metadata
            $invoiceId = $pi['metadata']['pa_invoice_id'] ?? $pi['metadata']['invoice_id'] ?? null;
            if (!$invoiceId) {
                $skipped++;
                continue;
            }
            
            $invoiceId = (int)$invoiceId;
            $piId = $pi['id'];
            $amount = ($pi['amount'] ?? 0) / 100; // Convert cents to dollars
            $paymentAmount = isset($pi['metadata']['original_amount']) ? (float)$pi['metadata']['original_amount'] : $amount;
            $surchargeAmount = isset($pi['metadata']['surcharge_amount']) ? (float)$pi['metadata']['surcharge_amount'] : max(0, $amount - $paymentAmount);
            
            // Check if this payment is already recorded
            $existsStmt = $pdo->prepare('SELECT id FROM payments WHERE stripe_payment_intent_id = ?');
            $existsStmt->execute([$piId]);
            if ($existsStmt->fetchColumn()) {
                $skipped++;
                continue;
            }
            
            // Check if invoice exists
            $invStmt = $pdo->prepare('SELECT id, total, status, client_id FROM invoices WHERE id = ?');
            $invStmt->execute([$invoiceId]);
            $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                @error_log("$logPrefix Invoice $invoiceId not found for PI $piId");
                $skipped++;
                continue;
            }
            
            // Skip if already paid
            if ($invoice['status'] === 'paid') {
                $skipped++;
                continue;
            }
            
            // Record the payment
            $pdo->beginTransaction();
            
            $isAutoPay = 0;
            $pdo->prepare('
                INSERT INTO payments (client_id, invoice_id, amount, surcharge_paid, payment_method, stripe_payment_intent_id, auto_pay_attempt, status, payment_date, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), NOW())
            ')->execute([(int)$invoice['client_id'], $invoiceId, $paymentAmount, $surchargeAmount, 'stripe', $piId, $isAutoPay, 'succeeded']);
            $paymentId = (int)$pdo->lastInsertId();
            stripe_link_pending_financial_events($pdo, $paymentId, $piId);
            
            // Update invoice status
            $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount-refunded_amount,0)), 0) FROM payments WHERE invoice_id = ? AND status = "succeeded"');
            $sumStmt->execute([$invoiceId]);
            $totalPaid = (float)$sumStmt->fetchColumn();
            
            $invoiceTotal = (float)$invoice['total'];
            $newStatus = ($totalPaid >= $invoiceTotal) ? 'paid' : 'partial';
            
            // Update invoice (with graceful handling of amount_paid column)
            try {
                $pdo->prepare('UPDATE invoices SET status=?,amount_paid=?,balance_due=GREATEST(total-?,0),stripe_session_id=NULL,stripe_checkout_expires_at=NULL WHERE id=?')
                    ->execute([$newStatus, $totalPaid, $totalPaid, $invoiceId]);
            } catch (Throwable $e) {
                $pdo->prepare('UPDATE invoices SET status = ? WHERE id = ?')
                    ->execute([$newStatus, $invoiceId]);
            }
            
            // If fully paid, handle contract completion and public link revocation
            if ($newStatus === 'paid') {
                // Revoke public links
                try {
                    $pdo->prepare('UPDATE public_links SET revoked = 1, redirect = ? WHERE document_type = "invoice" AND document_id = ? AND revoked = 0')
                        ->execute(['/?page=public-redirect&type=invoice&reason=paid', $invoiceId]);
                } catch (Throwable $e) { /* ignore */ }
                
                // Mark linked contract as completed
                $coStmt = $pdo->prepare('SELECT contract_id FROM invoices WHERE id = ?');
                $coStmt->execute([$invoiceId]);
                $contractId = (int)$coStmt->fetchColumn();
                if ($contractId > 0) {
                    $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?')->execute(['completed', $contractId]);
                }
            }
            
            $pdo->commit();
            $reconciled++;

            try {
                require_once __DIR__ . '/../utils/payment_receipts.php';
                payment_receipt_issue($pdo, $paymentId, $appConfig);
            } catch (Throwable $receiptError) {
                @error_log("$logPrefix Receipt issue failed for payment $paymentId: " . $receiptError->getMessage());
            }
            
            @error_log("$logPrefix Reconciled payment $piId for invoice $invoiceId: \$$paymentAmount");
            
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors++;
            @error_log("$logPrefix Error processing PI {$pi['id']}: " . $e->getMessage());
        }
    }
    
    cron_state_mark_success($pdo, $jobName, "{$reconciled} reconciled; {$skipped} skipped; {$errors} errors");
    
    @error_log("$logPrefix Completed: $reconciled reconciled, $skipped skipped, $errors errors");
    
} catch (Throwable $e) {
    @error_log("$logPrefix Fatal error: " . $e->getMessage());
    
    cron_state_mark_failure($pdo, $jobName, $e);
    
    exit(1);
}

exit(0);
