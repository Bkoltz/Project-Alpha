<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping;

use App\Services\TimeApprovalPolicy;
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
            'default_capture_mode' => 'duration',
            'default_billing_treatment' => 'undecided',
            'require_project' => 0,
            'require_work_type' => 0,
            'require_description' => 0,
            'allow_non_admin_time_management' => 0,
            'allow_non_admin_time_approval' => 0,
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
            'workforce_default_capture_mode',
            'workforce_default_billing_treatment',
            'workforce_require_project',
            'workforce_require_work_type',
            'workforce_require_description',
            'workforce_allow_non_admin_time_management',
            'workforce_allow_non_admin_time_approval',
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
        if (in_array(($config['workforce_default_capture_mode'] ?? ''), ['duration', 'timer', 'exact'], true)) {
            $settings['default_capture_mode'] = (string)$config['workforce_default_capture_mode'];
        }
        $billingTreatment = (string)($config['workforce_default_billing_treatment'] ?? '');
        $billingTreatment = [
            'internal' => 'nonbillable',
            'included' => 'included_fixed',
            'hourly' => 'ready',
        ][$billingTreatment] ?? $billingTreatment;
        if (in_array($billingTreatment, ['undecided', 'nonbillable', 'included_fixed', 'ready'], true)) {
            $settings['default_billing_treatment'] = $billingTreatment;
        }
        $settings['require_project'] = self::boolValue(
            $config['workforce_require_project'] ?? $settings['require_project']
        );
        $settings['require_work_type'] = self::boolValue(
            $config['workforce_require_work_type'] ?? $settings['require_work_type']
        );
        $settings['require_description'] = self::boolValue(
            $config['workforce_require_description'] ?? $settings['require_description']
        );
        $settings['allow_non_admin_time_management'] = self::boolValue(
            $config['workforce_allow_non_admin_time_management'] ?? 0
        );
        $settings['allow_non_admin_time_approval'] = self::boolValue(
            $config['workforce_allow_non_admin_time_approval'] ?? 0
        );

        return $settings;
    }

    public static function canManageAllTime(PDO $pdo, int $userId): bool
    {
        $role = function_exists('acl_user_role') ? \acl_user_role($pdo, $userId) : (string)($_SESSION['user']['role'] ?? '');
        if (in_array($role, ['admin', 'owner'], true)) {
            return true;
        }
        return self::load($pdo)['allow_non_admin_time_management'] === 1
            && function_exists('user_can')
            && \user_can($pdo, $userId, 'timekeeping.manage', 0);
    }

    public static function canReviewTime(PDO $pdo, int $userId): bool
    {
        return (new TimeApprovalPolicy($pdo))->canAccessQueue($userId);
    }

    private static function boolValue(mixed $value): int
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
}
