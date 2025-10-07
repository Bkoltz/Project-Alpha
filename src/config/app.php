<?php
// src/config/app.php
// Preferred settings path: config volume mounted at /var/www/config
$settingsPrimary = '/var/www/config/settings.json';
$settingsProject = __DIR__ . '/../../config/settings.json';
$settingsPublic  = __DIR__ . '/../../public/assets/settings.json';
$settingsFallback = __DIR__ . '/settings.json';

$appConfig = [
    'brand_name' => 'Project Alpha',
    'logo_path'  => null,
    // User info for documents
    'from_name' => null,
    'from_address_line1' => null,
    'from_address_line2' => null,
    'from_city' => null,
    'from_state' => null,
    'from_postal' => null,
    'from_country' => null,
    'from_email' => null,
    'from_phone' => null,
    // Terms for contracts
    'terms' => null,
    'net_terms_days' => 30,
    'payment_methods' => ['card','cash','bank_transfer'],
    // App preferences
    'timezone' => 'UTC',
    // SMTP (may be present from settings)
    'smtp_host' => null,
    'smtp_port' => 587,
    'smtp_secure' => 'tls',
    'smtp_username' => null,
    'smtp_password_enc' => null,
];

$paths = [$settingsPrimary, $settingsProject, $settingsPublic, $settingsFallback];
foreach ($paths as $path) {
    if (is_readable($path)) {
        $json = @file_get_contents($path);
        if ($json !== false) {
            $data = json_decode($json, true);
            if (is_array($data)) {
                $appConfig = array_merge($appConfig, $data);
                break;
            }
        }
    }
}

// Apply timezone if configured (defaults to UTC)
$tz = (string)($appConfig['timezone'] ?? 'UTC');
if ($tz !== '') {
    try {
        date_default_timezone_set($tz);
    } catch (Throwable $e) {
        // ignore invalid timezone values
        date_default_timezone_set('UTC');
    }
}
