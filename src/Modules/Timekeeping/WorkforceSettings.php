<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping;

use PDO;
use Throwable;

final class WorkforceSettings
{
    public static function load(PDO $pdo): array
    {
        $settings = [
            'business_name' => 'Project Alpha',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'default_hourly_rate' => null,
            'default_billing_rate' => null,
            'require_project' => 0,
            'require_description' => 0,
        ];

        try {
            $legacy = $pdo->query('SELECT * FROM business_settings WHERE singleton=1')->fetch(PDO::FETCH_ASSOC);
            if (is_array($legacy)) {
                $settings = array_merge($settings, $legacy);
            }
        } catch (Throwable) {
            // Migration 0040 seeds app_config from this table. Defaults keep
            // first-run rendering safe if the legacy row is unavailable.
        }

        $keys = [
            'brand_name',
            'from_company',
            'timezone',
            'workforce_currency',
            'workforce_default_hourly_rate',
            'workforce_default_billing_rate',
            'workforce_require_project',
            'workforce_require_description',
        ];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare(
            "SELECT config_key,config_value FROM app_config
             WHERE organization_id=0 AND config_key IN ($placeholders)"
        );
        $stmt->execute($keys);
        $config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $businessName = trim((string)($config['from_company'] ?? ''));
        if ($businessName === '') {
            $businessName = trim((string)($config['brand_name'] ?? ''));
        }
        if ($businessName !== '') {
            $settings['business_name'] = $businessName;
        }
        if (!empty($config['timezone'])) {
            $settings['timezone'] = (string)$config['timezone'];
        }
        if (preg_match('/^[A-Z]{3}$/', strtoupper((string)($config['workforce_currency'] ?? '')))) {
            $settings['currency'] = strtoupper((string)$config['workforce_currency']);
        }
        foreach (['hourly', 'billing'] as $rateType) {
            $configKey = 'workforce_default_' . $rateType . '_rate';
            $settingsKey = 'default_' . $rateType . '_rate';
            if (array_key_exists($configKey, $config)) {
                $value = trim((string)$config[$configKey]);
                $settings[$settingsKey] = $value === '' ? null : $value;
            }
        }
        $settings['require_project'] = self::boolValue(
            $config['workforce_require_project'] ?? $settings['require_project']
        );
        $settings['require_description'] = self::boolValue(
            $config['workforce_require_description'] ?? $settings['require_description']
        );

        return $settings;
    }

    private static function boolValue(mixed $value): int
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
}
