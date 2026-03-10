<?php
namespace App\config;

class AppConfiguration
{
    public static array $ConfigSettings = [
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
        'payment_methods' => ['Card', 'Cash', 'Bank Transfer'],
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
        // qoute defaults
        // contract defaults
        //invoice defaults
    ];

    private static $settingsPrimary = BASE_PATH . '/config/settings.json';
    private static $settingsProject = BASE_PATH . '/config/settings.json';
    private static $settingsPublic  = BASE_PATH . '/public/assets/settings.json';

    public static function updateAppConfig()
    {
        $paths = [AppConfiguration::$settingsPrimary, AppConfiguration::$settingsProject, AppConfiguration::$settingsPublic];
        foreach ($paths as $path) {
            if (is_readable($path)) {
                $json = @file_get_contents($path);
                if ($json !== false) {
                    $data = json_decode($json, true);
                    if (is_array($data)) {
                        AppConfiguration::$ConfigSettings = array_merge(AppConfiguration::$ConfigSettings, $data);
                        break;
                    }
                }
            }
        }

        $tz = (string)(AppConfiguration::$ConfigSettings['timezone'] ?? 'UTC');
        if ($tz !== '') {
            try {
                date_default_timezone_set($tz);
            } catch (\Throwable $e) {
                // ignore invalid timezone values
                date_default_timezone_set('UTC');
            }
        }
    }
}
