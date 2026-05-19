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
require_once __DIR__ . '/../utils/cron_logger.php';

$jobName = 'stripe_reconciliation';

// Check if cron is enabled
if (empty($appConfig['cron_enabled'])) {
    cron_log($jobName, 'Cron is disabled in settings. Skipping.', [], 'info');
    exit(0);
}

// Check if Stripe is configured
if (!StripeService::isConfigured($appConfig)) {
    cron_log($jobName, 'Stripe is not configured. Skipping.', [], 'info');
    exit(0);
}

cron_log_start($jobName);

try {
    $stripe = StripeService::fromAppConfig($appConfig);
    if (!$stripe) {
        throw new Exception('Failed to initialize Stripe service');
    }
    
    // Get last run time
    $lastRunStmt = $pdo->prepare('SELECT last_run FROM cron_job_runs WHERE job_name = ?');
    $lastRunStmt->execute([$jobName]);
    $lastRun = $lastRunStmt->fetchColumn();
    
    if (!$lastRun) {
        cron_log($jobName, 'First run - no previous timestamp. Starting from 24 hours ago.', [], 'info');
        $lastRun = date('Y-m-d H:i:s', strtotime('-24 hours'));
    }
    
    // Get recent payment intents from Stripe (last 7 days or since last run)
    $lookback = max(strtotime($lastRun), strtotime('-7 days'));
    $lookbackDate = date('Y-m-d H:i:s', $lookback);
    
    cron_log($jobName, "Fetching payments since {$lookbackDate}", [], 'info');
    
    $payments = $stripe->getPaymentsSince($lookbackDate);
    
    $reconciled = 0;
    $alreadyMatched = 0;
    $errors = 0;
    
    foreach ($payments as $payment) {
        try {
            $paymentIntentId = $payment['id'];
            $amount = $payment['amount'] / 100; // Convert from cents
            $status = $payment['status'];
            $createdAt = date('Y-m-d H:i:s', $payment['created']);
            
            // Check if we already have this payment
            $checkStmt = $pdo->prepare('SELECT id FROM payments WHERE stripe_payment_intent_id = ?');
            $checkStmt->execute([$paymentIntentId]);
            
            if ($checkStmt->fetch()) {
                $alreadyMatched++;
                continue;
            }
            
            // Find invoice by payment intent ID
            $invoiceStmt = $pdo->prepare('
                SELECT i.id, i.client_id, i.total 
                FROM invoices i 
                WHERE i.stripe_payment_intent_id = ? 
                OR i.id IN (
                    SELECT invoice_id FROM payment_intents 
                    WHERE stripe_payment_intent_id = ?
                )
            ');
            $invoiceStmt->execute([$paymentIntentId, $paymentIntentId]);
            $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                cron_log($jobName, "No matching invoice for payment intent: {$paymentIntentId}", [], 'warning');
                continue;
            }
            
            // Record the payment
            $insertStmt = $pdo->prepare('
                INSERT INTO payments (invoice_id, amount, payment_method, stripe_payment_intent_id, paid_at, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ');
            $insertStmt->execute([
                $invoice['id'],
                $amount,
                'Card',
                $paymentIntentId,
                $createdAt
            ]);
            
            // Mark invoice as paid
            $updateStmt = $pdo->prepare('UPDATE invoices SET status = ?, paid_at = ? WHERE id = ?');
            $updateStmt->execute(['paid', $createdAt, $invoice['id']]);
            
            $reconciled++;
            
        } catch (Throwable $e) {
            $errors++;
            cron_log_error($jobName, "Error processing payment {$payment['id']}: " . $e->getMessage());
        }
    }
    
    cron_log_end($jobName, [
        'reconciled' => $reconciled,
        'already_matched' => $alreadyMatched,
        'errors' => $errors,
        'total_checked' => count($payments)
    ]);
    
} catch (Throwable $e) {
    cron_log_error($jobName, 'Fatal error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
    exit(1);
}

exit(0);
