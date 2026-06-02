<?php
// src/cron/link_expiration_checker.php
// Run daily to revoke expired public_links and send warnings for links expiring soon
// php /var/www/src/cron/link_expiration_checker.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

$logPrefix = '[link_expiration_checker]';

// Check if link expiration checker is enabled in settings
if (empty($appConfig['link_expiration_checker'])) {
    @error_log("$logPrefix Link expiration checker is disabled. Skipping.");
    exit(0);
}

@error_log("$logPrefix Starting link expiration check at " . date('Y-m-d H:i:s'));

$today = date('Y-m-d H:i:s');
$revokedCount = 0;
$warningsSent = 0;

try {
    // 1. Revoke expired public links that haven't been revoked yet
    $stmt = $pdo->prepare('
        SELECT id, token, document_type, document_id, expires_at
        FROM public_links
        WHERE expires_at IS NOT NULL
          AND expires_at < ?
          AND revoked = 0
    ');
    $stmt->execute([$today]);
    $expiredLinks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($expiredLinks as $link) {
        try {
            $pdo->prepare('UPDATE public_links SET revoked = 1 WHERE id = ?')->execute([$link['id']]);
            $revokedCount++;
        } catch (Throwable $e) {
            @error_log("$logPrefix Error revoking link {$link['id']}: " . $e->getMessage());
        }
    }
    
    if ($revokedCount > 0) {
        @error_log("$logPrefix Revoked {$revokedCount} expired public link(s).");
    }
    
    // 2. Send warnings for links expiring soon (if enabled)
    if (!empty($appConfig['link_expiration_warning'])) {
        $warningDays = (int)($appConfig['link_expiration_warning_days'] ?? 30);
        $warningDate = date('Y-m-d H:i:s', strtotime("+{$warningDays} days"));
        $todayStart = date('Y-m-d 00:00:00');
        
        // Find active links expiring within the warning window
        $stmt = $pdo->prepare('
            SELECT pl.id, pl.document_type, pl.document_id, pl.expires_at, pl.token
            FROM public_links pl
            WHERE pl.expires_at IS NOT NULL
              AND pl.expires_at > ?
              AND pl.expires_at <= ?
              AND pl.revoked = 0
        ');
        $stmt->execute([$todayStart, $warningDate]);
        $expiringLinks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($expiringLinks) && !empty($appConfig['from_email'])) {
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
                $fromEmail = (string)$appConfig['from_email'];
                $fromName = (string)($appConfig['from_name'] ?? ($appConfig['brand_name'] ?? 'Project Alpha'));
                
                $count = count($expiringLinks);
                $subject = "{$count} public link(s) expiring within {$warningDays} days";
                $body = "<p>The following public links will expire soon:</p><ul>";
                foreach ($expiringLinks as $pl) {
                    $body .= '<li>' . ucfirst($pl['document_type']) . ' #' . $pl['document_id'] . ' — expires ' . date('M j, Y', strtotime($pl['expires_at'])) . '</li>';
                }
                $body .= '</ul><p>Please regenerate links if clients still need access.</p>';
                
                [$ok, $err] = mailer_send($mailCfg, $fromEmail, $subject, $body, $fromEmail, $fromName, ($mailCfg['username'] ?: $fromEmail));
                if ($ok) { $warningsSent = $count; }
                else { @error_log("$logPrefix Warning email failed: $err"); }
            } catch (Throwable $e) {
                @error_log("$logPrefix Warning email error: " . $e->getMessage());
            }
        }
    }
    
    @error_log("$logPrefix Completed: {$revokedCount} revoked, {$warningsSent} warnings sent");
    
    // Update last run timestamp
    $configMount = '/var/www/config';
    $projectConfig = __DIR__ . '/../../config';
    $configDir = is_dir($configMount) ? $configMount : $projectConfig;
    $settingsFile = $configDir . '/settings.json';
    
    if (is_readable($settingsFile) && is_writable($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
        $settings['link_expiration_last_run'] = date('Y-m-d H:i:s');
        @file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
} catch (Throwable $e) {
    @error_log("$logPrefix Fatal error: " . $e->getMessage());
    exit(1);
}

exit(0);
