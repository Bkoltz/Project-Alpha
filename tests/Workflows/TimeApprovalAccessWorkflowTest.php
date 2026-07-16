<?php

declare(strict_types=1);

use App\Services\TimeApprovalPolicy;
use App\Services\TimeReviewQueueService;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/utils/acl.php';

final class TimeApprovalAccessWorkflowTest extends TestCase
{
    public function testQueueCountAndListShareSelfReviewAndBusinessUnitPolicy(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', $parts), -1);
        foreach ([
            'CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, username TEXT, email TEXT, deleted_at TEXT, is_disabled INTEGER DEFAULT 0)',
            'CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, organization_id INTEGER, is_system INTEGER)',
            'CREATE TABLE role_permissions (role_id INTEGER, permission TEXT, allowed INTEGER)',
            'CREATE TABLE user_permissions_overrides (user_id INTEGER, organization_id INTEGER, permission TEXT, allowed INTEGER)',
            'CREATE TABLE app_config (organization_id INTEGER, config_key TEXT, config_value TEXT)',
            'CREATE TABLE worker_profiles (id INTEGER PRIMARY KEY, user_id INTEGER, relationship_type TEXT, status TEXT, relationship_review_required INTEGER DEFAULT 0)',
            'CREATE TABLE worker_capability_scopes (worker_profile_id INTEGER, capability TEXT, access_scope TEXT, allowed INTEGER)',
            'CREATE TABLE worker_business_units (worker_profile_id INTEGER, business_unit_id INTEGER, is_lead INTEGER, ends_at TEXT)',
            'CREATE TABLE client_business_units (client_id INTEGER, business_unit_id INTEGER)',
            'CREATE TABLE project_assignments (project_id INTEGER, user_id INTEGER, ends_at TEXT)',
            'CREATE TABLE jobs (id INTEGER PRIMARY KEY, client_id INTEGER)',
            'CREATE TABLE employee_profiles (user_id INTEGER, first_name TEXT, last_name TEXT)',
            'CREATE TABLE projects (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE invoices (id INTEGER PRIMARY KEY, doc_number TEXT, invoice_type TEXT)',
            'CREATE TABLE work_time_entries (id TEXT PRIMARY KEY,user_id INTEGER,client_id INTEGER,project_id INTEGER,invoice_id INTEGER,job_id INTEGER,work_assignment_id INTEGER,status TEXT,workflow_status TEXT DEFAULT "submitted",start_time TEXT)',
        ] as $sql) {
            $pdo->exec($sql);
        }

        $pdo->exec("INSERT INTO roles VALUES (1,'employee',NULL,1)");
        $pdo->exec("INSERT INTO role_permissions VALUES (1,'approvals.review',1)");
        $pdo->exec("INSERT INTO app_config VALUES (0,'workforce_allow_non_admin_time_approval','1')");
        $pdo->exec("INSERT INTO users (id,role,username,email) VALUES
            (8101,'employee','reviewer','reviewer@example.test'),
            (8102,'employee','same-unit','same@example.test'),
            (8103,'employee','other-unit','other@example.test'),
            (8104,'employee','unscoped','unscoped@example.test'),
            (8105,'admin','admin-worker','admin-worker@example.test')");
        $pdo->exec("INSERT INTO worker_profiles (id,user_id,relationship_type,status) VALUES
            (9101,8101,'employee','active'),(9102,8102,'employee','active'),
            (9103,8103,'employee','active'),(9104,8104,'employee','active'),
            (9105,8105,'employee','active')");
        $pdo->exec("INSERT INTO worker_capability_scopes VALUES (9101,'approvals.review','business_unit',1)");
        $pdo->exec("INSERT INTO worker_business_units VALUES
            (9101,11,0,NULL),(9102,11,0,NULL),(9103,12,0,NULL),(9104,11,0,NULL)");
        $pdo->exec("INSERT INTO work_time_entries (id,user_id,client_id,project_id,invoice_id,job_id,work_assignment_id,status,start_time) VALUES
            ('own',8101,NULL,NULL,NULL,NULL,NULL,'review','2026-07-01 09:00:00'),
            ('same',8102,NULL,NULL,NULL,NULL,NULL,'review','2026-07-01 10:00:00'),
            ('other',8103,NULL,NULL,NULL,NULL,NULL,'review','2026-07-01 11:00:00'),
            ('admin-own',8105,NULL,NULL,NULL,NULL,NULL,'review','2026-07-01 12:00:00')");

        $policy = new TimeApprovalPolicy($pdo);
        $queue = new TimeReviewQueueService($pdo, $policy);
        self::assertTrue($policy->canAccessQueue(8101));
        self::assertFalse($policy->canAccessQueue(8104), 'ACL permission without an explicit review scope is not enough.');
        self::assertFalse($policy->canReviewEntry(8101, 'own'));
        self::assertTrue($policy->canReviewEntry(8101, 'same'));
        self::assertFalse($policy->canReviewEntry(8101, 'other'));
        self::assertFalse($policy->canReviewEntry(8105, 'admin-own', 'correct'), 'Administrator ACL is not an owner relationship.');
        self::assertSame(['same'], array_column($queue->pendingFor(8101), 'id'));
        self::assertSame(count($queue->pendingFor(8101)), $queue->pendingCountFor(8101));
        self::assertSame([8102 => 1], $queue->pendingCountsByUser(8101));
    }

    public function testViewsAndMutationsUseTheSharedQueueAndPolicyContracts(): void
    {
        $root = dirname(__DIR__, 2);
        $overview = (string)file_get_contents($root . '/src/views/pages/workforce/overview.php');
        $approvals = (string)file_get_contents($root . '/src/views/pages/workforce/approvals.php');
        $controller = (string)file_get_contents($root . '/src/controllers/workforce/action.php');
        $approval = (string)file_get_contents($root . '/src/Modules/Timekeeping/ApprovalService.php');

        self::assertStringContainsString('new TimeReviewQueueService', $overview);
        self::assertStringContainsString('pendingCountsByUser($userId)', $overview);
        self::assertStringContainsString('if ($canReviewTime)', $overview);
        self::assertStringContainsString('new TimeReviewQueueService', $approvals);
        self::assertStringContainsString('$reviewQueue->pendingFor($userId)', $approvals);
        self::assertStringContainsString('$approvalPolicy->assertCanReviewEntry', $controller);
        self::assertStringContainsString('$this->policy->assertCanReviewEntry', $approval);
        self::assertStringContainsString('WorkforceCommandRegistry::require', $controller);
        self::assertStringContainsString('workforce_self_confirm_owner', $controller);
        self::assertStringContainsString('returnOwnerForRepair', $approval);
        self::assertStringNotContainsString('A project or business billing rate is required for billable time.', $approval);
    }
}
