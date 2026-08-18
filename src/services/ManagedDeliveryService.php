<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Throwable;

final class ManagedDeliveryService
{
    public const ENABLED_KEY = 'managed_delivery_enabled';
    public const URL_KEY = 'managed_delivery_intent_url';
    public const PROFILE_KEY = 'managed_delivery_profile_id';
    public const GUEST_KEY = 'managed_delivery_guest_links_enabled';

    private const SCOPE_TABLES = [
        'organization' => 'organizations',
        'department' => 'organization_departments',
        'client' => 'clients',
        'project' => 'projects',
    ];

    /** @return array<string,mixed> */
    public function config(PDO $pdo): array
    {
        $values = [];
        $statement = $pdo->prepare('SELECT config_key,config_value FROM app_config WHERE organization_id=0 AND config_key IN (?,?,?,?)');
        $statement->execute([self::ENABLED_KEY, self::URL_KEY, self::PROFILE_KEY, self::GUEST_KEY]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[(string)$row['config_key']] = (string)$row['config_value'];
        }
        $enabled = filter_var($values[self::ENABLED_KEY] ?? '0', FILTER_VALIDATE_BOOLEAN);
        $url = trim((string)($values[self::URL_KEY] ?? ''));
        $profileId = max(0, (int)($values[self::PROFILE_KEY] ?? 0));
        $profile = null;
        if ($profileId > 0) {
            $profileStatement = $pdo->prepare('SELECT * FROM portal_integration_profiles WHERE id=? LIMIT 1');
            $profileStatement->execute([$profileId]);
            $profile = $profileStatement->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $issues = [];
        if ($url === '') {
            $issues[] = 'delivery-intent URL';
        } else {
            try {
                self::validateIntentUrl($url);
            } catch (DomainException) {
                $issues[] = 'valid delivery-intent URL';
            }
        }
        if (!$profile || empty($profile['enabled']) || empty($profile['delivery_enabled'])) $issues[] = 'enabled integration delivery profile';
        if ($profile && trim((string)($profile['application_key'] ?? '')) === '') $issues[] = 'integration application key';
        if ($profile && trim((string)($profile['delivery_key_id'] ?? '')) === '') $issues[] = 'integration signing key ID';
        if ($profile) {
            try {
                $credentials = (new PortalProjectionDeliveryConfigService())->credentials($profile);
                if (strlen($credentials['currentSecret']) < 32) $issues[] = 'integration signing secret';
            } catch (Throwable) {
                $issues[] = 'readable integration delivery credentials';
            }
        }

        return [
            'enabled' => $enabled,
            'intent_url' => $url,
            'profile_id' => $profileId,
            'profile_label' => (string)($profile['display_label'] ?? ''),
            'application_key' => (string)($profile['application_key'] ?? ''),
            'guest_links_enabled' => filter_var($values[self::GUEST_KEY] ?? '0', FILTER_VALIDATE_BOOLEAN),
            'default_access_mode' => 'portal',
            'configured' => $issues === [],
            'ready' => $enabled && $issues === [],
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function saveConfig(PDO $pdo, array $input): array
    {
        $enabled = !empty($input['enabled']);
        $guest = !empty($input['guest_links_enabled']);
        $profileId = max(0, (int)($input['profile_id'] ?? 0));
        $url = trim((string)($input['intent_url'] ?? ''));
        if ($url !== '') {
            self::validateIntentUrl($url);
        }
        if ($enabled && $url === '') {
            throw new DomainException('The managed delivery-intent URL is required before enabling the provider.');
        }
        if ($enabled && $profileId < 1) throw new DomainException('Select an integration delivery profile before enabling managed delivery.');
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INSERT INTO app_config(organization_id,config_key,config_value) VALUES(0,?,?) ON CONFLICT(organization_id,config_key) DO UPDATE SET config_value=excluded.config_value'
            : 'INSERT INTO app_config(organization_id,config_key,config_value) VALUES(0,?,?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)';
        $save = $pdo->prepare($sql);
        $save->execute([self::ENABLED_KEY, $enabled ? '1' : '0']);
        $save->execute([self::URL_KEY, $url]);
        $save->execute([self::PROFILE_KEY, (string)$profileId]);
        $save->execute([self::GUEST_KEY, $guest ? '1' : '0']);
        return $this->config($pdo);
    }

    /** @return array{profileId:int,url:string,applicationKey:string,keyId:string,secret:string,authHeaders:array<string,string>,contractHash:string,timeout:int,maxAttempts:int} */
    public function deliveryContract(PDO $pdo, bool $requireProviderEnabled = true): array
    {
        $config = $this->config($pdo);
        if (($requireProviderEnabled && empty($config['ready'])) || (!$requireProviderEnabled && empty($config['configured']))) {
            throw new DomainException($requireProviderEnabled
                ? 'Managed delivery is disabled or incomplete.'
                : 'Managed delivery connection settings are incomplete.');
        }
        $statement = $pdo->prepare('SELECT * FROM portal_integration_profiles WHERE id=? AND enabled=1 AND delivery_enabled=1 LIMIT 1');
        $statement->execute([(int)$config['profile_id']]);
        $profile = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$profile) throw new DomainException('Managed delivery integration profile is unavailable.');
        $credentials = (new PortalProjectionDeliveryConfigService())->credentials($profile);
        $authHeaders = $credentials['authHeaders'];
        return [
            'profileId' => (int)$profile['id'],
            'url' => (string)$config['intent_url'],
            'applicationKey' => (string)$profile['application_key'],
            'keyId' => (string)$profile['delivery_key_id'],
            'secret' => $credentials['currentSecret'],
            'authHeaders' => $authHeaders,
            'contractHash' => self::contractHash((int)$profile['id'], (string)$profile['application_key'], (string)$config['intent_url'], (string)$profile['delivery_key_id'], $authHeaders, $credentials['currentSecret']),
            'timeout' => max(2, min(30, (int)($profile['delivery_timeout_seconds'] ?? 15))),
            'maxAttempts' => max(1, min(50, (int)($profile['delivery_max_attempts'] ?? 12))),
        ];
    }

    /** @param array<string,mixed> $claim @return array{profileId:int,url:string,applicationKey:string,keyId:string,secret:string,authHeaders:array<string,string>,contractHash:string,timeout:int,maxAttempts:int} */
    public function deliveryContractForClaim(PDO $pdo, array $claim): array
    {
        $profileId = (int)($claim['integration_profile_id'] ?? 0);
        $url = (string)($claim['destination_url'] ?? '');
        $applicationKey = (string)($claim['pinned_application_key'] ?? '');
        $keyId = (string)($claim['signing_key_id'] ?? '');
        self::validateIntentUrl($url);
        if ($profileId < 1 || $applicationKey === '' || $keyId === '') throw new DomainException('The pinned managed delivery contract is incomplete.');
        $statement = $pdo->prepare('SELECT * FROM portal_integration_profiles WHERE id=? AND enabled=1 AND delivery_enabled=1 LIMIT 1');
        $statement->execute([$profileId]);
        $profile = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$profile || !hash_equals($applicationKey, (string)$profile['application_key'])) throw new DomainException('The pinned managed delivery profile is unavailable.');
        $credentials = (new PortalProjectionDeliveryConfigService())->credentials($profile);
        $secret = '';
        if (hash_equals((string)($profile['delivery_key_id'] ?? ''), $keyId)) {
            $secret = $credentials['currentSecret'];
        } elseif (hash_equals((string)($profile['delivery_previous_key_id'] ?? ''), $keyId)
            && !empty($profile['delivery_previous_valid_until'])
            && (string)$profile['delivery_previous_valid_until'] > (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')) {
            $secret = $credentials['previousSecret'];
        }
        if (strlen($secret) < 32) throw new DomainException('The pinned managed delivery signing key is unavailable.');
        $contractHash = self::contractHash($profileId, $applicationKey, $url, $keyId, $credentials['authHeaders'], $secret);
        if (!hash_equals((string)($claim['signing_contract_hash'] ?? ''), $contractHash)) throw new DomainException('The pinned managed delivery contract epoch is unavailable.');
        return [
            'profileId' => $profileId,
            'url' => $url,
            'applicationKey' => $applicationKey,
            'keyId' => $keyId,
            'secret' => $secret,
            'authHeaders' => $credentials['authHeaders'],
            'contractHash' => $contractHash,
            'timeout' => max(2, min(30, (int)($claim['delivery_timeout_seconds'] ?? 15))),
            'maxAttempts' => max(1, min(50, (int)($claim['delivery_max_attempts'] ?? 12))),
        ];
    }

    /** @param array<string,mixed> $original @return array{profileId:int,url:string,applicationKey:string,keyId:string,secret:string,authHeaders:array<string,string>,contractHash:string,timeout:int,maxAttempts:int} */
    public function revocationContract(PDO $pdo, array $original): array
    {
        $profileId = (int)($original['integration_profile_id'] ?? 0);
        $url = (string)($original['destination_url'] ?? '');
        $applicationKey = (string)($original['pinned_application_key'] ?? '');
        self::validateIntentUrl($url);
        if ($profileId < 1 || $applicationKey === '') throw new DomainException('The pinned managed delivery receiver is incomplete.');
        $statement = $pdo->prepare('SELECT * FROM portal_integration_profiles WHERE id=? AND enabled=1 AND delivery_enabled=1 LIMIT 1');
        $statement->execute([$profileId]);
        $profile = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$profile || !hash_equals($applicationKey, (string)$profile['application_key'])) throw new DomainException('The pinned managed delivery receiver is unavailable.');
        $credentials = (new PortalProjectionDeliveryConfigService())->credentials($profile);
        $keyId = (string)($profile['delivery_key_id'] ?? '');
        $secret = $credentials['currentSecret'];
        if ($keyId === '' || strlen($secret) < 32) throw new DomainException('The pinned managed delivery receiver has no current signing key.');
        $authHeaders = $credentials['authHeaders'];
        return [
            'profileId' => $profileId,
            'url' => $url,
            'applicationKey' => $applicationKey,
            'keyId' => $keyId,
            'secret' => $secret,
            'authHeaders' => $authHeaders,
            'contractHash' => self::contractHash($profileId, $applicationKey, $url, $keyId, $authHeaders, $secret),
            'timeout' => max(2, min(30, (int)($original['delivery_timeout_seconds'] ?? 15))),
            'maxAttempts' => max(1, min(50, (int)($original['delivery_max_attempts'] ?? 12))),
        ];
    }

    public static function validateIntentUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if (strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL) || ($scheme !== 'https' && !$local)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (string)($parts['path'] ?? '') !== '/api/internal/project-alpha/delivery-intents') {
            throw new DomainException('The managed delivery URL must be the exact HTTPS /api/internal/project-alpha/delivery-intents route.');
        }
    }

    /** @return array{deliveryId:string,replayed:bool,status:string} */
    public function queue(PDO $pdo, array $input, int $actorUserId): array
    {
        $config = $this->config($pdo);
        if (empty($config['ready'])) {
            throw new DomainException('Managed delivery is disabled or incomplete.');
        }
        $contract = $this->deliveryContract($pdo);
        $deliveryId = strtolower(trim((string)($input['delivery_id'] ?? '')));
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $deliveryId) !== 1) {
            throw new DomainException('The delivery request identifier is invalid. Refresh and try again.');
        }
        $scopeType = (string)($input['scope_type'] ?? '');
        $scopePublicId = trim((string)($input['scope_public_id'] ?? ''));
        $audienceType = (string)($input['audience_type'] ?? 'principal');
        $audiencePublicId = trim((string)($input['audience_public_id'] ?? ''));
        $accessMode = (string)($input['access_mode'] ?? 'portal');
        if (!isset(self::SCOPE_TABLES[$scopeType]) || $audienceType !== 'principal'
            || !self::opaqueId($scopePublicId) || !self::opaqueId($audiencePublicId)
            || !in_array($accessMode, ['portal', 'guest'], true)) {
            throw new DomainException('The managed delivery selection is invalid.');
        }
        if ($accessMode === 'guest' && empty($config['guest_links_enabled'])) {
            throw new DomainException('Guest/public delivery is disabled. Portal delivery was not changed or retried.');
        }
        if (!$this->scopeExists($pdo, $scopeType, $scopePublicId) || !$this->audienceExists($pdo, $audienceType, $audiencePublicId)) {
            throw new DomainException('The selected delivery scope or recipient is not available.');
        }
        $label = trim((string)($input['label'] ?? ''));
        if (mb_strlen($label) > 160) {
            throw new DomainException('The delivery label cannot exceed 160 characters.');
        }
        $expiresAt = $this->expiry((string)($input['expires_at'] ?? ''), $accessMode);
        $existing = $pdo->prepare('SELECT request_fingerprint,payload_json,delivered_at,dead_lettered_at,revoked_at FROM managed_delivery_intent_outbox WHERE delivery_id=?');
        $existing->execute([$deliveryId]);
        $prior = $existing->fetch(PDO::FETCH_ASSOC);
        if ($prior) {
            if (!$this->matchesExisting($prior, $scopeType, $scopePublicId, $audienceType, $audiencePublicId, $accessMode, $expiresAt, $label)) {
                throw new DomainException('The delivery request identifier was already used for a different request.');
            }
            return ['deliveryId' => $deliveryId, 'replayed' => true, 'status' => !empty($prior['revoked_at']) ? 'revoked' : (!empty($prior['delivered_at']) ? 'accepted' : (!empty($prior['dead_lettered_at']) ? 'failed' : 'queued'))];
        }
        $occurredAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        $payload = [
            'schemaVersion' => 1,
            'applicationKey' => $contract['applicationKey'],
            'deliveryId' => $deliveryId,
            'occurredAt' => $occurredAt,
            'scope' => ['type' => $scopeType, 'publicId' => $scopePublicId],
            'audience' => ['type' => $audienceType, 'publicId' => $audiencePublicId],
            'accessMode' => $accessMode,
            'expiresAt' => $expiresAt,
            'label' => $label !== '' ? $label : null,
            'notify' => true,
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($body) > 32768) {
            throw new DomainException('The delivery request is too large.');
        }
        $fingerprint = hash('sha256', $body);
        $insert = $pdo->prepare('INSERT INTO managed_delivery_intent_outbox(delivery_id,integration_profile_id,destination_url,pinned_application_key,signing_key_id,signing_contract_hash,delivery_timeout_seconds,delivery_max_attempts,actor_user_id,scope_type,scope_public_id,audience_type,audience_public_id,access_mode,request_fingerprint,payload_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        try {
            $insert->execute([$deliveryId, $contract['profileId'], $contract['url'], $contract['applicationKey'], $contract['keyId'], $contract['contractHash'], $contract['timeout'], $contract['maxAttempts'], $actorUserId, $scopeType, $scopePublicId, $audienceType, $audiencePublicId, $accessMode, $fingerprint, $body]);
        } catch (Throwable $error) {
            $existing->execute([$deliveryId]);
            $prior = $existing->fetch(PDO::FETCH_ASSOC);
            if ($prior && $this->matchesExisting($prior, $scopeType, $scopePublicId, $audienceType, $audiencePublicId, $accessMode, $expiresAt, $label)) {
                return ['deliveryId' => $deliveryId, 'replayed' => true, 'status' => !empty($prior['revoked_at']) ? 'revoked' : (!empty($prior['delivered_at']) ? 'accepted' : 'queued')];
            }
            throw $error;
        }
        return ['deliveryId' => $deliveryId, 'replayed' => false, 'status' => 'queued'];
    }

    /** @return array{deliveryId:string,replayed:bool,status:string} */
    public function queueRevocation(PDO $pdo, string $targetDeliveryId, string $deliveryId, int $actorUserId): array
    {
        foreach ([$targetDeliveryId, $deliveryId] as $id) {
            if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', strtolower(trim($id))) !== 1) {
                throw new DomainException('The delivery request identifier is invalid.');
            }
        }
        $targetDeliveryId = strtolower(trim($targetDeliveryId));
        $deliveryId = strtolower(trim($deliveryId));
        $target = $pdo->prepare("SELECT * FROM managed_delivery_intent_outbox WHERE delivery_id=? AND intent_type='provision' AND delivered_at IS NOT NULL AND receipt_id IS NOT NULL LIMIT 1");
        $target->execute([$targetDeliveryId]);
        $original = $target->fetch(PDO::FETCH_ASSOC);
        if (!$original) throw new DomainException('Only an accepted managed delivery can be revoked.');
        $existing = $pdo->prepare('SELECT target_delivery_id,delivered_at,dead_lettered_at FROM managed_delivery_intent_outbox WHERE delivery_id=?');
        $existing->execute([$deliveryId]);
        $prior = $existing->fetch(PDO::FETCH_ASSOC);
        if ($prior) {
            if (!hash_equals((string)$prior['target_delivery_id'], $targetDeliveryId)) throw new DomainException('The delivery request identifier was already used for a different request.');
            return ['deliveryId' => $deliveryId, 'replayed' => true, 'status' => !empty($prior['delivered_at']) ? 'accepted' : (!empty($prior['dead_lettered_at']) ? 'failed' : 'queued')];
        }
        if (!empty($original['revoked_at'])) throw new DomainException('This managed delivery is already revoked.');
        if (!self::opaqueId((string)$original['receipt_id'])) throw new DomainException('The accepted delivery receipt is invalid.');
        $contract = $this->revocationContract($pdo, $original);
        $payload = [
            'schemaVersion' => 1,
            'applicationKey' => $contract['applicationKey'],
            'deliveryId' => $deliveryId,
            'occurredAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'receiptId' => (string)$original['receipt_id'],
            'reasonCode' => 'project_alpha_delivery_revoked',
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $insert = $pdo->prepare("INSERT INTO managed_delivery_intent_outbox(delivery_id,intent_type,target_delivery_id,integration_profile_id,destination_url,pinned_application_key,signing_key_id,signing_contract_hash,delivery_timeout_seconds,delivery_max_attempts,actor_user_id,scope_type,scope_public_id,audience_type,audience_public_id,access_mode,request_fingerprint,payload_json) VALUES(?,'revoke',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $insert->execute([$deliveryId, $targetDeliveryId, $contract['profileId'], $contract['url'], $contract['applicationKey'], $contract['keyId'], $contract['contractHash'], $contract['timeout'], $contract['maxAttempts'], $actorUserId, (string)$original['scope_type'], (string)$original['scope_public_id'], (string)$original['audience_type'], (string)$original['audience_public_id'], (string)$original['access_mode'], hash('sha256', $body), $body]);
        return ['deliveryId' => $deliveryId, 'replayed' => false, 'status' => 'queued'];
    }

    public function requeueRevocation(PDO $pdo, string $deliveryId): void
    {
        $deliveryId = strtolower(trim($deliveryId));
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $deliveryId) !== 1) throw new DomainException('The delivery request identifier is invalid.');
        $pdo->beginTransaction();
        try {
            $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $failed = $pdo->prepare("SELECT * FROM managed_delivery_intent_outbox WHERE delivery_id=? AND intent_type='revoke' AND delivered_at IS NULL AND dead_lettered_at IS NOT NULL LIMIT 1{$lock}");
            $failed->execute([$deliveryId]);
            $failedIntent = $failed->fetch(PDO::FETCH_ASSOC);
            if (!$failedIntent) throw new DomainException('The failed revocation is no longer eligible for retry.');
            $targetDeliveryId = (string)$failedIntent['target_delivery_id'];
            $original = $pdo->prepare("SELECT 1 FROM managed_delivery_intent_outbox WHERE delivery_id=? AND intent_type='provision' AND delivered_at IS NOT NULL AND revoked_at IS NULL LIMIT 1{$lock}");
            $original->execute([$targetDeliveryId]);
            if (!$original->fetchColumn()) throw new DomainException('The failed revocation is no longer eligible for retry.');
            $this->deliveryContractForClaim($pdo, $failedIntent);
            $statement = $pdo->prepare("UPDATE managed_delivery_intent_outbox SET attempts=0,next_attempt_at=CURRENT_TIMESTAMP,claim_token=NULL,claimed_at=NULL,dead_lettered_at=NULL,last_http_status=NULL,last_error_code=NULL WHERE delivery_id=? AND intent_type='revoke' AND delivered_at IS NULL AND dead_lettered_at IS NOT NULL");
            $statement->execute([$deliveryId]);
            if ($statement->rowCount() !== 1) throw new DomainException('The failed revocation is no longer eligible for retry.');
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private static function opaqueId(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D', $value) === 1;
    }

    /** @param array<string,string> $authHeaders */
    private static function contractHash(int $profileId, string $applicationKey, string $url, string $keyId, array $authHeaders, string $secret): string
    {
        ksort($authHeaders, SORT_STRING);
        return hash('sha256', $profileId . "\n" . $applicationKey . "\n" . $url . "\n" . $keyId . "\n" . json_encode($authHeaders, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n" . hash('sha256', $secret));
    }

    private function scopeExists(PDO $pdo, string $type, string $publicId): bool
    {
        $table = self::SCOPE_TABLES[$type];
        $suffix = $type === 'project' ? " AND status<>'cancelled'" : ($type === 'client' ? ' AND archived=0 AND deleted_at IS NULL' : '');
        $statement = $pdo->prepare("SELECT 1 FROM {$table} WHERE public_id=?{$suffix} LIMIT 1");
        $statement->execute([$publicId]);
        return (bool)$statement->fetchColumn();
    }

    private function audienceExists(PDO $pdo, string $type, string $publicId): bool
    {
        if ($type !== 'principal') return false;
        $statement = $pdo->prepare('SELECT 1 FROM portal_principals WHERE public_id=? AND enabled=1 AND revoked_at IS NULL LIMIT 1');
        $statement->execute([$publicId]);
        return (bool)$statement->fetchColumn();
    }

    private function expiry(string $value, string $accessMode): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        try {
            $parsed = new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new DomainException('The delivery expiration is invalid.');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $utc = $parsed->setTimezone(new DateTimeZone('UTC'));
        $maximumDays = $accessMode === 'guest' ? 90 : 365;
        if ($utc <= $now->modify('+6 minutes') || $utc > $now->modify('+' . $maximumDays . ' days')) {
            throw new DomainException('Delivery expiration must be more than six minutes in the future and within the supported policy window.');
        }
        return $utc->format('Y-m-d\TH:i:s.v\Z');
    }

    /** @param array<string,mixed> $row */
    private function matchesExisting(array $row, string $scopeType, string $scopePublicId, string $audienceType, string $audiencePublicId, string $accessMode, ?string $expiresAt, string $label): bool
    {
        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        return is_array($payload)
            && ($payload['scope'] ?? null) === ['type' => $scopeType, 'publicId' => $scopePublicId]
            && ($payload['audience'] ?? null) === ['type' => $audienceType, 'publicId' => $audiencePublicId]
            && ($payload['accessMode'] ?? null) === $accessMode
            && ($payload['expiresAt'] ?? null) === $expiresAt
            && ($payload['label'] ?? null) === ($label !== '' ? $label : null)
            && ($payload['notify'] ?? null) === true;
    }
}
