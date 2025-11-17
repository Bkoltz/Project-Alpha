<?php
// src/cron/auto_terminate_contracts.php
// Run this script via cron to auto-terminate expired contracts
// php /var/www/src/cron/auto_terminate_contracts.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

$logPrefix = '[auto_terminate_contracts]';

// Check if cron is enabled in settings
if (empty($appConfig['cron_enabled'])) {
    @error_log("$logPrefix Cron is disabled in settings. Skipping auto-termination.");
    exit(0);
}

@error_log("$logPrefix Starting auto-termination check at " . date('Y-m-d H:i:s'));

$today = date('Y-m-d');
$terminatedCount = 0;

try {
    // Auto-terminate long-term contracts past their end date
    $query = 'SELECT id, doc_number, end_date FROM long_term_contracts 
              WHERE status IN (?, ?) 
              AND end_date IS NOT NULL 
              AND end_date < ?';
    
    $stmt = $pdo->prepare($query);
    $stmt->execute(['active', 'paused', $today]);
    $ltContracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($ltContracts as $contract) {
        try {
            $pdo->prepare('UPDATE long_term_contracts SET status=?, next_invoice_date=NULL WHERE id=?')
                ->execute(['completed', $contract['id']]);
            $terminatedCount++;
            @error_log("$logPrefix Auto-terminated long-term contract LTC-{$contract['doc_number']} (end date: {$contract['end_date']})");
        } catch (Throwable $e) {
            @error_log("$logPrefix Error terminating LTC-{$contract['doc_number']}: " . $e->getMessage());
        }
    }
    
    // Auto-terminate on-demand contracts past their end date
    $query = 'SELECT id, doc_number, end_date FROM on_demand_contracts 
              WHERE status IN (?, ?) 
              AND end_date IS NOT NULL 
              AND end_date < ?';
    
    $stmt = $pdo->prepare($query);
    $stmt->execute(['active', 'paused', $today]);
    $odContracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($odContracts as $contract) {
        try {
            $pdo->prepare('UPDATE on_demand_contracts SET status=? WHERE id=?')
                ->execute(['completed', $contract['id']]);
            $terminatedCount++;
            @error_log("$logPrefix Auto-terminated on-demand contract ODC-{$contract['doc_number']} (end date: {$contract['end_date']})");
        } catch (Throwable $e) {
            @error_log("$logPrefix Error terminating ODC-{$contract['doc_number']}: " . $e->getMessage());
        }
    }
    
    @error_log("$logPrefix Completed: $terminatedCount contracts auto-terminated");
    
    // Update last run timestamp in settings
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
