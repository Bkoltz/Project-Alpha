<?php
// src/cron/auto_terminate_contracts.php
// Updated: uses unified contracts table
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

$logPrefix = '[auto_terminate_contracts]';

if (empty($appConfig['cron_enabled'])) {
    @error_log("$logPrefix Cron is disabled in settings. Skipping auto-termination.");
    exit(0);
}

@error_log("$logPrefix Starting auto-termination check at " . date('Y-m-d H:i:s'));

$today = date('Y-m-d');
$terminatedCount = 0;

try {
    // Auto-terminate long-term and on-demand contracts past their end date
    // using the unified contracts table
    $query = 'SELECT id, doc_number, end_date, contract_type FROM contracts 
              WHERE contract_type IN (?, ?)
              AND status IN (?, ?) 
              AND end_date IS NOT NULL 
              AND end_date < ?';
    
    $stmt = $pdo->prepare($query);
    $stmt->execute(['long_term', 'on_demand', 'active', 'paused', $today]);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($contracts as $contract) {
        try {
            // Set next_invoice_date to NULL for long-term contracts
            $nextInvoiceUpdate = $contract['contract_type'] === 'long_term' ? ', next_invoice_date=NULL' : '';
            $pdo->prepare("UPDATE contracts SET status=? {$nextInvoiceUpdate} WHERE id=?")
                ->execute(['completed', $contract['id']]);
            $terminatedCount++;
            
            $prefix = strtoupper(substr($contract['contract_type'], 0, 1)) . 'TC';
            @error_log("$logPrefix Auto-terminated {$contract['contract_type']} contract {$prefix}-{$contract['doc_number']} (end date: {$contract['end_date']})");
        } catch (Throwable $e) {
            @error_log("$logPrefix Error terminating {$contract['contract_type']} contract {$contract['doc_number']}: " . $e->getMessage());
        }
    }
    
    @error_log("$logPrefix Completed: $terminatedCount contracts auto-terminated");
    
    $configMount = '/var/www/config';
    $projectConfig = __DIR__ . '/../../config';
    $configDir = is_dir($configMount) ? $configMount : $projectConfig;
    $settingsFile = $configDir . '/settings.json';
    
    if (is_readable($settingsFile) && is_writable($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
        $settings['auto_terminate_last_run'] = date('Y-m-d H:i:s');
        @file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
} catch (Throwable $e) {
    @error_log("$logPrefix Fatal error: " . $e->getMessage());
    exit(1);
}

exit(0);
