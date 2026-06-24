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
    // SMTP (loaded from app_config with fallback to settings.json)
    'smtp_host' => null,
    'smtp_port' => 587,
    'smtp_secure' => 'tls',
    'smtp_username' => null,
    'smtp_password_enc' => null,
    'smtp_from_email' => null,
    'smtp_from_name' => null,
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
    'quote_auto_create_contract' => 1,
    'quote_auto_create_invoice' => 1,
    // contract defaults
    //invoice defaults
];

// Load .env file if it exists (project root, config volume, or container root)
$envPaths = [__DIR__ . '/../../.env', '/var/www/config/.env', '/var/www/.env'];
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

// Load Stripe keys and SMTP config from app_config DB table (UI-entered keys, encrypted)
// This takes precedence over env-vars and settings.json so that keys entered
// via the Settings UI are used. Env-vars still work as a fallback.
try {
    if (!isset($pdo)) {
        require_once __DIR__ . '/db.php';
    }
    if (isset($pdo)) {
        $appConfigKeys = [
            // Stripe + SMTP (already loaded from DB)
            'stripe_publishable_key', 'stripe_secret_key_enc', 'stripe_webhook_secret_enc',
            'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_username', 'smtp_password_enc',
            'smtp_from_email', 'smtp_from_name',
            // General UI settings (now stored in app_config DB)
            'brand_name', 'logo_path', 'from_name', 'from_address_line1', 'from_address_line2',
            'from_city', 'from_state', 'from_postal', 'from_country', 'from_email', 'from_phone',
            'app_host', 'public_links_in_email', 'primary_state', 'timezone',
            'terms', 'long_term_terms', 'on_demand_terms',
            'net_terms_days', 'documents_valid_days', 'payment_methods',
            'quote_auto_create_contract', 'quote_auto_create_invoice', 'quotes_show_terms',
            'invoice_auto_send_due_7days', 'invoice_auto_send_overdue_weekly',
            'auto_terminate_contracts', 'link_expiration_checker',
            'contract_expiring_warning', 'contract_expiring_days', 'contract_expired_alert',
            'payment_failure_alert', 'payment_received_notification',
            'link_expiration_warning', 'link_expiration_warning_days',
            'invoice_show_terms', 'invoice_show_project_code', 'invoice_show_due_date',
            'quote_scope_enabled', 'contract_scope_enabled', 'contract_memo_enabled',
            'signature_agreement', 'review_link', 'suppress_assets_warning',
            'cron_enabled', 'cron_schedule', 'cron_custom',
            'contract_custom_sections_json',
        ];
        $placeholders = implode(',', array_fill(0, count($appConfigKeys), '?'));
        $cfgStmt = $pdo->prepare("SELECT config_key, config_value FROM app_config WHERE config_key IN ($placeholders)");
        $cfgStmt->execute($appConfigKeys);
        if ($cfgStmt) {
            while ($row = $cfgStmt->fetch(PDO::FETCH_ASSOC)) {
                $key = $row['config_key'];
                $val = $row['config_value'];
                if ($val !== '' && $val !== null) {
                    $appConfig[$key] = $val;
                }
            }
        }

        // Deserialize JSON-encoded settings
        if (isset($appConfig['contract_custom_sections_json'])) {
            $decoded = json_decode($appConfig['contract_custom_sections_json'], true);
            if (is_array($decoded)) { $appConfig['contract_custom_sections'] = $decoded; }
            unset($appConfig['contract_custom_sections_json']);
        }
        if (isset($appConfig['payment_methods']) && is_string($appConfig['payment_methods'])) {
            $decoded = json_decode($appConfig['payment_methods'], true);
            if (is_array($decoded)) { $appConfig['payment_methods'] = $decoded; }
        }
    }
} catch (Throwable $e) {
    // DB not available yet or table doesn't exist — fall through to env vars
}
