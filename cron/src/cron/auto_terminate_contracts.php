<?php
// src/cron/auto_terminate_contracts.php
// Run this script via cron to auto-terminate expired contracts and send expiration warnings
// php /var/www/src/cron/auto_terminate_contracts.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

$logPrefix = '[auto_terminate_contracts]';

// Check if auto-termination is enabled
if (empty($appConfig['auto_terminate_contracts'])) {
    @error_log("$logPrefix Auto-termination is disabled in settings. Skipping.");
    exit(0);
}

@error_log("$logPrefix Starting auto-termination check at " . date('Y-m-d H:i:s'));

$today = date('Y-m-d');
$terminatedCount = 0;
$warningsSent = 0;

// Build mailer config for email notifications
$mailCfg = null;
$fromEmail = '';
$fromName = '';
$adminEmail = '';
if (!empty($appConfig['contract_expiring_warning']) || !empty($appConfig['contract_expired_alert'])) {
    try {
        require_once __DIR__ . '/../utils/mailer.php';
        require_once __DIR__ . '/../utils/crypto.php';
        $smtpPass = '';
        if (!empty($appConfig['smtp_password_enc']) && is_string($appConfig['smtp_password_enc'])) {
            $encVal = $appConfig['smtp_password_enc'];
            if (strpos($encVal, 'plain::') === 0) { $smtpPass = substr($encVal, 7); }
            else { $pt = crypto_decrypt($encVal); if (is_string($pt)) { $smtpPass = $pt; } }
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
        // Admin email = from_email (notifications go to the business owner)
        $adminEmail = $fromEmail;
    } catch (Throwable $e) {
        @error_log("$logPrefix Could not initialize mailer: " . $e->getMessage());
    }
}

try {
    // ── 1. Contract expiring soon warnings ──
    if (!empty($appConfig['contract_expiring_warning']) && $mailCfg) {
        $warningDays = (int)($appConfig['contract_expiring_days'] ?? 30);
        $warningDate = date('Y-m-d', strtotime("+{$warningDays} days"));
        
        $stmt = $pdo->prepare('
            SELECT co.id, co.doc_number, co.contract_type, co.end_date, co.project_code, c.name AS client_name
            FROM contracts co JOIN clients c ON c.id = co.client_id
            WHERE co.status IN ("active", "paused")
            AND co.end_date IS NOT NULL
            AND co.end_date = ?
        ');
        $stmt->execute([$warningDate]);
        $expiring = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($expiring as $co) {
            $prefix = $co['contract_type'] === 'on_demand' ? 'ODC' : 'LTC';
            $subject = "Contract {$prefix}-{$co['doc_number']} expiring in {$warningDays} days";
            $body = "<p>Contract <strong>{$prefix}-{$co['doc_number']}</strong> for client <strong>" . htmlspecialchars($co['client_name']) . "</strong> ";
            $body .= "(Project {$co['project_code']}) is set to expire on <strong>" . date('M j, Y', strtotime($co['end_date'])) . "</strong>.</p>";
            $body .= "<p>Please review and take action if needed.</p>";
            try {
                [$ok, $err] = mailer_send($mailCfg, $adminEmail, $subject, $body, $fromEmail, $fromName, ($mailCfg['username'] ?: $fromEmail));
                if ($ok) { $warningsSent++; }
                else { @error_log("$logPrefix Failed to send expiring warning for {$prefix}-{$co['doc_number']}: $err"); }
            } catch (Throwable $e) { @error_log("$logPrefix Expiring warning email error: " . $e->getMessage()); }
        }
    }

    // ── 2. Auto-terminate expired contracts ──
    // Long-term contracts
    $stmt = $pdo->prepare('SELECT id, doc_number, end_date, client_id, project_code FROM contracts
              WHERE status IN (?, ?) AND contract_type = "long_term" AND end_date IS NOT NULL AND end_date < ?');
    $stmt->execute(['active', 'paused', $today]);
    $ltContracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($ltContracts as $contract) {
        try {
            $pdo->prepare('UPDATE contracts SET status=?, next_invoice_date=NULL WHERE id=? AND contract_type="long_term"')
                ->execute(['completed', $contract['id']]);
            $terminatedCount++;
            @error_log("$logPrefix Auto-terminated LTC-{$contract['doc_number']} (end date: {$contract['end_date']})");
            
            // Send expired alert
            if (!empty($appConfig['contract_expired_alert']) && $mailCfg) {
                $subject = "Contract LTC-{$contract['doc_number']} has been auto-terminated";
                $body = "<p>Long-term contract <strong>LTC-{$contract['doc_number']}</strong> (Project {$contract['project_code']}) ";
                $body .= "has been automatically terminated because it reached its end date ({$contract['end_date']}).</p>";
                try {
                    mailer_send($mailCfg, $adminEmail, $subject, $body, $fromEmail, $fromName, ($mailCfg['username'] ?: $fromEmail));
                } catch (Throwable $e) { /* ignore */ }
            }
        } catch (Throwable $e) {
            @error_log("$logPrefix Error terminating LTC-{$contract['doc_number']}: " . $e->getMessage());
        }
    }
    
    // On-demand contracts
    $stmt = $pdo->prepare('SELECT id, doc_number, end_date, client_id, project_code FROM contracts
              WHERE status IN (?, ?) AND contract_type = "on_demand" AND end_date IS NOT NULL AND end_date < ?');
    $stmt->execute(['active', 'paused', $today]);
    $odContracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($odContracts as $contract) {
        try {
            $pdo->prepare('UPDATE contracts SET status=? WHERE id=? AND contract_type="on_demand"')
                ->execute(['completed', $contract['id']]);
            $terminatedCount++;
            @error_log("$logPrefix Auto-terminated ODC-{$contract['doc_number']} (end date: {$contract['end_date']})");
            
            if (!empty($appConfig['contract_expired_alert']) && $mailCfg) {
                $subject = "Contract ODC-{$contract['doc_number']} has been auto-terminated";
                $body = "<p>On-demand contract <strong>ODC-{$contract['doc_number']}</strong> (Project {$contract['project_code']}) ";
                $body .= "has been automatically terminated because it reached its end date ({$contract['end_date']}).</p>";
                try {
                    mailer_send($mailCfg, $adminEmail, $subject, $body, $fromEmail, $fromName, ($mailCfg['username'] ?: $fromEmail));
                } catch (Throwable $e) { /* ignore */ }
            }
        } catch (Throwable $e) {
            @error_log("$logPrefix Error terminating ODC-{$contract['doc_number']}: " . $e->getMessage());
        }
    }
    
    @error_log("$logPrefix Completed: $terminatedCount terminated, $warningsSent expiry warnings sent");
    
    // Update last run timestamp
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
