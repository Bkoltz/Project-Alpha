<?php
// src/controllers/settings/links_handler.php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/link_provider_config.php';

if (empty($_SESSION['user'])) {
    http_response_code(401);
    exit('Authentication required');
}

if (!csrf_validate()) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

try {
    // Ensure app_config table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        organization_id INT NOT NULL DEFAULT 0,
        config_key VARCHAR(100) NOT NULL,
        config_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_app_config (organization_id, config_key),
        INDEX idx_config_key (config_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $hasOrgColumn = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='app_config' AND COLUMN_NAME='organization_id'")->fetchColumn();
    if (!$hasOrgColumn) {
        $pdo->exec("ALTER TABLE app_config ADD COLUMN organization_id INT NOT NULL DEFAULT 0 AFTER id");
    }
    
    // Ensure link_resolver_config table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS link_resolver_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        provider VARCHAR(50) NOT NULL UNIQUE,
        is_enabled TINYINT(1) NOT NULL DEFAULT 0,
        credentials TEXT,
        default_expiration_days INT DEFAULT 365,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $readConfig = function (string $key, $default = null) use ($pdo) {
        $stmt = $pdo->prepare('SELECT config_value FROM app_config WHERE organization_id = 0 AND config_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    };

    // Save only the global settings that are currently present on this form.
    $globalSettings = [
        'link_resolver_enabled' => isset($_POST['link_resolver_enabled']) ? 1 : 0,
        'org_level_links_only' => isset($_POST['org_level_links_only']) ? 1 : 0,
        'link_resolver_daily_scan_enabled' => isset($_POST['link_resolver_daily_scan_enabled']) ? 1 : 0,
        'link_resolver_invoice_auto_attach_enabled' => isset($_POST['link_resolver_invoice_auto_attach_enabled']) ? 1 : 0,
        'project_specific_links_enabled' => isset($_POST['project_specific_links_enabled']) ? 1 : 0,
        'invoice_content_links_enabled' => isset($_POST['invoice_content_links_enabled']) ? 1 : 0,
    ];
    $missingLinksBehavior = (string)($_POST['invoice_missing_content_links_behavior'] ?? 'warn');
    if (!in_array($missingLinksBehavior, ['send', 'warn', 'block'], true)) {
        $missingLinksBehavior = 'warn';
    }
    $globalSettings['invoice_missing_content_links_behavior'] = $missingLinksBehavior;
    if (isset($_POST['default_link_expiration_days'])) {
        $globalSettings['default_link_expiration_days'] = max(1, (int)$_POST['default_link_expiration_days']);
    }
    if (isset($_POST['link_expiration_checker_enabled'])) {
        $globalSettings['link_expiration_checker'] = 1;
    }
    if (isset($_POST['link_expiration_email_enabled'])) {
        $globalSettings['link_expiration_email_enabled'] = 1;
    }
    if (isset($_POST['dropbox_app_key'])) {
        $globalSettings['dropbox_app_key'] = trim((string)$_POST['dropbox_app_key']);
    }
    if (isset($_POST['dropbox_app_secret']) && trim((string)$_POST['dropbox_app_secret']) !== '') {
        $globalSettings['dropbox_app_secret'] = trim((string)$_POST['dropbox_app_secret']);
    }
    
    foreach ($globalSettings as $key => $value) {
        $stmt = $pdo->prepare("
            INSERT INTO app_config (organization_id, config_key, config_value)
            VALUES (0, ?, ?)
            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
        ");
        $stmt->execute([$key, $value]);
    }
    
    // Process provider configurations
    $providers = ['dropbox', 'gdrive', 's3'];
    
    foreach ($providers as $provider) {
        $isEnabled = isset($_POST["provider_enabled_{$provider}"]) ? 1 : 0;
        
        // Build credentials array based on provider
        $credentials = [];
        
        if ($provider === 'dropbox') {
            // For Dropbox, preserve existing OAuth credentials if present
            $existingCredentials = [];
            try {
                $existingRow = pa_link_provider_best_row($pdo, $provider);
                if ($existingRow) {
                    $existingCredentials = pa_link_provider_credentials_from_row($existingRow);
                }
            } catch (Throwable $e) {}
            
            // Only update access token if provided, otherwise keep existing OAuth tokens
            $accessToken = $_POST["{$provider}_access_token"] ?? '';
            if (!empty($accessToken)) {
                // Legacy access token provided
                $credentials = [
                    'access_token' => $accessToken,
                    'root_path' => $_POST["{$provider}_root_path"] ?? '/'
                ];
            } elseif (!empty($existingCredentials['refresh_token'])) {
                // Keep existing OAuth credentials
                $credentials = $existingCredentials;
                // Update root path if changed
                $credentials['root_path'] = $_POST["{$provider}_root_path"] ?? ($existingCredentials['root_path'] ?? '/');
            } else {
                // No credentials at all
                $credentials = [
                    'root_path' => $_POST["{$provider}_root_path"] ?? '/'
                ];
            }
        } elseif ($provider === 'gdrive') {
            $credentials = [
                'service_account' => $_POST["{$provider}_credentials"] ?? '',
                'root_path' => $_POST["{$provider}_root_path"] ?? ''
            ];
        } elseif ($provider === 's3') {
            $credentials = [
                'access_key' => $_POST["{$provider}_access_key"] ?? '',
                'secret_key' => $_POST["{$provider}_secret_key"] ?? '',
                'bucket' => $_POST["{$provider}_bucket"] ?? '',
                'region' => $_POST["{$provider}_region"] ?? 'us-east-1',
                'root_path' => $_POST["{$provider}_root_path"] ?? ''
            ];
        }
        
        $expirationDays = (int)($globalSettings['default_link_expiration_days'] ?? $readConfig('default_link_expiration_days', 365));
        pa_link_provider_save($pdo, $provider, $isEnabled, $credentials, $expirationDays);
    }
    
    header('Location: /?page=settings&tab=links&saved=1');
    exit;
    
} catch (Throwable $e) {
    @error_log('[LinksHandler] Error: ' . $e->getMessage());
    header('Location: /?page=settings&tab=links&saved=0&error=' . urlencode($e->getMessage()));
    exit;
}
