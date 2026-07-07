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
require_once __DIR__ . '/../utils/stripe_reconciliation_import.php';
require_once __DIR__ . '/../utils/stripe_payment_accounting.php';

$logPrefix = '[stripe_reconciliation]';
$jobName = 'stripe_reconciliation';

// Check if cron is enabled
if (empty($appConfig['cron_enabled'])) {
    @error_log("$logPrefix Cron is disabled in settings. Skipping.");
    cron_state_mark_success($pdo, $jobName, 'Cron disabled');
    exit(0);
}

// Check if Stripe is configured
if (!StripeService::isConfigured($appConfig)) {
    @error_log("$logPrefix Stripe is not configured. Skipping.");
    cron_state_mark_success($pdo, $jobName, 'Stripe not configured');
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
    
    $result = stripe_reconcile_payment_intents($pdo, $stripe, $appConfig, $since, null, 1000, false);

    $backfill = stripe_backfill_net_income($pdo, $stripe, $appConfig, 100);
    $result['reconciled'] += (int)$backfill['updated'] + (int)$backfill['estimated'];
    $result['skipped'] += (int)$backfill['unknown'] + (int)$backfill['skipped'];
    $result['errors'] += (int)$backfill['failed'];
    
    cron_state_mark_success($pdo, $jobName, stripe_reconcile_summary($result));
    
    @error_log("$logPrefix Completed: " . stripe_reconcile_summary($result));
    
} catch (Throwable $e) {
    @error_log("$logPrefix Fatal error: " . $e->getMessage());
    
    cron_state_mark_failure($pdo, $jobName, $e);
    
    exit(1);
}

exit(0);
