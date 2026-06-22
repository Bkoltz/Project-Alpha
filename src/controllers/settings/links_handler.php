<?php
// src/controllers/settings/links_handler.php

require_once __DIR__ . '/../../config/db.php';

try {
    // Ensure app_config table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        config_key VARCHAR(100) NOT NULL UNIQUE,
        config_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_config_key (config_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
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
    
    // Save global settings to app_config
    $globalSettings = [
        'link_resolver_enabled' => isset($_POST['link_resolver_enabled']) ? 1 : 0,
        'default_link_expiration_days' => (int)($_POST['default_link_expiration_days'] ?? 365),
        'org_level_links_only' => isset($_POST['org_level_links_only']) ? 1 : 0,
        'link_expiration_checker' => isset($_POST['link_expiration_checker_enabled']) ? 1 : 0,
        'link_expiration_email_enabled' => isset($_POST['link_expiration_email_enabled']) ? 1 : 0
    ];
    
    foreach ($globalSettings as $key => $value) {
        $stmt = $pdo->prepare("
            INSERT INTO app_config (config_key, config_value) 
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE config_value = ?
        ");
        $stmt->execute([$key, $value, $value]);
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
                $stmt = $pdo->prepare("SELECT credentials FROM link_resolver_config WHERE provider = ?");
                $stmt->execute([$provider]);
                $existing = $stmt->fetchColumn();
                if ($existing) {
                    $existingCredentials = json_decode($existing, true) ?: [];
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
        
        // Save or update provider config
        $stmt = $pdo->prepare("
            INSERT INTO link_resolver_config (provider, is_enabled, credentials, default_expiration_days)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                is_enabled = ?,
                credentials = ?,
                default_expiration_days = ?
        ");
        
        $credentialsJson = json_encode($credentials);
        $expirationDays = $globalSettings['default_link_expiration_days'];
        
        $stmt->execute([
            $provider,
            $isEnabled,
            $credentialsJson,
            $expirationDays,
            $isEnabled,
            $credentialsJson,
            $expirationDays
        ]);
    }
    
    header('Location: /?page=settings&tab=links&saved=1');
    exit;
    
} catch (Throwable $e) {
    @error_log('[LinksHandler] Error: ' . $e->getMessage());
    header('Location: /?page=settings&tab=links&saved=0&error=' . urlencode($e->getMessage()));
    exit;
}
