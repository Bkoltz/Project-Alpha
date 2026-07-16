<?php

declare(strict_types=1);

namespace App\Utils;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\Exception\CounterException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

final class PasskeyException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

/**
 * WebAuthn ceremony and credential persistence service.
 *
 * Request hosts are deliberately ignored. The relying party is derived only
 * from an explicit installation origin, preventing Host-header RP confusion.
 */
final class PasskeyService
{
    private const CHALLENGE_TTL_SECONDS = 300;

    private readonly string $origin;
    private readonly string $rpId;
    private readonly string $rpName;
    private readonly mixed $serializer;
    private readonly AttestationStatementSupportManager $attestationManager;

    /** @param array<string,mixed> $appConfig */
    public function __construct(private readonly PDO $pdo, array $appConfig = [])
    {
        $configuredOrigin = trim((string)(getenv('WEBAUTHN_ORIGIN') ?: getenv('APP_HOST') ?: ($appConfig['webauthn_origin'] ?? $appConfig['app_host'] ?? '')));
        if ($configuredOrigin === '') {
            throw new PasskeyException('passkey_not_configured', 'Passkeys require a canonical WebAuthn origin in WEBAUTHN_ORIGIN.', 503);
        }

        $configuredOrigin = rtrim($configuredOrigin, '/');
        $parts = parse_url($configuredOrigin);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
        $path = (string)($parts['path'] ?? '');
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($host === '' || ($scheme !== 'https' && !($scheme === 'http' && $isLocal)) || ($path !== '' && $path !== '/')) {
            throw new PasskeyException('passkey_origin_invalid', 'The WebAuthn origin must be an HTTPS origin without a path (HTTP is allowed only on localhost).', 503);
        }

        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $this->origin = $scheme . '://' . $host . $port;
        $this->rpId = strtolower(rtrim(trim((string)(getenv('WEBAUTHN_RP_ID') ?: $host)), '.'));
        if ($this->rpId === '' || str_contains($this->rpId, '/') || str_contains($this->rpId, ':')) {
            throw new PasskeyException('passkey_rp_invalid', 'WEBAUTHN_RP_ID must be a hostname without a scheme or port.', 503);
        }
        if ($host !== $this->rpId && !str_ends_with($host, '.' . $this->rpId)) {
            throw new PasskeyException('passkey_rp_invalid', 'The WebAuthn RP ID must equal the configured origin host or be its parent domain.', 503);
        }

        $this->rpName = trim((string)($appConfig['brand_name'] ?? 'Project Alpha')) ?: 'Project Alpha';
        $this->attestationManager = new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]);
        $this->serializer = (new WebauthnSerializerFactory($this->attestationManager))->create();
    }

    /** @return array{challenge_id:string,publicKey:array<string,mixed>} */
    public function registrationOptions(int $userId, string $email, string $displayName, string $credentialName, string $ip): array
    {
        $this->cleanupChallenges();
        $this->assertRateLimit($ip, 'registration', $userId);
        $credentialName = $this->validateName($credentialName);
        $userHandle = $this->userHandleFor($userId);
        $challenge = random_bytes(32);

        $exclude = [];
        $stmt = $this->pdo->prepare('SELECT credential_record FROM passkey_credentials WHERE user_id=? AND revoked_at IS NULL ORDER BY id');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $recordJson) {
            $exclude[] = $this->recordFromJson((string)$recordJson)->getPublicKeyCredentialDescriptor();
        }

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create($this->rpName, $this->rpId),
            PublicKeyCredentialUserEntity::create($email, $userHandle, $displayName ?: $email),
            $challenge,
            [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)],
            AuthenticatorSelectionCriteria::create(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $exclude,
            120000
        );

        $normalized = $this->normalizeOptions($options);
        $challengeId = $this->storeChallenge($challenge, 'registration', $userId, [
            'options' => $this->withoutChallenge($normalized),
            'credential_name' => $credentialName,
        ]);
        return ['challenge_id' => $challengeId, 'publicKey' => $normalized];
    }

    /** @param array<string,mixed> $credential */
    public function finishRegistration(int $userId, string $challengeId, array $credential, string $ip): array
    {
        $consumed = $this->consumeChallenge($challengeId, 'registration', $credential);
        if ((int)($consumed['user_id'] ?? 0) !== $userId) {
            throw new PasskeyException('passkey_challenge_invalid', 'This passkey request does not belong to the signed-in account.', 403);
        }

        try {
            $context = $this->decodeContext($consumed['context_json'] ?? null);
            $optionsData = (array)($context['options'] ?? []);
            $optionsData['challenge'] = self::base64UrlEncode($consumed['challenge']);
            $options = $this->serializer->deserialize(json_encode($optionsData, JSON_THROW_ON_ERROR), PublicKeyCredentialCreationOptions::class, 'json');
            $publicKeyCredential = $this->credentialFromPayload($credential);
            if (!$publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
                throw new PasskeyException('passkey_response_invalid', 'The browser returned an invalid registration response.', 422);
            }

            $factory = $this->ceremonyFactory();
            $record = AuthenticatorAttestationResponseValidator::create($factory->creationCeremony())->check(
                $publicKeyCredential->response,
                $options,
                $this->rpId
            );
            $recordJson = $this->serializer->serialize($record, 'json');
            $name = $this->validateName((string)($context['credential_name'] ?? 'Passkey'));

            $stmt = $this->pdo->prepare(
                'INSERT INTO passkey_credentials
                 (user_id,credential_id,user_handle,display_name,credential_record,signature_counter,transports,aaguid,backup_eligible,backup_status)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $userId,
                $record->publicKeyCredentialId,
                $record->userHandle,
                $name,
                $recordJson,
                $record->counter,
                json_encode($record->transports, JSON_THROW_ON_ERROR),
                (string)$record->aaguid,
                $record->backupEligible === null ? null : (int)$record->backupEligible,
                $record->backupStatus === null ? null : (int)$record->backupStatus,
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->recordAttempt($ip, 'registration', true, $userId, null);
            return ['id' => $id, 'name' => $name];
        } catch (PasskeyException $e) {
            $this->recordAttempt($ip, 'registration', false, $userId, $e->errorCode);
            throw $e;
        } catch (Throwable $e) {
            $code = str_contains(strtolower($e->getMessage()), 'duplicate') ? 'passkey_already_registered' : 'passkey_verification_failed';
            $this->recordAttempt($ip, 'registration', false, $userId, $code);
            throw new PasskeyException($code, $code === 'passkey_already_registered' ? 'That passkey is already registered.' : 'The passkey could not be verified.', 422, $e);
        }
    }

    /** @return array{challenge_id:string,publicKey:array<string,mixed>} */
    public function authenticationOptions(string $ip): array
    {
        $this->cleanupChallenges();
        $this->assertRateLimit($ip, 'authentication', null);
        $challenge = random_bytes(32);
        $options = PublicKeyCredentialRequestOptions::create(
            $challenge,
            $this->rpId,
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            120000
        );
        $normalized = $this->normalizeOptions($options);
        $challengeId = $this->storeChallenge($challenge, 'authentication', null, [
            'options' => $this->withoutChallenge($normalized),
        ]);
        return ['challenge_id' => $challengeId, 'publicKey' => $normalized];
    }

    /** @param array<string,mixed> $credential @return array<string,mixed> */
    public function finishAuthentication(string $challengeId, array $credential, string $ip): array
    {
        $consumed = $this->consumeChallenge($challengeId, 'authentication', $credential);
        $rawId = self::base64UrlDecode((string)($credential['rawId'] ?? $credential['id'] ?? ''));
        if ($rawId === '') {
            $this->recordAttempt($ip, 'authentication', false, null, 'passkey_response_invalid');
            throw new PasskeyException('passkey_response_invalid', 'The browser returned an invalid passkey response.', 422);
        }

        $stmt = $this->pdo->prepare(
            'SELECT pc.*,u.email,u.username,u.role,u.is_disabled,u.auth_version,u.force_password_reset,u.tos_accepted_at,u.deleted_at
             FROM passkey_credentials pc JOIN users u ON u.id=pc.user_id
             WHERE pc.credential_id=? AND pc.revoked_at IS NULL LIMIT 1'
        );
        $stmt->execute([$rawId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !empty($row['is_disabled']) || !empty($row['deleted_at'])) {
            $this->recordAttempt($ip, 'authentication', false, $row ? (int)$row['user_id'] : null, 'passkey_not_found');
            throw new PasskeyException('passkey_not_found', 'This passkey is unavailable for sign-in.', 403);
        }

        $userId = (int)$row['user_id'];
        try {
            $context = $this->decodeContext($consumed['context_json'] ?? null);
            $optionsData = (array)($context['options'] ?? []);
            $optionsData['challenge'] = self::base64UrlEncode($consumed['challenge']);
            $options = $this->serializer->deserialize(json_encode($optionsData, JSON_THROW_ON_ERROR), PublicKeyCredentialRequestOptions::class, 'json');
            $publicKeyCredential = $this->credentialFromPayload($credential);
            if (!$publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
                throw new PasskeyException('passkey_response_invalid', 'The browser returned an invalid authentication response.', 422);
            }
            $record = $this->recordFromJson((string)$row['credential_record']);
            $oldCounter = (int)$row['signature_counter'];
            $updated = AuthenticatorAssertionResponseValidator::create($this->ceremonyFactory()->requestCeremony())->check(
                $record,
                $publicKeyCredential->response,
                $options,
                $this->rpId,
                $record->userHandle
            );

            $update = $this->pdo->prepare(
                'UPDATE passkey_credentials SET credential_record=?,signature_counter=?,backup_eligible=?,backup_status=?,last_used_at=UTC_TIMESTAMP()
                 WHERE id=? AND signature_counter=? AND revoked_at IS NULL'
            );
            $update->execute([
                $this->serializer->serialize($updated, 'json'),
                $updated->counter,
                $updated->backupEligible === null ? null : (int)$updated->backupEligible,
                $updated->backupStatus === null ? null : (int)$updated->backupStatus,
                (int)$row['id'],
                $oldCounter,
            ]);
            if ($update->rowCount() !== 1) {
                throw new PasskeyException('passkey_concurrent_use', 'The passkey was used concurrently. Start sign-in again.', 409);
            }
            $this->recordAttempt($ip, 'authentication', true, $userId, null);
            return $row;
        } catch (PasskeyException $e) {
            $this->recordAttempt($ip, 'authentication', false, $userId, $e->errorCode);
            throw $e;
        } catch (Throwable $e) {
            $code = $e instanceof CounterException ? 'passkey_counter_regressed' : 'passkey_verification_failed';
            $this->recordAttempt($ip, 'authentication', false, $userId, $code);
            $message = $e instanceof CounterException
                ? 'This passkey reported suspicious use. Use another sign-in method and re-enroll it.'
                : 'The passkey could not be verified. Use another sign-in method.';
            throw new PasskeyException($code, $message, 422, $e);
        }
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id,display_name,transports,aaguid,backup_eligible,backup_status,created_at,last_used_at
             FROM passkey_credentials WHERE user_id=? AND revoked_at IS NULL ORDER BY last_used_at DESC,created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function rename(int $userId, int $credentialId, string $name, string $ip): void
    {
        $name = $this->validateName($name);
        $stmt = $this->pdo->prepare('UPDATE passkey_credentials SET display_name=? WHERE id=? AND user_id=? AND revoked_at IS NULL');
        $stmt->execute([$name, $credentialId, $userId]);
        if ($stmt->rowCount() !== 1) {
            throw new PasskeyException('passkey_not_found', 'Passkey not found.', 404);
        }
        $this->recordAttempt($ip, 'management', true, $userId, null);
    }

    public function revoke(int $userId, int $credentialId, int $revokedBy, string $ip): void
    {
        $stmt = $this->pdo->prepare('UPDATE passkey_credentials SET revoked_at=UTC_TIMESTAMP(),revoked_by=? WHERE id=? AND user_id=? AND revoked_at IS NULL');
        $stmt->execute([$revokedBy, $credentialId, $userId]);
        if ($stmt->rowCount() !== 1) {
            throw new PasskeyException('passkey_not_found', 'Passkey not found.', 404);
        }
        $this->recordAttempt($ip, 'management', true, $userId, null);
    }

    public function cleanupChallenges(): int
    {
        return $this->pdo->exec('DELETE FROM passkey_challenges WHERE consumed_at IS NOT NULL OR expires_at < UTC_TIMESTAMP()');
    }

    public function assertManagementAllowed(int $userId, string $ip): void
    {
        $this->assertRateLimit($ip, 'management', $userId);
    }

    public function recordManagementAttempt(int $userId, string $ip, bool $success, ?string $code = null): void
    {
        $this->recordAttempt($ip, 'management', $success, $userId, $code);
    }

    private function ceremonyFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$this->origin], false);
        $factory->setAttestationStatementSupportManager($this->attestationManager);
        return $factory;
    }

    /** @param PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options @return array<string,mixed> */
    private function normalizeOptions(object $options): array
    {
        $data = json_decode($this->serializer->serialize($options, 'json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new PasskeyException('passkey_options_failed', 'Could not create passkey options.', 500);
        }
        return $data;
    }

    /** @param array<string,mixed> $normalized @return array<string,mixed> */
    private function withoutChallenge(array $normalized): array
    {
        unset($normalized['challenge']);
        return $normalized;
    }

    /** @param array<string,mixed> $context */
    private function storeChallenge(string $challenge, string $ceremony, ?int $userId, array $context): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
            throw new PasskeyException('passkey_session_required', 'A secure browser session is required.', 409);
        }
        $sessionHash = hash('sha256', session_id(), true);
        $limit = $this->pdo->prepare(
            'SELECT COUNT(*) FROM passkey_challenges
             WHERE session_hash=? AND consumed_at IS NULL AND created_at >= UTC_TIMESTAMP() - INTERVAL 10 MINUTE'
        );
        $limit->execute([$sessionHash]);
        if ((int)$limit->fetchColumn() >= 20) {
            throw new PasskeyException('passkey_rate_limited', 'Too many passkey requests. Try again later.', 429);
        }
        $id = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . self::CHALLENGE_TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO passkey_challenges (id,user_id,ceremony,challenge_hash,session_hash,context_json,expires_at)
             VALUES (?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $id,
            $userId,
            $ceremony,
            hash('sha256', $challenge, true),
            $sessionHash,
            json_encode($context, JSON_THROW_ON_ERROR),
            $expires,
        ]);
        return $id;
    }

    /** @param array<string,mixed> $credential @return array<string,mixed> */
    private function consumeChallenge(string $id, string $ceremony, array $credential): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $id) || session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
            throw new PasskeyException('passkey_challenge_invalid', 'The passkey request is invalid or expired.', 409);
        }
        $clientData = self::base64UrlDecode((string)($credential['response']['clientDataJSON'] ?? ''));
        $clientJson = json_decode($clientData, true);
        $challenge = is_array($clientJson) ? self::base64UrlDecode((string)($clientJson['challenge'] ?? '')) : '';
        if ($challenge === '') {
            throw new PasskeyException('passkey_response_invalid', 'The browser returned invalid client data.', 422);
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM passkey_challenges WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if (!$row || $row['ceremony'] !== $ceremony || !empty($row['consumed_at']) || new DateTimeImmutable((string)$row['expires_at'], new DateTimeZone('UTC')) < $now) {
                throw new PasskeyException('passkey_challenge_invalid', 'The passkey request is invalid or expired.', 409);
            }
            if (!hash_equals((string)$row['session_hash'], hash('sha256', session_id(), true))) {
                throw new PasskeyException('passkey_session_mismatch', 'The passkey request belongs to a different browser session.', 403);
            }
            if (!hash_equals((string)$row['challenge_hash'], hash('sha256', $challenge, true))) {
                $this->pdo->prepare('UPDATE passkey_challenges SET consumed_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
                $this->pdo->commit();
                throw new PasskeyException('passkey_challenge_invalid', 'The passkey challenge did not match.', 409);
            }
            $this->pdo->prepare('UPDATE passkey_challenges SET consumed_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
            $this->pdo->commit();
            $row['challenge'] = $challenge;
            return $row;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $credential */
    private function credentialFromPayload(array $credential): PublicKeyCredential
    {
        $json = json_encode($credential, JSON_THROW_ON_ERROR);
        $parsed = $this->serializer->deserialize($json, PublicKeyCredential::class, 'json');
        if (!$parsed instanceof PublicKeyCredential) {
            throw new PasskeyException('passkey_response_invalid', 'Invalid passkey response.', 422);
        }
        return $parsed;
    }

    private function recordFromJson(string $json): CredentialRecord
    {
        $record = $this->serializer->deserialize($json, CredentialRecord::class, 'json');
        if (!$record instanceof CredentialRecord) {
            throw new PasskeyException('passkey_record_invalid', 'The stored passkey record is invalid.', 500);
        }
        return $record;
    }

    private function userHandleFor(int $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT user_handle FROM passkey_credentials WHERE user_id=? ORDER BY id LIMIT 1');
        $stmt->execute([$userId]);
        $handle = $stmt->fetchColumn();
        return is_string($handle) && $handle !== '' ? $handle : random_bytes(32);
    }

    private function validateName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            throw new PasskeyException('passkey_name_invalid', 'Passkey name must be between 1 and 100 characters.', 422);
        }
        return $name;
    }

    /** @return array<string,mixed> */
    private function decodeContext(mixed $json): array
    {
        $context = json_decode((string)$json, true);
        return is_array($context) ? $context : [];
    }

    private function assertRateLimit(string $ip, string $ceremony, ?int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM passkey_attempts
             WHERE ip_address=? AND ceremony=? AND success=0 AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 10 MINUTE'
        );
        $stmt->execute([$ip, $ceremony]);
        if ((int)$stmt->fetchColumn() >= 10) {
            throw new PasskeyException('passkey_rate_limited', 'Too many passkey attempts. Try again later.', 429);
        }
        if ($userId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM passkey_attempts
                 WHERE user_id=? AND ceremony=? AND success=0 AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 10 MINUTE'
            );
            $stmt->execute([$userId, $ceremony]);
            if ((int)$stmt->fetchColumn() >= 10) {
                throw new PasskeyException('passkey_rate_limited', 'Too many passkey attempts. Try again later.', 429);
            }
        }
    }

    private function recordAttempt(string $ip, string $ceremony, bool $success, ?int $userId, ?string $code): void
    {
        try {
            $stmt = $this->pdo->prepare('INSERT INTO passkey_attempts (user_id,ip_address,ceremony,success,failure_code) VALUES (?,?,?,?,?)');
            $stmt->execute([$userId, $ip, $ceremony, $success ? 1 : 0, $code]);
        } catch (Throwable $ignored) {}
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            return '';
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        return is_string($decoded) ? $decoded : '';
    }
}
