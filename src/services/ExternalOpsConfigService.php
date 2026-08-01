<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

final class ExternalOpsConfigService
{
    private const CREDENTIALS_KEY = 'external_ops_credentials_enc';
    private const CONFIG_KEYS = [
        'external_ops_enabled',
        'external_ops_label',
        'external_ops_application_key',
        'external_ops_webhook_url',
        'external_ops_timeout_seconds',
        'external_ops_max_attempts',
        self::CREDENTIALS_KEY,
    ];

    /** @return array<string,mixed> */
    public function load(PDO $pdo): array
    {
        $values = [];
        $placeholders = implode(',', array_fill(0, count(self::CONFIG_KEYS), '?'));
        $statement = $pdo->prepare(
            "SELECT config_key,config_value FROM app_config WHERE organization_id=0 AND config_key IN ($placeholders)"
        );
        $statement->execute(self::CONFIG_KEYS);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[(string)$row['config_key']] = (string)$row['config_value'];
        }

        $credentials = [];
        $encrypted = trim((string)($values[self::CREDENTIALS_KEY] ?? ''));
        $credentialsUnreadable = false;
        if ($encrypted !== '') {
            require_once __DIR__ . '/../utils/crypto.php';
            $plaintext = crypto_decrypt($encrypted);
            $decoded = is_string($plaintext) ? json_decode($plaintext, true) : null;
            if (is_array($decoded)) {
                $credentials = $decoded;
            } else {
                $credentialsUnreadable = true;
            }
        }

        $applicationKey = strtolower(trim((string)($values['external_ops_application_key'] ?? '')));
        $configuredEnabled = filter_var(
            $values['external_ops_enabled'] ?? 'false',
            FILTER_VALIDATE_BOOLEAN
        );
        $deliveryIssues = self::deliveryIssues([
            'application_key' => $applicationKey,
            'webhook_url' => trim((string)($values['external_ops_webhook_url'] ?? '')),
            'access_client_id' => trim((string)($credentials['access_client_id'] ?? '')),
            'access_client_secret' => trim((string)($credentials['access_client_secret'] ?? '')),
            'hmac_secret' => trim((string)($credentials['hmac_secret'] ?? '')),
            'credentials_unreadable' => $credentialsUnreadable,
        ]);
        $configurationComplete = $deliveryIssues === [];
        $deliveryReady = $configuredEnabled && $configurationComplete;

        return [
            // Preserve administrator intent separately from the effective runtime state.
            'configured_enabled' => $configuredEnabled,
            // Keep the established enabled contract for event capture and entitlement access.
            'enabled' => $configuredEnabled,
            'configuration_complete' => $configurationComplete,
            'delivery_ready' => $deliveryReady,
            'delivery_issues' => $deliveryIssues,
            'application_key' => $applicationKey,
            'label' => trim((string)($values['external_ops_label'] ?? 'External operations')) ?: 'External operations',
            'webhook_url' => trim((string)($values['external_ops_webhook_url'] ?? '')),
            'access_client_id' => trim((string)($credentials['access_client_id'] ?? '')),
            'access_client_secret' => trim((string)($credentials['access_client_secret'] ?? '')),
            'hmac_secret' => trim((string)($credentials['hmac_secret'] ?? '')),
            'timeout_seconds' => max(2, min(60, (int)($values['external_ops_timeout_seconds'] ?? 15))),
            'max_attempts' => max(1, min(100, (int)($values['external_ops_max_attempts'] ?? 12))),
            'credentials_unreadable' => $credentialsUnreadable,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(PDO $pdo, array $input): array
    {
        $current = $this->load($pdo);
        $enabled = !empty($input['enabled']);
        $label = trim((string)($input['label'] ?? '')) ?: 'External operations';
        $applicationKeyInput = trim((string)($input['application_key'] ?? $current['application_key']));
        $applicationKey = $applicationKeyInput === ''
            ? ''
            : ExternalOpsIntegrationService::normalizeApplicationKey($applicationKeyInput);
        $webhookUrl = trim((string)($input['webhook_url'] ?? ''));
        $timeout = max(2, min(60, (int)($input['timeout_seconds'] ?? 15)));
        $maxAttempts = max(1, min(100, (int)($input['max_attempts'] ?? 12)));

        if (mb_strlen($label) > 100) {
            throw new DomainException('The integration label cannot exceed 100 characters.');
        }
        if (!empty($current['configured_enabled'])
            && (string)$current['application_key'] !== ''
            && $applicationKey !== (string)$current['application_key']) {
            throw new DomainException('Disable the integration before changing its application key.');
        }
        if (mb_strlen($webhookUrl) > 1000) {
            throw new DomainException('The webhook URL cannot exceed 1000 characters.');
        }
        if ($webhookUrl !== '') {
            $parts = parse_url($webhookUrl);
            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            $host = strtolower((string)($parts['host'] ?? ''));
            $localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
            if (!filter_var($webhookUrl, FILTER_VALIDATE_URL) || ($scheme !== 'https' && !$localHost)) {
                throw new DomainException('The webhook URL must be a valid HTTPS URL (HTTP is allowed only for localhost).');
            }
        }

        $credentials = [
            'access_client_id' => trim((string)($input['access_client_id'] ?? '')) ?: (string)$current['access_client_id'],
            'access_client_secret' => trim((string)($input['access_client_secret'] ?? '')) ?: (string)$current['access_client_secret'],
            'hmac_secret' => trim((string)($input['hmac_secret'] ?? '')) ?: (string)$current['hmac_secret'],
        ];
        if (mb_strlen($credentials['access_client_id']) > 500 || mb_strlen($credentials['access_client_secret']) > 1000) {
            throw new DomainException('The Cloudflare Access credential is too long.');
        }
        if (strlen($credentials['hmac_secret']) > 1000) {
            throw new DomainException('The webhook HMAC secret is too long.');
        }
        if ($credentials['hmac_secret'] !== '' && strlen($credentials['hmac_secret']) < 32) {
            throw new DomainException('The webhook HMAC secret must be at least 32 characters.');
        }
        if ($enabled && ($applicationKey === '' || $webhookUrl === '' || in_array('', $credentials, true))) {
            throw new DomainException('Application key, webhook URL, Cloudflare Access credentials, and the HMAC secret are required before enabling this integration.');
        }

        require_once __DIR__ . '/../utils/crypto.php';
        $encryptedCredentials = crypto_encrypt(json_encode($credentials, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($encryptedCredentials === null) {
            throw new RuntimeException('Project Alpha could not encrypt the integration credentials. Verify the persisted application encryption key.');
        }

        $values = [
            'external_ops_enabled' => $enabled ? '1' : '0',
            'external_ops_label' => $label,
            'external_ops_application_key' => $applicationKey,
            'external_ops_webhook_url' => $webhookUrl,
            'external_ops_timeout_seconds' => (string)$timeout,
            'external_ops_max_attempts' => (string)$maxAttempts,
            self::CREDENTIALS_KEY => $encryptedCredentials,
        ];

        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            $saveSql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? 'INSERT INTO app_config (organization_id,config_key,config_value) VALUES (0,?,?)
                   ON CONFLICT(organization_id,config_key) DO UPDATE SET config_value=excluded.config_value'
                : 'INSERT INTO app_config (organization_id,config_key,config_value) VALUES (0,?,?)
                   ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)';
            $save = $pdo->prepare($saveSql);
            foreach ($values as $key => $value) {
                $save->execute([$key, $value]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        return $this->load($pdo);
    }

    /**
     * Return only non-secret setting categories that block outbound delivery.
     *
     * @param array<string,mixed> $config
     * @return list<string>
     */
    public static function deliveryIssues(array $config): array
    {
        $issues = [];
        $applicationKey = trim((string)($config['application_key'] ?? ''));
        if ($applicationKey === '') {
            $issues[] = 'application key';
        } elseif (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $applicationKey)) {
            $issues[] = 'valid application key';
        }
        $webhookUrl = trim((string)($config['webhook_url'] ?? ''));
        if ($webhookUrl === '') {
            $issues[] = 'signed event URL';
        } else {
            $parts = parse_url($webhookUrl);
            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            $host = strtolower((string)($parts['host'] ?? ''));
            $localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
            if (!filter_var($webhookUrl, FILTER_VALIDATE_URL) || ($scheme !== 'https' && !$localHost)) {
                $issues[] = 'valid signed event URL';
            }
        }
        if (!empty($config['credentials_unreadable'])) {
            $issues[] = 'stored delivery credentials cannot be decrypted';
            return $issues;
        }
        foreach ([
            'access_client_id' => 'access service-token ID',
            'access_client_secret' => 'access service-token secret',
            'hmac_secret' => 'HMAC secret',
        ] as $field => $label) {
            if (trim((string)($config[$field] ?? '')) === '') {
                $issues[] = $label;
            }
        }
        if (!in_array('access service-token ID', $issues, true)
            && mb_strlen((string)$config['access_client_id']) > 500) {
            $issues[] = 'valid access service-token ID';
        }
        if (!in_array('access service-token secret', $issues, true)
            && mb_strlen((string)$config['access_client_secret']) > 1000) {
            $issues[] = 'valid access service-token secret';
        }
        $hmacLength = strlen((string)($config['hmac_secret'] ?? ''));
        if (!in_array('HMAC secret', $issues, true) && ($hmacLength < 32 || $hmacLength > 1000)) {
            $issues[] = 'valid HMAC secret';
        }
        return $issues;
    }
}
