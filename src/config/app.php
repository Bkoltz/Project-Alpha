<?php
// src/config/app.php
// Preferred settings path: config volume mounted at /var/www/config
// Secrets (Stripe keys, SMTP password, encryption key) are loaded from environment variables
// or .env file first; non-sensitive settings come from settings.json.

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
    'payment_methods' => ['Card','Cash','Bank Transfer'],
    'quotes_show_terms' => 0,
    // App preferences
    'timezone' => 'UTC',
    'primary_state' => null,
    'documents_valid_days' => 14,
    // Automatic invoice email settings
    'invoice_auto_send_due_7days' => 0,
    'invoice_auto_send_overdue_weekly' => 0,
    // SMTP (may be present from settings)
    'smtp_host' => null,
    'smtp_port' => 587,
    'smtp_secure' => 'tls',
    'smtp_username' => null,
    'smtp_password_enc' => null,
    // Stripe (loaded from environment/.env, not stored in settings.json)
    'stripe_publishable_key' => null,
    'stripe_secret_key_enc' => null,
    'stripe_webhook_secret_enc' => null,
    'stripe_surcharge_type' => 'split',
    'stripe_surcharge_percent' => 2.9,
    'stripe_surcharge_fixed' => 0.3,
    'stripe_surcharge_split_percent' => 50,
    'stripe_surcharge_message' => 'Using a credit card is a privilege for both parties, so it is fair that we split the fee',
    // qoute defaults
    // contract defaults
    //invoice defaults
];

// Load .env file if it exists (project root or config directory)
$envPaths = [__DIR__ . '/../../.env', '/var/www/config/.env'];
foreach ($envPaths as $envPath) {
    if (is_readable($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue; // skip comments
            if (strpos($line, '=') === false) continue;
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Remove quotes if present
            if (strlen($value) >= 2 && (($value[0] === '"' && $value[strlen($value)-1] === '"') || ($value[0] === "'" && $value[strlen($value)-1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            if (!isset($_ENV[$key]) && !isset($_SERVER[$key])) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// Load secrets from environment variables (they override anything in settings.json)
$secretKeys = [
    'stripe_publishable_key',
    'stripe_secret_key_enc',
    'stripe_webhook_secret_enc',
    'smtp_password_enc',
    'encryption_key',
];
foreach ($secretKeys as $key) {
    $envValue = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?? false;
    if ($envValue !== false && $envValue !== '') {
        $appConfig[$key] = $envValue;
    }
}

$paths = [$settingsPrimary, $settingsProject, $settingsPublic, $settingsFallback];
foreach ($paths as $path) {
    if (is_readable($path)) {
        $json = @file_get_contents($path);
        if ($json !== false) {
            $data = json_decode($json, true);
            if (is_array($data)) {
                // Don't let settings.json override secrets that came from environment
                foreach ($secretKeys as $secretKey) {
                    if (isset($data[$secretKey]) && empty($appConfig[$secretKey])) {
                        $appConfig[$secretKey] = $data[$secretKey];
                    }
                    unset($data[$secretKey]); // remove from merge to prevent overwriting env values
                }
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
