<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\ManagedDeliveryIntentSender;
use App\Services\ManagedDeliveryIntentSigner;
use App\Services\ManagedDeliveryService;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ManagedDeliveryIntegrationTest extends TestCase
{
    private string|false $previousEncryptionKey;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite unavailable');
        $this->previousEncryptionKey = getenv('APP_ENCRYPTION_KEY');
        putenv('APP_ENCRYPTION_KEY=managed-delivery-test-key');
    }

    protected function tearDown(): void
    {
        $this->previousEncryptionKey === false ? putenv('APP_ENCRYPTION_KEY') : putenv('APP_ENCRYPTION_KEY=' . $this->previousEncryptionKey);
    }

    public function testPinnedWireFixtureMatchesPathBoundSigner(): void
    {
        $path = dirname(__DIR__) . '/fixtures/project-alpha-delivery-intent-v1.json';
        $fixture = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('f16d540bcfbcf4c77c356fc37e2c046a23a473ebec701d526e3b8d45f38c90e8', hash_file('sha256', $path));
        foreach ($fixture['cases'] as $case) {
            $canonical = $fixture['timestamp'] . "\nPOST\n" . $case['path'] . "\n" . $fixture['keyId'] . "\n" . $case['deliveryId'] . "\n" . $case['body'];
            self::assertSame($case['canonical'], $canonical);
            $headers = $this->headers(ManagedDeliveryIntentSigner::headers([
                'applicationKey' => $fixture['applicationKey'],
                'keyId' => $fixture['keyId'],
                'secret' => $fixture['testSecret'],
                'authHeaders' => [],
            ], $case['deliveryId'], 'https://ops.example' . $case['path'], $case['body'], $fixture['timestamp']));
            self::assertSame($case['bodySha256'], hash('sha256', $case['body']));
            self::assertSame($case['bodySha256'], $headers['x-portal-integration-body-sha256']);
            self::assertSame($case['signature'], $headers['x-portal-integration-signature']);
        }
    }

    public function testPreflightWorksWhileProviderDisabledAndValidatesExactCapabilities(): void
    {
        $pdo = $this->database(false);
        $captured = [];
        $result = (new ManagedDeliveryIntentSender())->preflight($pdo, static function (string $url, array $headers, string $body, int $timeout) use (&$captured): array {
            $captured = compact('url', 'headers', 'body', 'timeout');
            return ['status' => 200, 'body' => '{"status":"ready","schemaVersion":1,"integrationEnabled":false,"portalSupported":true,"guestSupported":false,"revocationSupported":true}'];
        });
        self::assertSame('https://ops.example/api/internal/project-alpha/delivery-intents/preflight', $captured['url']);
        self::assertSame(['schemaVersion','applicationKey','deliveryId','occurredAt'], array_keys(json_decode($captured['body'], true, 8, JSON_THROW_ON_ERROR)));
        self::assertFalse($result['integrationEnabled']);
        self::assertTrue($result['revocationSupported']);

        $this->expectException(\RuntimeException::class);
        (new ManagedDeliveryIntentSender())->preflight($pdo, static fn(): array => ['status' => 200, 'body' => '{"status":"ready","schemaVersion":1,"integrationEnabled":false,"portalSupported":true,"guestSupported":false,"revocationSupported":true,"extra":1}']);
    }

    public function testPortalProvisionAndAcceptedRevocationLifecycle(): void
    {
        $pdo = $this->database();
        $service = new ManagedDeliveryService();
        $deliveryId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $queued = $service->queue($pdo, [
            'delivery_id' => $deliveryId,
            'scope_type' => 'project',
            'scope_public_id' => str_repeat('a', 32),
            'audience_type' => 'principal',
            'audience_public_id' => str_repeat('b', 32),
            'label' => 'Johnson Road',
        ], 7);
        self::assertSame('queued', $queued['status']);
        $body = (string)$pdo->query("SELECT payload_json FROM managed_delivery_intent_outbox WHERE delivery_id='{$deliveryId}'")->fetchColumn();
        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(['schemaVersion','applicationKey','deliveryId','occurredAt','scope','audience','accessMode','expiresAt','label','notify'], array_keys($payload));
        self::assertSame('portal', $payload['accessMode']);
        self::assertSame('principal', $payload['audience']['type']);
        self::assertStringNotContainsString('email', strtolower($body));
        self::assertStringNotContainsString('r2', strtolower($body));
        $pinned = $pdo->query("SELECT integration_profile_id,destination_url,pinned_application_key,signing_key_id,signing_contract_hash,delivery_timeout_seconds,delivery_max_attempts FROM managed_delivery_intent_outbox WHERE delivery_id='{$deliveryId}'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int)$pinned['integration_profile_id']);
        self::assertSame('https://ops.example/api/internal/project-alpha/delivery-intents', $pinned['destination_url']);
        self::assertSame('project-alpha', $pinned['pinned_application_key']);
        self::assertSame('ops-v1', $pinned['signing_key_id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)$pinned['signing_contract_hash']);
        self::assertSame(5, (int)$pinned['delivery_timeout_seconds']);
        self::assertSame(3, (int)$pinned['delivery_max_attempts']);

        $sender = new ManagedDeliveryIntentSender();
        $provision = $sender->deliverDeliveryId($pdo, $deliveryId, static fn(): array => ['status' => 202, 'body' => '{"receiptId":"receipt_01","status":"accepted"}']);
        self::assertSame(1, $provision['accepted']);
        self::assertSame('receipt_01', $pdo->query("SELECT receipt_id FROM managed_delivery_intent_outbox WHERE delivery_id='{$deliveryId}'")->fetchColumn());

        $revokeId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $service->queueRevocation($pdo, $deliveryId, $revokeId, 7);
        self::assertNull($pdo->query("SELECT revoked_at FROM managed_delivery_intent_outbox WHERE delivery_id='{$deliveryId}'")->fetchColumn());
        $captured = [];
        $revoke = $sender->deliverDeliveryId($pdo, $revokeId, static function (string $url, array $headers, string $rawBody) use (&$captured): array {
            $captured = compact('url', 'headers', 'rawBody');
            return ['status' => 202, 'body' => '{"receiptId":"revoke_receipt_01","status":"accepted"}'];
        });
        self::assertSame(1, $revoke['accepted']);
        self::assertStringEndsWith('/delivery-intents/revoke', $captured['url']);
        self::assertSame(['schemaVersion','applicationKey','deliveryId','occurredAt','receiptId','reasonCode'], array_keys(json_decode($captured['rawBody'], true, 8, JSON_THROW_ON_ERROR)));
        self::assertSame('receipt_01', json_decode($captured['rawBody'], true)['receiptId']);
        self::assertNotNull($pdo->query("SELECT revoked_at FROM managed_delivery_intent_outbox WHERE delivery_id='{$deliveryId}'")->fetchColumn());
    }

    public function testGuestIsExplicitOnlyAndMalformedAcceptanceFailsClosed(): void
    {
        $pdo = $this->database();
        $service = new ManagedDeliveryService();
        try {
            $service->queue($pdo, [
                'delivery_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
                'scope_type' => 'project', 'scope_public_id' => str_repeat('a', 32),
                'audience_type' => 'project', 'audience_public_id' => str_repeat('a', 32),
            ], 7);
            self::fail('Expected non-principal audience denial.');
        } catch (DomainException $error) {
            self::assertStringContainsString('selection is invalid', $error->getMessage());
        }
        try {
            $service->queue($pdo, [
                'delivery_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
                'scope_type' => 'project', 'scope_public_id' => str_repeat('a', 32),
                'audience_type' => 'principal', 'audience_public_id' => str_repeat('b', 32),
                'access_mode' => 'guest',
            ], 7);
            self::fail('Expected guest policy denial.');
        } catch (DomainException $error) {
            self::assertStringContainsString('Portal delivery was not changed or retried', $error->getMessage());
        }
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM managed_delivery_intent_outbox')->fetchColumn());

        try {
            $service->queue($pdo, [
                'delivery_id' => '12121212-1212-4212-8212-121212121212',
                'scope_type' => 'project', 'scope_public_id' => str_repeat('a', 129),
                'audience_type' => 'principal', 'audience_public_id' => str_repeat('b', 32),
            ], 7);
            self::fail('Expected overlong safe-ID denial.');
        } catch (DomainException $error) {
            self::assertStringContainsString('selection is invalid', $error->getMessage());
        }

        $id = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
        $service->queue($pdo, [
            'delivery_id' => $id, 'scope_type' => 'project', 'scope_public_id' => str_repeat('a', 32),
            'audience_type' => 'principal', 'audience_public_id' => str_repeat('b', 32),
        ], 7);
        $result = (new ManagedDeliveryIntentSender())->deliverDeliveryId($pdo, $id, static fn(): array => ['status' => 202, 'body' => '{"receiptId":"receipt_01","status":"accepted","url":"secret"}']);
        self::assertSame(1, $result['retrying']);
        self::assertNull($pdo->query("SELECT receipt_id FROM managed_delivery_intent_outbox WHERE delivery_id='{$id}'")->fetchColumn());
        self::assertSame('invalid_response', $pdo->query("SELECT last_error_code FROM managed_delivery_intent_outbox WHERE delivery_id='{$id}'")->fetchColumn());
    }

    public function testRevocationInheritsAcceptedProvisionReceiverAfterCurrentProfileMoves(): void
    {
        $pdo = $this->database();
        $service = new ManagedDeliveryService();
        $sender = new ManagedDeliveryIntentSender();
        $provisionId = '19191919-1919-4919-8919-191919191919';
        $revokeId = '20202020-2020-4020-8020-202020202020';
        $service->queue($pdo, [
            'delivery_id' => $provisionId, 'scope_type' => 'project', 'scope_public_id' => str_repeat('a', 32),
            'audience_type' => 'principal', 'audience_public_id' => str_repeat('b', 32),
        ], 7);
        $sender->deliverDeliveryId($pdo, $provisionId, static fn(): array => ['status' => 202, 'body' => '{"receiptId":"receipt_old_receiver","status":"accepted"}']);
        $original = $pdo->query("SELECT integration_profile_id,destination_url,pinned_application_key,signing_key_id,signing_contract_hash,delivery_timeout_seconds,delivery_max_attempts FROM managed_delivery_intent_outbox WHERE delivery_id='{$provisionId}'")->fetch(PDO::FETCH_ASSOC);

        $rotatedCredentials = crypto_encrypt(json_encode(['currentSecret' => str_repeat('u', 32), 'previousSecret' => str_repeat('s', 32), 'authHeaders' => ['CF-Access-Client-Id' => 'opaque-id', 'CF-Access-Client-Secret' => 'opaque-secret']], JSON_THROW_ON_ERROR));
        $rotate = $pdo->prepare('UPDATE portal_integration_profiles SET delivery_key_id=?,delivery_previous_key_id=?,delivery_previous_valid_until=?,delivery_credentials_enc=? WHERE id=1');
        $rotate->execute(['ops-v1-rotated', 'ops-v1', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s.u'), $rotatedCredentials]);
        $replacementCredentials = crypto_encrypt(json_encode(['currentSecret' => str_repeat('t', 32), 'previousSecret' => '', 'authHeaders' => ['CF-Access-Client-Id' => 'replacement-id', 'CF-Access-Client-Secret' => 'replacement-secret']], JSON_THROW_ON_ERROR));
        $replacement = $pdo->prepare('INSERT INTO portal_integration_profiles(id,application_key,display_label,enabled,delivery_enabled,delivery_key_id,delivery_credentials_enc,delivery_timeout_seconds,delivery_max_attempts) VALUES(2,?,?,?,?,?,?,?,?)');
        $replacement->execute(['replacement-app', 'Replacement Ops', 1, 1, 'ops-v2', $replacementCredentials, 9, 8]);
        $pdo->exec("UPDATE app_config SET config_value='2' WHERE config_key='managed_delivery_profile_id'");
        $pdo->exec("UPDATE app_config SET config_value='https://replacement.example/api/internal/project-alpha/delivery-intents' WHERE config_key='managed_delivery_intent_url'");

        $service->queueRevocation($pdo, $provisionId, $revokeId, 7);
        $revoke = $pdo->query("SELECT integration_profile_id,destination_url,pinned_application_key,signing_key_id,signing_contract_hash,delivery_timeout_seconds,delivery_max_attempts,payload_json FROM managed_delivery_intent_outbox WHERE delivery_id='{$revokeId}'")->fetch(PDO::FETCH_ASSOC);
        foreach (['integration_profile_id','destination_url','pinned_application_key','delivery_timeout_seconds','delivery_max_attempts'] as $field) {
            self::assertSame((string)$original[$field], (string)$revoke[$field], $field);
        }
        self::assertSame('ops-v1-rotated', $revoke['signing_key_id']);
        self::assertNotSame($original['signing_contract_hash'], $revoke['signing_contract_hash']);
        self::assertSame('project-alpha', json_decode((string)$revoke['payload_json'], true, 8, JSON_THROW_ON_ERROR)['applicationKey']);
        $captured = [];
        $sent = $sender->deliverDeliveryId($pdo, $revokeId, static function (string $url, array $headers) use (&$captured): array {
            $captured = compact('url', 'headers');
            return ['status' => 202, 'body' => '{"receiptId":"revoke_old_receiver","status":"accepted"}'];
        });
        self::assertSame(1, $sent['accepted']);
        self::assertSame('https://ops.example/api/internal/project-alpha/delivery-intents/revoke', $captured['url']);
        self::assertSame('ops-v1-rotated', $this->headers($captured['headers'])['x-portal-integration-key-id']);
        $pdo->exec('UPDATE portal_integration_profiles SET enabled=0 WHERE id=1');
        $replayed = $service->queueRevocation($pdo, $provisionId, $revokeId, 7);
        self::assertTrue($replayed['replayed']);
        self::assertSame('accepted', $replayed['status']);
    }

    public function testDeliveryExpiryIncludesReceiverAndDispatchSafetyMargin(): void
    {
        $pdo = $this->database();
        $service = new ManagedDeliveryService();
        try {
            $service->queue($pdo, [
                'delivery_id' => '21212121-2121-4121-8121-212121212121',
                'scope_type' => 'project', 'scope_public_id' => str_repeat('a', 32),
                'audience_type' => 'principal', 'audience_public_id' => str_repeat('b', 32),
                'expires_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+5 minutes')->format(DATE_ATOM),
            ], 7);
            self::fail('Expiry inside the dispatch safety margin must be rejected.');
        } catch (DomainException $error) {
            self::assertStringContainsString('more than six minutes', $error->getMessage());
        }
        $accepted = $service->queue($pdo, [
            'delivery_id' => '22222222-2222-4222-8222-222222222222',
            'scope_type' => 'project', 'scope_public_id' => str_repeat('a', 32),
            'audience_type' => 'principal', 'audience_public_id' => str_repeat('b', 32),
            'expires_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+7 minutes')->format(DATE_ATOM),
        ], 7);
        self::assertSame('queued', $accepted['status']);
    }

    public function testMappedPrivateIpv6AndLegacyR2SeparationArePinned(): void
    {
        $method = new \ReflectionMethod(ManagedDeliveryIntentSender::class, 'isPublicAddress');
        self::assertFalse($method->invoke(null, '::ffff:127.0.0.1'));
        self::assertFalse($method->invoke(null, '64:ff9b::7f00:1'));
        self::assertTrue($method->invoke(null, '2606:4700:4700::1111'));
        $root = dirname(__DIR__, 2);
        $view = (string)file_get_contents($root . '/src/views/pages/settings/links.php');
        self::assertStringContainsString('Client Portal (recommended)', $view);
        self::assertStringContainsString("'type' => 'principal'", $view);
        self::assertStringContainsString('Revocation failed', $view);
        self::assertStringContainsString('Retry revocation', $view);
        self::assertStringContainsString('managed-delivery-retry', $view);
        self::assertStringContainsString("['dropbox', 'gdrive', 's3', 'r2']", $view);
        self::assertStringContainsString('R2 Secret Access Key', $view);
        self::assertStringContainsString('cannot be enabled together', (string)file_get_contents($root . '/src/controllers/settings/links_handler.php'));
        self::assertStringNotContainsString('invoice-finalize', (string)file_get_contents($root . '/src/controllers/settings/managed_delivery_send.php'));
        self::assertStringContainsString('send_managed_delivery_intents.php', (string)file_get_contents($root . '/cron/crontab'));
        self::assertStringContainsString('deliverDue($pdo, 50, null, 50)', (string)file_get_contents($root . '/src/cron/send_managed_delivery_intents.php'));
        self::assertStringContainsString("(0,'managed_delivery_enabled','0')", (string)file_get_contents($root . '/database/migrations/0069_managed_delivery_intents.sql'));
        $javascript = (string)file_get_contents($root . '/public/assets/js/settings-links.js');
        self::assertStringContainsString('data.integrationEnabled === true', $javascript);
        self::assertStringContainsString('delivery intents are currently disabled in Ops', $javascript);
        $retryController = (string)file_get_contents($root . '/src/controllers/settings/managed_delivery_retry.php');
        self::assertStringContainsString('requeueRevocation', $retryController);
        self::assertStringContainsString('managed_delivery.revocation_requeued', $retryController);
        $documentation = (string)file_get_contents($root . '/docs/managed-delivery.md');
        self::assertStringContainsString('0069_managed_delivery_intents.sql', $documentation);
        foreach (['managed_delivery_enabled = 0', "managed_delivery_intent_url = ''", 'managed_delivery_profile_id = 0', 'managed_delivery_guest_links_enabled = 0'] as $default) {
            self::assertStringContainsString($default, $documentation);
        }
    }

    public function testQueuedDestinationAndSigningEpochAreImmutableAndUnavailableEpochFailsClosed(): void
    {
        $pdo = $this->database();
        $service = new ManagedDeliveryService();
        $sender = new ManagedDeliveryIntentSender();
        $firstId = '15151515-1515-4515-8515-151515151515';
        $service->queue($pdo, [
            'delivery_id' => $firstId, 'scope_type' => 'project', 'scope_public_id' => str_repeat('a', 32),
            'audience_type' => 'principal', 'audience_public_id' => str_repeat('b', 32),
        ], 7);
        $pdo->exec("UPDATE app_config SET config_value='https://other.example/api/internal/project-alpha/delivery-intents' WHERE config_key='managed_delivery_intent_url'");
        $capturedUrl = null;
        $result = $sender->deliverDeliveryId($pdo, $firstId, static function (string $url) use (&$capturedUrl): array {
            $capturedUrl = $url;
            return ['status' => 202, 'body' => '{"receiptId":"receipt_01","status":"accepted"}'];
        });
        self::assertSame(1, $result['accepted']);
        self::assertSame('https://ops.example/api/internal/project-alpha/delivery-intents', $capturedUrl);

        $secondId = '16161616-1616-4616-8616-161616161616';
        $service->queue($pdo, [
            'delivery_id' => $secondId, 'scope_type' => 'project', 'scope_public_id' => str_repeat('a', 32),
            'audience_type' => 'principal', 'audience_public_id' => str_repeat('b', 32),
        ], 7);
        $pdo->prepare('UPDATE managed_delivery_intent_outbox SET signing_contract_hash=? WHERE delivery_id=?')->execute([str_repeat('0', 64), $secondId]);
        $transportCalls = 0;
        $failed = $sender->deliverDeliveryId($pdo, $secondId, static function () use (&$transportCalls): array {
            $transportCalls++;
            return ['status' => 202, 'body' => '{"receiptId":"must_not_arrive","status":"accepted"}'];
        });
        self::assertSame(1, $failed['retrying']);
        self::assertSame(0, $transportCalls);
        self::assertSame('pinned_contract_unavailable', $pdo->query("SELECT last_error_code FROM managed_delivery_intent_outbox WHERE delivery_id='{$secondId}'")->fetchColumn());
    }

    public function testDeadLetteredRevocationCanBeExplicitlyRequeuedWithoutCreatingASecondRow(): void
    {
        $pdo = $this->database();
        $service = new ManagedDeliveryService();
        $sender = new ManagedDeliveryIntentSender();
        $provisionId = '17171717-1717-4717-8717-171717171717';
        $revokeId = '18181818-1818-4818-8818-181818181818';
        $service->queue($pdo, [
            'delivery_id' => $provisionId, 'scope_type' => 'project', 'scope_public_id' => str_repeat('a', 32),
            'audience_type' => 'principal', 'audience_public_id' => str_repeat('b', 32),
        ], 7);
        $sender->deliverDeliveryId($pdo, $provisionId, static fn(): array => ['status' => 202, 'body' => '{"receiptId":"receipt_01","status":"accepted"}']);
        $service->queueRevocation($pdo, $provisionId, $revokeId, 7);
        $dead = $sender->deliverDeliveryId($pdo, $revokeId, static fn(): array => ['status' => 400, 'body' => '{}']);
        self::assertSame(1, $dead['dead_lettered']);
        self::assertNotNull($pdo->query("SELECT dead_lettered_at FROM managed_delivery_intent_outbox WHERE delivery_id='{$revokeId}'")->fetchColumn());

        $pdo->exec("UPDATE app_config SET config_value='0' WHERE config_key='managed_delivery_enabled'");
        $service->requeueRevocation($pdo, $revokeId);
        self::assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM managed_delivery_intent_outbox WHERE target_delivery_id='{$provisionId}' AND intent_type='revoke'")->fetchColumn());
        self::assertSame(0, (int)$pdo->query("SELECT attempts FROM managed_delivery_intent_outbox WHERE delivery_id='{$revokeId}'")->fetchColumn());
        self::assertNull($pdo->query("SELECT dead_lettered_at FROM managed_delivery_intent_outbox WHERE delivery_id='{$revokeId}'")->fetchColumn());
        $retried = $sender->deliverDeliveryId($pdo, $revokeId, static fn(): array => ['status' => 202, 'body' => '{"receiptId":"revoke_receipt_01","status":"accepted"}'], true);
        self::assertSame(1, $retried['accepted']);
        self::assertNotNull($pdo->query("SELECT revoked_at FROM managed_delivery_intent_outbox WHERE delivery_id='{$provisionId}'")->fetchColumn());
        try {
            $service->requeueRevocation($pdo, $revokeId);
            self::fail('An accepted revocation must not be requeued.');
        } catch (DomainException $error) {
            self::assertStringContainsString('no longer eligible', $error->getMessage());
        }
    }

    public function testBatchRuntimeDeadlineStopsBeforeClaimingAnotherIntent(): void
    {
        $pdo = $this->database();
        $service = new ManagedDeliveryService();
        foreach (['13131313-1313-4313-8313-131313131313', '14141414-1414-4414-8414-141414141414'] as $id) {
            $service->queue($pdo, [
                'delivery_id' => $id, 'scope_type' => 'project', 'scope_public_id' => str_repeat('a', 32),
                'audience_type' => 'principal', 'audience_public_id' => str_repeat('b', 32),
            ], 7);
        }
        $summary = (new ManagedDeliveryIntentSender())->deliverDue($pdo, 2, static function (): array {
            usleep(1_100_000);
            return ['status' => 202, 'body' => '{"receiptId":"receipt_01","status":"accepted"}'];
        }, 1);
        self::assertSame(1, $summary['processed']);
        self::assertSame(1, $summary['accepted']);
        self::assertSame(0, (int)$pdo->query("SELECT attempts FROM managed_delivery_intent_outbox WHERE delivery_id='14141414-1414-4414-8414-141414141414'")->fetchColumn());
    }

    private function database(bool $enabled = true): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(<<<'SQL'
CREATE TABLE app_config(organization_id INTEGER NOT NULL,config_key TEXT NOT NULL,config_value TEXT NOT NULL,PRIMARY KEY(organization_id,config_key));
CREATE TABLE portal_integration_profiles(id INTEGER PRIMARY KEY,application_key TEXT,display_label TEXT,enabled INTEGER,delivery_enabled INTEGER,delivery_key_id TEXT,delivery_previous_key_id TEXT,delivery_previous_valid_until TEXT,delivery_credentials_enc TEXT,delivery_timeout_seconds INTEGER,delivery_max_attempts INTEGER);
CREATE TABLE organizations(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT);
CREATE TABLE organization_departments(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT);
CREATE TABLE clients(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,archived INTEGER,deleted_at TEXT);
CREATE TABLE projects(id INTEGER PRIMARY KEY,public_id TEXT,name TEXT,status TEXT);
CREATE TABLE portal_principals(id INTEGER PRIMARY KEY,public_id TEXT,enabled INTEGER,revoked_at TEXT);
CREATE TABLE managed_delivery_intent_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,delivery_id TEXT NOT NULL UNIQUE,intent_type TEXT NOT NULL DEFAULT 'provision',target_delivery_id TEXT,integration_profile_id INTEGER NOT NULL,destination_url TEXT NOT NULL,pinned_application_key TEXT NOT NULL,signing_key_id TEXT NOT NULL,signing_contract_hash TEXT NOT NULL,delivery_timeout_seconds INTEGER NOT NULL,delivery_max_attempts INTEGER NOT NULL,actor_user_id INTEGER,scope_type TEXT NOT NULL,scope_public_id TEXT NOT NULL,audience_type TEXT NOT NULL,audience_public_id TEXT NOT NULL,access_mode TEXT NOT NULL DEFAULT 'portal',request_fingerprint TEXT NOT NULL,payload_json TEXT NOT NULL,attempts INTEGER NOT NULL DEFAULT 0,next_attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,claim_token TEXT,claimed_at TEXT,delivered_at TEXT,dead_lettered_at TEXT,last_http_status INTEGER,last_error_code TEXT,receipt_id TEXT,revoked_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
SQL);
        require_once dirname(__DIR__, 2) . '/src/utils/crypto.php';
        $credentials = crypto_encrypt(json_encode(['currentSecret' => str_repeat('s', 32), 'previousSecret' => '', 'authHeaders' => ['CF-Access-Client-Id' => 'opaque-id', 'CF-Access-Client-Secret' => 'opaque-secret']], JSON_THROW_ON_ERROR));
        $insert = $pdo->prepare('INSERT INTO portal_integration_profiles(id,application_key,display_label,enabled,delivery_enabled,delivery_key_id,delivery_credentials_enc,delivery_timeout_seconds,delivery_max_attempts) VALUES(1,?,?,?,?,?,?,?,?)');
        $insert->execute(['project-alpha', 'Ops', 1, 1, 'ops-v1', $credentials, 5, 3]);
        $configs = [
            ManagedDeliveryService::ENABLED_KEY => $enabled ? '1' : '0',
            ManagedDeliveryService::URL_KEY => 'https://ops.example/api/internal/project-alpha/delivery-intents',
            ManagedDeliveryService::PROFILE_KEY => '1',
            ManagedDeliveryService::GUEST_KEY => '0',
        ];
        $save = $pdo->prepare('INSERT INTO app_config VALUES(0,?,?)');
        foreach ($configs as $key => $value) $save->execute([$key, $value]);
        $pdo->exec("INSERT INTO projects VALUES(1,'" . str_repeat('a', 32) . "','Johnson Road','active')");
        $pdo->exec("INSERT INTO portal_principals VALUES(1,'" . str_repeat('b', 32) . "',1,NULL)");
        return $pdo;
    }

    /** @return array<string,string> */
    private function headers(array $headers): array
    {
        $out = [];
        foreach ($headers as $header) {
            [$name, $value] = explode(':', $header, 2);
            $out[strtolower($name)] = trim($value);
        }
        return $out;
    }
}
