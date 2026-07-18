<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\ExternalOpsIntegrationService;
use App\Services\ExternalOpsOutboxSender;
use App\Services\ExternalOpsConfigService;
use App\Services\OperationsPlanningService;
use PDO;
use PHPUnit\Framework\TestCase;

final class ExternalOpsIntegrationTest extends TestCase
{
    private PDO $pdo;

    public function testSchemaAndSettingsRemainExplicitlyOptIn(): void
    {
        $root = dirname(__DIR__, 2);
        $baseline = (string)file_get_contents($root . '/database/baseline.sql');
        $migration = (string)file_get_contents($root . '/database/migrations/0049_external_ops_integration.sql');
        foreach (['application_entitlements', 'application_entitlement_business_units', 'integration_outbox', 'operations', 'operation_assignments', 'tasks'] as $table) {
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS ' . $table, $baseline);
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS ' . $table, $migration);
        }

        $config = (string)file_get_contents($root . '/src/services/ExternalOpsConfigService.php');
        $registry = (string)file_get_contents($root . '/src/views/pages/settings/registry.php');
        $handler = (string)file_get_contents($root . '/src/controllers/settings/external_ops_handler.php');
        $page = (string)file_get_contents($root . '/src/views/pages/settings/external-ops.php');
        $compose = (string)file_get_contents($root . '/docker-compose.yml');
        $envExample = (string)file_get_contents($root . '/config/.env.example');
        self::assertStringContainsString("'external_ops_enabled'", $config);
        self::assertStringContainsString("'external_ops_credentials_enc'", $config);
        self::assertStringContainsString("'title' => 'Custom integrations'", $registry);
        self::assertStringContainsString("'permission' => 'settings.manage'", $registry);
        self::assertStringContainsString("\$action === 'save-config'", $handler);
        self::assertStringContainsString('csrf_validate()', $handler);
        self::assertStringContainsString('name="access_client_secret"', $page);
        self::assertStringContainsString('name="hmac_secret"', $page);
        self::assertStringNotContainsString('name="application_key"', $page);
        self::assertStringNotContainsString('name="role_key"', $page);
        self::assertStringContainsString("'role-admin'", $migration);
        self::assertStringContainsString("'role-admin'", $baseline);
        self::assertStringContainsString('saveAccountAccess(', $handler);
        foreach (['accounts.php', 'account-edit.php'] as $accountPage) {
            $accountForm = (string)file_get_contents($root . '/src/views/pages/auth/' . $accountPage);
            self::assertStringContainsString('name="external_ops_enabled"', $accountForm);
            self::assertStringContainsString('LTDS Operations access', $accountForm);
            self::assertStringContainsString('resetExternalOpsDefault', $accountForm);
            self::assertStringContainsString('externalOpsToggle.checked = !!selectedRoleMeta().isAdmin', $accountForm);
        }
        self::assertStringNotContainsString('OPS_SYNC_', $compose);
        self::assertStringNotContainsString('OPS_SYNC_', $envExample);
    }

    public function testUiConfigurationEncryptsAndReloadsCredentials(): void
    {
        $previousKey = getenv('APP_ENCRYPTION_KEY');
        putenv('APP_ENCRYPTION_KEY=' . base64_encode(str_repeat('K', 32)));
        try {
            $service = new ExternalOpsConfigService();
            $saved = $service->save($this->pdo, [
                'enabled' => '1',
                'label' => 'LTDS Operations',
                'application_key' => 'ltds_ops',
                'webhook_url' => 'https://ops.example.test/api/provisioning/events',
                'access_client_id' => 'access-client-id',
                'access_client_secret' => 'access-client-secret',
                'hmac_secret' => str_repeat('h', 40),
                'timeout_seconds' => '20',
                'max_attempts' => '8',
            ]);

            self::assertTrue($saved['enabled']);
            self::assertSame('LTDS Operations', $saved['label']);
            self::assertSame('ltds_ops', $saved['application_key']);
            self::assertSame('access-client-id', $saved['access_client_id']);
            self::assertSame('access-client-secret', $saved['access_client_secret']);
            self::assertSame(str_repeat('h', 40), $saved['hmac_secret']);
            self::assertSame(20, $saved['timeout_seconds']);
            self::assertSame(8, $saved['max_attempts']);

            $encrypted = (string)$this->pdo->query(
                "SELECT config_value FROM app_config WHERE config_key='external_ops_credentials_enc'"
            )->fetchColumn();
            self::assertStringStartsWith('enc::', $encrypted);
            self::assertStringNotContainsString('access-client-secret', $encrypted);
            self::assertStringNotContainsString(str_repeat('h', 40), $encrypted);

            $retained = $service->save($this->pdo, [
                'enabled' => '1',
                'label' => 'LTDS Operations',
                'application_key' => 'ltds_ops',
                'webhook_url' => 'https://ops.example.test/api/provisioning/events',
                'timeout_seconds' => '20',
                'max_attempts' => '8',
            ]);
            self::assertSame('access-client-secret', $retained['access_client_secret']);
        } finally {
            if ($previousKey === false) {
                putenv('APP_ENCRYPTION_KEY');
            } else {
                putenv('APP_ENCRYPTION_KEY=' . $previousKey);
            }
        }
    }

    public function testApiServicePrincipalRetainsReadScopeWithoutAnAdminSession(): void
    {
        require_once dirname(__DIR__, 2) . '/src/utils/acl.php';
        $previousPrincipal = $GLOBALS['pa_service_principal'] ?? null;
        $previousUser = $_SESSION['user'] ?? null;
        $GLOBALS['pa_service_principal'] = [
            'type' => 'api_key',
            'api_key_id' => 7,
            'name' => 'Ops sync',
            'scopes' => ['ops.sync.read'],
        ];
        unset($_SESSION['user']);

        try {
            self::assertSame(['', []], scope_clause($this->pdo, 'projects', 0));
        } finally {
            if ($previousPrincipal === null) {
                unset($GLOBALS['pa_service_principal']);
            } else {
                $GLOBALS['pa_service_principal'] = $previousPrincipal;
            }
            if ($previousUser === null) {
                unset($_SESSION['user']);
            } else {
                $_SESSION['user'] = $previousUser;
            }
        }
    }

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        foreach ([
            'CREATE TABLE app_config (
                id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id INTEGER DEFAULT 0,
                config_key TEXT, config_value TEXT, UNIQUE(organization_id, config_key)
            )',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, username TEXT, role TEXT, is_disabled INTEGER, deleted_at TEXT)',
            'CREATE TABLE worker_profiles (id INTEGER PRIMARY KEY, user_id INTEGER, display_name TEXT, status TEXT)',
            'CREATE TABLE worker_business_units (
                worker_profile_id INTEGER, business_unit_id INTEGER, ends_at TEXT,
                PRIMARY KEY(worker_profile_id,business_unit_id)
            )',
            'CREATE TABLE business_units (id INTEGER PRIMARY KEY, name TEXT, is_active INTEGER)',
            'CREATE TABLE application_entitlements (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, application_key TEXT, enabled INTEGER,
                role_key TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, application_key)
            )',
            'CREATE TABLE application_entitlement_business_units (
                entitlement_id INTEGER, business_unit_id INTEGER, PRIMARY KEY(entitlement_id, business_unit_id)
            )',
            'CREATE TABLE integration_outbox (
                id INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT UNIQUE, integration_key TEXT, event_type TEXT,
                schema_version INTEGER, payload_json TEXT, occurred_at TEXT, attempts INTEGER DEFAULT 0,
                next_attempt_at TEXT, delivered_at TEXT, last_error TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE projects (id INTEGER PRIMARY KEY, name TEXT, status TEXT)',
            'CREATE TABLE operations (
                id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, business_unit_id INTEGER, title TEXT,
                status TEXT, scheduled_start_at TEXT, scheduled_end_at TEXT, location TEXT, notes TEXT,
                created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE operation_assignments (
                operation_id INTEGER, user_id INTEGER, assignment_role TEXT, assigned_by INTEGER,
                assigned_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(operation_id, user_id)
            )',
            'CREATE TABLE tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT, operation_id INTEGER, project_id INTEGER,
                business_unit_id INTEGER, assignee_user_id INTEGER, title TEXT, status TEXT, due_at TEXT,
                notes TEXT, created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )',
        ] as $statement) {
            $this->pdo->exec($statement);
        }

        $this->pdo->exec("INSERT INTO users VALUES
            (1,'admin@example.test','admin','admin',0,NULL),
            (2,'OPERATOR@EXAMPLE.TEST','operator','employee',0,NULL),
            (3,'owner@example.test','owner','owner',0,NULL)");
        $this->pdo->exec("INSERT INTO worker_profiles VALUES
            (20,2,'Field Operator','active'),
            (21,3,'Business Owner','active')");
        $this->pdo->exec("INSERT INTO business_units VALUES
            (30,'North Division',1),
            (31,'South Division',1),
            (32,'Retired Division',0)");
        $this->pdo->exec("INSERT INTO worker_business_units VALUES
            (20,30,NULL),
            (21,31,NULL)");
        $this->pdo->exec("INSERT INTO projects VALUES (40,'Aerial Survey','active')");
    }

    public function testEntitlementStateIsQueuedWithoutCredentialsAndOwnerMapsToOperator(): void
    {
        $result = (new ExternalOpsIntegrationService())->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1);

        self::assertGreaterThan(0, $result['entitlement_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $result['event_id']);
        self::assertSame([30], array_map('intval', $this->pdo->query(
            'SELECT business_unit_id FROM application_entitlement_business_units'
        )->fetchAll(PDO::FETCH_COLUMN)));

        $payload = json_decode((string)$this->pdo->query(
            'SELECT payload_json FROM integration_outbox ORDER BY id DESC LIMIT 1'
        )->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('operator@example.test', $payload['user']['email']);
        self::assertSame('Field Operator', $payload['user']['display_name']);
        self::assertTrue($payload['user']['active']);
        self::assertTrue($payload['entitlement']['enabled']);
        self::assertSame('role-operator', $payload['entitlement']['role_key']);
        self::assertSame([30], $payload['entitlement']['business_unit_ids']);
        self::assertArrayNotHasKey('password', $payload['user']);

    }

    public function testDisabledUserProducesEffectiveRevocation(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1);
        $this->pdo->exec('UPDATE users SET is_disabled=1 WHERE id=2');
        $service->enqueueCurrentState($this->pdo, 2, 'ltds_ops', 'user.changed');

        $payload = json_decode((string)$this->pdo->query(
            'SELECT payload_json FROM integration_outbox ORDER BY id DESC LIMIT 1'
        )->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($payload['user']['active']);
        self::assertFalse($payload['entitlement']['enabled']);
    }

    public function testAccountAccessDerivesAdminAndWorkerScopesWithoutTreatingOwnerAsAdmin(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->saveAccountAccess($this->pdo, 1, 'ltds_ops', true, 1);
        $adminPayload = json_decode((string)$this->pdo->query(
            'SELECT payload_json FROM integration_outbox ORDER BY id DESC LIMIT 1'
        )->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($adminPayload['entitlement']['enabled']);
        self::assertSame('role-admin', $adminPayload['entitlement']['role_key']);
        self::assertSame([], $adminPayload['entitlement']['business_unit_ids']);

        $service->saveAccountAccess($this->pdo, 3, 'ltds_ops', true, 1);
        $ownerPayload = json_decode((string)$this->pdo->query(
            'SELECT payload_json FROM integration_outbox ORDER BY id DESC LIMIT 1'
        )->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('role-operator', $ownerPayload['entitlement']['role_key']);
        self::assertSame([31], $ownerPayload['entitlement']['business_unit_ids']);
    }

    public function testAccountAccessCheckboxAndDisabledStateProduceRevocations(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', false, 1);
        $uncheckedPayload = json_decode((string)$this->pdo->query(
            'SELECT payload_json FROM integration_outbox ORDER BY id DESC LIMIT 1'
        )->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($uncheckedPayload['entitlement']['enabled']);

        $this->pdo->exec('UPDATE users SET is_disabled=1 WHERE id=2');
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1);
        $disabledPayload = json_decode((string)$this->pdo->query(
            'SELECT payload_json FROM integration_outbox ORDER BY id DESC LIMIT 1'
        )->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($disabledPayload['entitlement']['enabled']);
        self::assertSame([30], $disabledPayload['entitlement']['business_unit_ids']);
        self::assertSame(1, (int)$this->pdo->query(
            "SELECT enabled FROM application_entitlements WHERE user_id=2 AND application_key='ltds_ops'"
        )->fetchColumn(), 'PA account inactivity must not erase the explicit Ops ACL checkbox.');
    }

    public function testAccountFormSavePreservesManualNonAdminBusinessUnitScope(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1, [31]);
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1);

        $payload = json_decode((string)$this->pdo->query(
            'SELECT payload_json FROM integration_outbox ORDER BY id DESC LIMIT 1'
        )->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('role-operator', $payload['entitlement']['role_key']);
        self::assertSame([31], $payload['entitlement']['business_unit_ids']);
    }

    public function testPromotionAndDemotionDeriveRoleAndPreserveFinalAclAndScope(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1, [31]);

        $this->pdo->exec("UPDATE users SET role='admin' WHERE id=2");
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1);
        $promoted = $this->latestPayload();
        self::assertSame('role-admin', $promoted['entitlement']['role_key']);
        self::assertSame([], $promoted['entitlement']['business_unit_ids']);

        $this->pdo->exec("UPDATE users SET role='employee' WHERE id=2");
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1);
        $demotedEnabled = $this->latestPayload();
        self::assertTrue($demotedEnabled['entitlement']['enabled']);
        self::assertSame('role-operator', $demotedEnabled['entitlement']['role_key']);
        self::assertSame([31], $demotedEnabled['entitlement']['business_unit_ids']);

        $this->pdo->exec("UPDATE users SET role='admin' WHERE id=2");
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1);
        $this->pdo->exec("UPDATE users SET role='employee' WHERE id=2");
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', false, 1);
        $demotedRevoked = $this->latestPayload();
        self::assertFalse($demotedRevoked['entitlement']['enabled']);
        self::assertSame('role-operator', $demotedRevoked['entitlement']['role_key']);
        self::assertSame([31], $demotedRevoked['entitlement']['business_unit_ids']);
    }

    public function testAdminAclCanBeDisabledWithoutAllowingRoleOverride(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->saveAccountAccess($this->pdo, 1, 'ltds_ops', false, 1, [30]);
        $payload = $this->latestPayload();

        self::assertFalse($payload['entitlement']['enabled']);
        self::assertSame('role-admin', $payload['entitlement']['role_key']);
        self::assertSame([], $payload['entitlement']['business_unit_ids']);
    }

    public function testTerminatedWorkerProducesEffectiveRevocation(): void
    {
        $service = new ExternalOpsIntegrationService();
        $service->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1);
        $this->pdo->exec("UPDATE worker_profiles SET status='terminated' WHERE user_id=2");
        $service->enqueueCurrentState($this->pdo, 2, 'ltds_ops', 'user.changed');

        $payload = json_decode((string)$this->pdo->query(
            'SELECT payload_json FROM integration_outbox ORDER BY id DESC LIMIT 1'
        )->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($payload['user']['active']);
        self::assertFalse($payload['entitlement']['enabled']);
    }

    public function testOutboxDeliveryUsesAccessAndHmacHeaders(): void
    {
        (new ExternalOpsIntegrationService())->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1);
        $captured = [];
        $config = [
            'enabled' => true,
            'application_key' => 'ltds_ops',
            'webhook_url' => 'https://ops.example.test/api/provisioning/events',
            'access_client_id' => 'client-id',
            'access_client_secret' => 'client-secret',
            'hmac_secret' => 'hmac-secret',
            'timeout_seconds' => 5,
            'max_attempts' => 3,
        ];

        $summary = (new ExternalOpsOutboxSender())->deliverDue(
            $this->pdo,
            $config,
            1,
            static function (string $url, array $headers, string $body, int $timeout) use (&$captured): array {
                $captured = compact('url', 'headers', 'body', 'timeout');
                return ['status' => 204];
            }
        );

        self::assertSame(['processed' => 1, 'delivered' => 1, 'failed' => 0], $summary);
        self::assertSame($config['webhook_url'], $captured['url']);
        self::assertContains('CF-Access-Client-Id: client-id', $captured['headers']);
        self::assertContains('CF-Access-Client-Secret: client-secret', $captured['headers']);
        $timestampHeader = $this->headerValue($captured['headers'], 'X-PA-Timestamp: ');
        $signatureHeader = $this->headerValue($captured['headers'], 'X-PA-Signature: ');
        self::assertSame(
            'sha256=' . hash_hmac('sha256', $timestampHeader . '.' . $captured['body'], 'hmac-secret'),
            $signatureHeader
        );
        self::assertNotFalse($this->pdo->query('SELECT delivered_at FROM integration_outbox')->fetchColumn());
    }

    public function testOutboxFailureIsRetriedWithAStoredBoundedError(): void
    {
        (new ExternalOpsIntegrationService())->saveAccountAccess($this->pdo, 2, 'ltds_ops', true, 1);
        $before = gmdate('Y-m-d H:i:s.u');
        $summary = (new ExternalOpsOutboxSender())->deliverDue($this->pdo, [
            'enabled' => true,
            'application_key' => 'ltds_ops',
            'webhook_url' => 'https://ops.example.test/api/provisioning/events',
            'access_client_id' => 'client-id',
            'access_client_secret' => 'client-secret',
            'hmac_secret' => 'hmac-secret',
            'timeout_seconds' => 5,
            'max_attempts' => 3,
        ], 1, static fn(): array => ['status' => 503, 'body' => "temporary\nerror"]);

        self::assertSame(['processed' => 1, 'delivered' => 0, 'failed' => 1], $summary);
        $row = $this->pdo->query(
            'SELECT attempts,next_attempt_at,delivered_at,last_error FROM integration_outbox LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int)$row['attempts']);
        self::assertNull($row['delivered_at']);
        self::assertGreaterThan($before, (string)$row['next_attempt_at']);
        self::assertSame('HTTP 503: temporary error', $row['last_error']);
    }

    public function testOperationsAndTasksRemainOwnedByProjectAlpha(): void
    {
        $service = new OperationsPlanningService();
        $operationId = $service->saveOperation($this->pdo, [
            'project_id' => 40,
            'business_unit_id' => 30,
            'title' => 'Capture flight',
            'status' => 'scheduled',
            'scheduled_start_at' => '2026-07-20 09:00:00',
            'scheduled_end_at' => '2026-07-20 11:00:00',
        ], [2], 1);
        $taskId = $service->saveTask($this->pdo, [
            'operation_id' => $operationId,
            'project_id' => 40,
            'business_unit_id' => 30,
            'assignee_user_id' => 2,
            'title' => 'Upload imagery',
            'status' => 'todo',
            'due_at' => '2026-07-20 17:00:00',
        ], 1);

        self::assertGreaterThan(0, $operationId);
        self::assertGreaterThan(0, $taskId);
        self::assertSame('2', (string)$this->pdo->query(
            'SELECT user_id FROM operation_assignments WHERE operation_id=' . $operationId
        )->fetchColumn());
        self::assertSame((string)$operationId, (string)$this->pdo->query(
            'SELECT operation_id FROM tasks WHERE id=' . $taskId
        )->fetchColumn());
    }

    /** @param list<string> $headers */
    private function headerValue(array $headers, string $prefix): string
    {
        foreach ($headers as $header) {
            if (str_starts_with($header, $prefix)) {
                return substr($header, strlen($prefix));
            }
        }
        self::fail('Missing header: ' . $prefix);
    }

    /** @return array<string,mixed> */
    private function latestPayload(): array
    {
        return json_decode((string)$this->pdo->query(
            'SELECT payload_json FROM integration_outbox ORDER BY id DESC LIMIT 1'
        )->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    }
}
