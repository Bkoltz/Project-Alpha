<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\OpsSnapshotService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class OpsSnapshotApiTest extends TestCase
{
    private string $root;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
        $this->seedData();
    }

    public function testSnapshotPaginatesEveryCollectionWithoutExposingSecrets(): void
    {
        $service = new OpsSnapshotService();
        $generatedAt = new DateTimeImmutable('2026-07-16T17:00:00-05:00');

        $first = $service->snapshot($this->pdo, 1, 1, $generatedAt);

        self::assertSame([
            'generated_at', 'users', 'business_units', 'worker_business_units', 'clients',
            'organizations', 'projects', 'project_assignments', 'service_locations',
            'application_entitlements', 'operations', 'operation_assignments', 'tasks', 'calendar_events',
            'has_more', 'next_page',
        ], array_keys($first));
        self::assertSame('2026-07-16T22:00:00.000000Z', $first['generated_at']);
        self::assertTrue($first['has_more']);
        self::assertSame(2, $first['next_page']);
        self::assertSame(1, $first['users'][0]['id']);
        self::assertFalse($first['users'][0]['is_disabled']);
        self::assertArrayNotHasKey('password_hash', $first['users'][0]);
        self::assertArrayNotHasKey('auth_version', $first['users'][0]);
        self::assertArrayNotHasKey('public_project_token', $first['projects'][0]);
        self::assertArrayNotHasKey('pay_rate_override', $first['project_assignments'][0]);
        self::assertSame('01234', $first['service_locations'][0]['postal_code']);
        self::assertSame('ltds_ops', $first['application_entitlements'][0]['application_key']);
        self::assertFalse($first['application_entitlements'][0]['enabled']);
        self::assertSame('role-operator', $first['application_entitlements'][0]['role_key']);
        self::assertSame([30], $first['application_entitlements'][0]['business_unit_ids']);
        self::assertSame(100, $first['operations'][0]['id']);
        self::assertSame(2, $first['operation_assignments'][0]['user_id']);
        self::assertSame(110, $first['tasks'][0]['id']);
        self::assertContains($first['calendar_events'][0]['source_type'], ['contract', 'invoice', 'operation', 'task']);

        $second = $service->snapshot($this->pdo, 2, 1, $generatedAt);

        self::assertTrue($second['has_more']);
        self::assertSame(3, $second['next_page']);
        self::assertSame(2, $second['users'][0]['id']);
        self::assertTrue($second['users'][0]['is_disabled']);
        self::assertSame([], $second['business_units']);
        self::assertSame([], $second['projects']);

        $fourth = $service->snapshot($this->pdo, 4, 1, $generatedAt);
        self::assertFalse($fourth['has_more']);
        self::assertNull($fourth['next_page']);
    }

    public function testPaginationBoundsAreEnforced(): void
    {
        $service = new OpsSnapshotService();

        $this->expectException(\InvalidArgumentException::class);
        $service->snapshot($this->pdo, 0, 100);
    }

    public function testOpsSyncScopeAndCleanRouteUseExistingApiKeyPipeline(): void
    {
        require_once $this->root . '/src/utils/api_scopes.php';

        self::assertSame('ops.sync.read', api_scope_endpoint_map()['api-ops-snapshot']);
        self::assertTrue(api_key_has_scope('ops.sync.read', 'ops.sync.read'));
        self::assertFalse(api_key_has_scope('projects.read', 'ops.sync.read'));
        self::assertTrue(api_key_has_scope('full', 'ops.sync.read'));

        $front = (string)file_get_contents($this->root . '/public/index.php');
        self::assertStringContainsString("'/api/v1/ops/snapshot' => 'api-ops-snapshot'", $front);
        self::assertStringContainsString("\$_GET['_ops_snapshot_page'] = \$opsSnapshotPage", $front);
        self::assertStringContainsString("'api-ops-snapshot'           => __DIR__ . '/../src/controllers/api/ops_snapshot.php'", $front);
        self::assertStringContainsString('api_require_key([$requiredApiScope])', $front);
    }

    private function createSchema(): void
    {
        $schema = [
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY, email TEXT, username TEXT, role TEXT, is_disabled INTEGER,
                deleted_at TEXT, created_at TEXT, updated_at TEXT, password_hash TEXT, auth_version INTEGER
            )',
            'CREATE TABLE worker_profiles (
                id INTEGER PRIMARY KEY, user_id INTEGER, display_name TEXT, relationship_type TEXT, status TEXT
            )',
            'CREATE TABLE business_units (
                id INTEGER PRIMARY KEY, name TEXT, code TEXT, description TEXT, is_active INTEGER,
                created_by INTEGER, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE worker_business_units (
                worker_profile_id INTEGER, business_unit_id INTEGER, is_lead INTEGER,
                assigned_by INTEGER, assigned_at TEXT, ends_at TEXT
            )',
            'CREATE TABLE clients (
                id INTEGER PRIMARY KEY, name TEXT, email TEXT, phone TEXT, address_line1 TEXT, address_line2 TEXT,
                city TEXT, state TEXT, postal_code TEXT, country TEXT, organization_id INTEGER, client_type TEXT,
                archived INTEGER, deleted_at TEXT, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE organizations (
                id INTEGER PRIMARY KEY, name TEXT, address_line1 TEXT, address_line2 TEXT, city TEXT, state TEXT,
                postal_code TEXT, country TEXT, link_strategy TEXT, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE projects (
                id INTEGER PRIMARY KEY, client_id INTEGER, parent_id INTEGER, organization_id INTEGER,
                department_id INTEGER, created_by INTEGER, name TEXT, description TEXT, status TEXT,
                start_date TEXT, end_date TEXT, estimated_start TEXT, estimated_end TEXT,
                created_at TEXT, updated_at TEXT, public_project_token TEXT
            )',
            'CREATE TABLE project_assignments (
                id INTEGER PRIMARY KEY, project_id INTEGER, user_id INTEGER, assigned_at TEXT, ends_at TEXT,
                created_by INTEGER, created_at TEXT, updated_at TEXT, pay_rate_override TEXT
            )',
            'CREATE TABLE service_locations (
                id INTEGER PRIMARY KEY, organization_id INTEGER, client_id INTEGER, project_id INTEGER,
                address_id INTEGER, name TEXT, address_line1 TEXT, address_line2 TEXT, city TEXT, state TEXT,
                postal_code TEXT, country TEXT, archived INTEGER, created_by INTEGER, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE application_entitlements (
                id INTEGER PRIMARY KEY, user_id INTEGER, application_key TEXT, enabled INTEGER, role_key TEXT,
                created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE application_entitlement_business_units (
                entitlement_id INTEGER, business_unit_id INTEGER
            )',
            'CREATE TABLE operations (
                id INTEGER PRIMARY KEY, project_id INTEGER, business_unit_id INTEGER, title TEXT,
                status TEXT, scheduled_start_at TEXT, scheduled_end_at TEXT, location TEXT, notes TEXT, created_by INTEGER,
                created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE operation_assignments (
                operation_id INTEGER, user_id INTEGER, assignment_role TEXT, assigned_by INTEGER, assigned_at TEXT
            )',
            'CREATE TABLE tasks (
                id INTEGER PRIMARY KEY, operation_id INTEGER, project_id INTEGER, business_unit_id INTEGER,
                assignee_user_id INTEGER, title TEXT, status TEXT, due_at TEXT, notes TEXT,
                created_by INTEGER, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE contracts (
                id INTEGER PRIMARY KEY, project_id INTEGER, scheduled_date TEXT, start_date TEXT, end_date TEXT
            )',
            'CREATE TABLE invoices (
                id INTEGER PRIMARY KEY, project_id INTEGER, due_date TEXT
            )',
        ];

        foreach ($schema as $statement) {
            $this->pdo->exec($statement);
        }
    }

    private function seedData(): void
    {
        $this->pdo->exec("INSERT INTO users VALUES
            (1,'beau@example.test','beau','owner',0,NULL,'2026-01-01','2026-07-01','secret',1),
            (2,'kollins@example.test','kollins','employee',1,NULL,'2026-01-02','2026-07-02','secret',1)");
        $this->pdo->exec("INSERT INTO worker_profiles VALUES
            (10,1,'Beau','owner','active'),(20,2,'Kollins','employee','inactive')");
        $this->pdo->exec("INSERT INTO business_units VALUES
            (30,'Chippewa Falls','CF','Chippewa Falls division',1,1,'2026-01-01','2026-07-01')");
        $this->pdo->exec("INSERT INTO worker_business_units VALUES
            (20,30,1,1,'2026-01-01',NULL)");
        $this->pdo->exec("INSERT INTO organizations VALUES
            (40,'Client Org','1 Main',NULL,'Chippewa Falls','WI','54729','US','overall_folder','2026-01-01','2026-07-01')");
        $this->pdo->exec("INSERT INTO clients VALUES
            (50,'Client','client@example.test','555-0100','1 Main',NULL,'Chippewa Falls','WI','54729','US',40,'business',0,NULL,'2026-01-01','2026-07-01')");
        $this->pdo->exec("INSERT INTO projects VALUES
            (60,50,NULL,40,NULL,1,'Aerial Survey','Survey description','active','2026-07-01',NULL,NULL,NULL,'2026-01-01','2026-07-01','do-not-export')");
        $this->pdo->exec("INSERT INTO project_assignments VALUES
            (70,60,2,'2026-07-01',NULL,1,'2026-07-01','2026-07-01','100.00')");
        $this->pdo->exec("INSERT INTO service_locations VALUES
            (80,40,50,60,NULL,'Flight Site','100 Site Rd',NULL,'Eau Claire','WI','01234','US',0,1,'2026-01-01','2026-07-01')");
        $this->pdo->exec("INSERT INTO application_entitlements VALUES
            (90,2,'ltds_ops',1,'role-operator','2026-07-01','2026-07-01')");
        $this->pdo->exec("INSERT INTO application_entitlement_business_units VALUES (90,30)");
        $this->pdo->exec("INSERT INTO operations VALUES
            (100,60,30,'Aerial capture','scheduled','2026-07-20 14:00:00','2026-07-20 16:00:00','Flight Site','Capture imagery',1,'2026-07-01','2026-07-01')");
        $this->pdo->exec("INSERT INTO operation_assignments VALUES
            (100,2,'operator',1,'2026-07-01')");
        $this->pdo->exec("INSERT INTO tasks VALUES
            (110,100,60,30,2,'Upload imagery','todo','2026-07-21 17:00:00','Upload processed files',1,'2026-07-01','2026-07-01')");
        $this->pdo->exec("INSERT INTO contracts VALUES
            (120,60,'2026-07-18','2026-07-19','2026-07-22')");
        $this->pdo->exec("INSERT INTO invoices VALUES (130,60,'2026-07-30')");
    }
}
