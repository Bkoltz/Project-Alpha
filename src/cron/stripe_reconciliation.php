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
    $lastRunStmt = $pdo->prepare('SELECT last_run FROM cron_job_runs WHERE job_name = ?');
    $lastRunStmt->execute([$jobName]);
    $lastRun = $lastRunStmt->fetchColumn();
    
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
            
            // Get invoice ID from metadata
            $invoiceId = $pi['metadata']['pa_invoice_id'] ?? $pi['metadata']['invoice_id'] ?? null;
            if (!$invoiceId) {
                $skipped++;
                continue;
            }
            
            $invoiceId = (int)$invoiceId;
            $piId = $pi['id'];
            $amount = ($pi['amount'] ?? 0) / 100; // Convert cents to dollars
            
            // Check if this payment is already recorded
            $existsStmt = $pdo->prepare('SELECT id FROM payments WHERE stripe_payment_intent_id = ?');
            $existsStmt->execute([$piId]);
            if ($existsStmt->fetchColumn()) {
                $skipped++;
                continue;
            }
            
            // Check if invoice exists
            $invStmt = $pdo->prepare('SELECT id, total, status FROM invoices WHERE id = ?');
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
            
            $pdo->prepare('INSERT INTO payments (invoice_id, amount, payment_method, stripe_payment_intent_id, status, payment_date, created_at) VALUES (?, ?, ?, ?, ?, CURDATE(), NOW())')
                ->execute([$invoiceId, $amount, 'stripe', $piId, 'succeeded']);
            
            // Update invoice status
            $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND status = "succeeded"');
            $sumStmt->execute([$invoiceId]);
            $totalPaid = (float)$sumStmt->fetchColumn();
            
            $invoiceTotal = (float)$invoice['total'];
            $newStatus = ($totalPaid >= $invoiceTotal) ? 'paid' : 'partial';
            
            // Update invoice (with graceful handling of amount_paid column)
            try {
                $pdo->prepare('UPDATE invoices SET status = ?, amount_paid = ? WHERE id = ?')
                    ->execute([$newStatus, $totalPaid, $invoiceId]);
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
            
            @error_log("$logPrefix Reconciled payment $piId for invoice $invoiceId: \$$amount");
            
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors++;
            @error_log("$logPrefix Error processing PI {$pi['id']}: " . $e->getMessage());
        }
    }
    
    // Update cron_job_runs
    $pdo->prepare('INSERT INTO cron_job_runs (job_name, last_run, status) VALUES (?, NOW(), "success") ON DUPLICATE KEY UPDATE last_run = NOW(), status = "success", error_message = NULL')
        ->execute([$jobName]);
    
    @error_log("$logPrefix Completed: $reconciled reconciled, $skipped skipped, $errors errors");
    
} catch (Throwable $e) {
    @error_log("$logPrefix Fatal error: " . $e->getMessage());
    
    // Record failure
    try {
        $pdo->prepare('INSERT INTO cron_job_runs (job_name, last_run, status, error_message) VALUES (?, NOW(), "failed", ?) ON DUPLICATE KEY UPDATE last_run = NOW(), status = "failed", error_message = ?')
            ->execute([$jobName, $e->getMessage(), $e->getMessage()]);
    } catch (Throwable $e2) { /* ignore */ }
    
    exit(1);
}

exit(0);
