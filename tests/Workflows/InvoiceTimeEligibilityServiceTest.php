<?php

declare(strict_types=1);

use App\Services\WorkTimeInvoiceEligibilityService;
use PHPUnit\Framework\TestCase;

final class InvoiceTimeEligibilityServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('The SQLite PDO driver is unavailable.');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
        $this->seedFixture();
    }

    public function testReadyAndPendingEntriesShareInvoiceCompatibilityAndDuplicateRules(): void
    {
        $result = (new WorkTimeInvoiceEligibilityService($this->pdo))->forInvoice(10, 1);

        self::assertSame(['ready'], array_column($result['ready'], 'id'));
        self::assertSame(
            ['pending-admin', 'pending-member', 'pending-other'],
            array_column($result['pending'], 'id')
        );
        self::assertSame(['attached-pending'], array_column($result['attached'], 'id'));

        $adminEntry = $this->entryById($result['pending'], 'pending-admin');
        self::assertTrue($adminEntry['can_confirm_and_add']);
        self::assertSame('administrative', $adminEntry['confirmation_mode']);
        self::assertFalse($adminEntry['requires_another_reviewer']);

        $otherEntry = $this->entryById($result['pending'], 'pending-other');
        self::assertFalse($otherEntry['can_confirm_and_add']);

        self::assertNotContains('other-client', array_column($result['pending'], 'id'));
        self::assertNotContains('other-job', array_column($result['pending'], 'id'));
        self::assertNotContains('other-invoice', array_column($result['pending'], 'id'));
        self::assertNotContains('confirmed-no-projection', array_column($result['ready'], 'id'));
        self::assertNotContains('attached-pending', array_column($result['pending'], 'id'));
    }

    public function testBuiltInOwnerAndVerifiedWorkerOwnerCanConfirmOwnPendingTime(): void
    {
        $ownerResult = (new WorkTimeInvoiceEligibilityService($this->pdo))->forInvoice(10, 4);
        $ownerEntry = $this->entryById($ownerResult['pending'], 'pending-other');
        self::assertTrue($ownerResult['actor_can_administratively_self_confirm']);
        self::assertTrue($ownerEntry['can_confirm_and_add']);
        self::assertSame('administrative', $ownerEntry['confirmation_mode']);

        $verifiedOwnerResult = (new WorkTimeInvoiceEligibilityService($this->pdo))->forInvoice(10, 2);
        $verifiedOwnerEntry = $this->entryById($verifiedOwnerResult['pending'], 'pending-member');
        self::assertFalse($verifiedOwnerResult['actor_can_administratively_self_confirm']);
        self::assertTrue($verifiedOwnerResult['actor_is_verified_owner']);
        self::assertTrue($verifiedOwnerEntry['can_confirm_and_add']);
        self::assertSame('verified_owner', $verifiedOwnerEntry['confirmation_mode']);
    }

    public function testInvoiceWithoutJobExplainsWhyNoTimeCanBeAdded(): void
    {
        $result = (new WorkTimeInvoiceEligibilityService($this->pdo))->forInvoice(12, 1);

        self::assertSame([], $result['ready']);
        self::assertSame([], $result['pending']);
        self::assertSame('Assign the invoice to a Job before adding tracked time.', $result['blocking_reason']);
    }

    public function testDirectAttachmentUsesTheSameInvoiceDestinationRule(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('assigned to a different invoice');

        WorkTimeInvoiceEligibilityService::assertCompatibleDestination(
            ['client_id' => 7, 'job_id' => 20, 'invoice_id' => 11],
            ['id' => 10, 'client_id' => 7, 'job_id' => 20]
        );
    }

    public function testDirectAttachmentRejectsAProjectionFromAnyApprovalRevision(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already attached to an invoice');

        (new WorkTimeInvoiceEligibilityService($this->pdo))->assertUnattached('attached-pending');
    }

    public function testPendingConfirmationPreflightRequiresAResolvableRate(): void
    {
        $service = (string)file_get_contents(
            dirname(__DIR__, 2) . '/src/services/WorkTimeInvoiceEligibilityService.php'
        );
        $controller = (string)file_get_contents(
            dirname(__DIR__, 2) . '/src/controllers/workforce/action.php'
        );

        self::assertStringContainsString('assertResolvableBillingRate', $service);
        self::assertStringContainsString('Enter the hourly billing rate to add this time to the invoice.', $service);
        self::assertStringContainsString('$eligibility->assertUnattached($entryId)', $controller);
        self::assertStringContainsString(
            '$eligibility->assertResolvableBillingRate($entry, $invoiceId, $requestedRate)',
            $controller
        );
    }

    /** @param list<array<string,mixed>> $entries */
    private function entryById(array $entries, string $id): array
    {
        foreach ($entries as $entry) {
            if ((string)$entry['id'] === $id) {
                return $entry;
            }
        }
        self::fail('Entry not returned: ' . $id);
    }

    private function createSchema(): void
    {
        $statements = [
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY, role TEXT, deleted_at TEXT, is_disabled INTEGER,
                username TEXT, email TEXT
            )',
            'CREATE TABLE worker_profiles (
                user_id INTEGER, status TEXT, relationship_type TEXT, relationship_review_required INTEGER
            )',
            'CREATE TABLE invoices (
                id INTEGER PRIMARY KEY, client_id INTEGER, job_id INTEGER, status TEXT, finalized_at TEXT
            )',
            'CREATE TABLE employee_profiles (user_id INTEGER, first_name TEXT, last_name TEXT)',
            'CREATE TABLE work_types (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE jobs (id INTEGER PRIMARY KEY, job_code TEXT)',
            'CREATE TABLE projects (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE work_time_entries (
                id TEXT PRIMARY KEY, user_id INTEGER, client_id INTEGER, invoice_id INTEGER,
                job_id INTEGER, project_id INTEGER, work_type_id INTEGER, start_time TEXT,
                end_time TEXT, duration_seconds INTEGER, description TEXT, status TEXT,
                workflow_status TEXT, billable INTEGER, revision INTEGER
            )',
            'CREATE TABLE work_approval_snapshots (
                id TEXT PRIMARY KEY, time_entry_id TEXT, entry_revision INTEGER,
                voided_at TEXT, billing_rate REAL
            )',
            'CREATE TABLE work_billing_consumptions (
                id INTEGER PRIMARY KEY, approval_snapshot_id TEXT,
                billing_time_entry_id INTEGER, consumption_type TEXT
            )',
            'CREATE TABLE time_entries (
                id INTEGER PRIMARY KEY, billed INTEGER, invoice_id INTEGER,
                invoice_item_id INTEGER, rate REAL
            )',
            'CREATE TABLE invoice_items (
                id INTEGER PRIMARY KEY, invoice_id INTEGER, time_entry_id INTEGER
            )',
            'CREATE TABLE work_time_billing_allocations (
                id INTEGER PRIMARY KEY, time_entry_id TEXT, status TEXT,
                invoice_id INTEGER, invoice_item_id INTEGER
            )',
        ];
        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }
    }

    private function seedFixture(): void
    {
        $this->pdo->exec(
            "INSERT INTO users (id,role,deleted_at,is_disabled,username,email) VALUES
             (1,'admin',NULL,0,'admin','admin@example.test'),
             (2,'member',NULL,0,'member','member@example.test'),
             (3,'staff',NULL,0,'staff','staff@example.test'),
             (4,'owner',NULL,0,'owner','owner@example.test')"
        );
        $this->pdo->exec(
            "INSERT INTO worker_profiles (user_id,status,relationship_type,relationship_review_required)
             VALUES (2,'active','owner',0)"
        );
        $this->pdo->exec(
            "INSERT INTO invoices (id,client_id,job_id,status,finalized_at) VALUES
             (10,7,20,'draft',NULL),(11,7,20,'draft',NULL),(12,7,NULL,'draft',NULL)"
        );
        $this->pdo->exec("INSERT INTO jobs (id,job_code) VALUES (20,'JOB-20'),(21,'JOB-21')");

        $this->insertEntry('ready', 3, 7, null, 20, 'approved', 'confirmed');
        $this->insertProjection('ready', 100, false, null, null, 125.0);

        $this->insertEntry('pending-admin', 1, 7, 10, 20, 'review', 'submitted');
        $this->insertEntry('pending-member', 2, 7, null, null, 'review', 'submitted');
        $this->insertEntry('pending-other', 4, 7, null, 20, 'review', 'submitted');

        $this->insertEntry('attached-pending', 1, 7, 10, 20, 'review', 'submitted');
        $this->insertProjection('attached-pending', 101, true, 10, 501, 125.0);
        $this->pdo->exec('INSERT INTO invoice_items (id,invoice_id,time_entry_id) VALUES (501,10,101)');

        $this->insertEntry('other-client', 1, 8, null, 20, 'review', 'submitted');
        $this->insertEntry('other-job', 1, 7, null, 21, 'review', 'submitted');
        $this->insertEntry('other-invoice', 1, 7, 11, 20, 'review', 'submitted');
        $this->insertEntry('confirmed-no-projection', 1, 7, null, 20, 'approved', 'confirmed');
    }

    private function insertEntry(
        string $id,
        int $userId,
        int $clientId,
        ?int $invoiceId,
        ?int $jobId,
        string $status,
        string $workflowStatus
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO work_time_entries
             (id,user_id,client_id,invoice_id,job_id,project_id,work_type_id,start_time,end_time,
              duration_seconds,description,status,workflow_status,billable,revision)
             VALUES (?,?,?,?,?,NULL,NULL,?,?,?,?,?,?,1,1)'
        );
        $stmt->execute([
            $id,
            $userId,
            $clientId,
            $invoiceId,
            $jobId,
            '2026-07-20 09:00:00',
            '2026-07-20 10:00:00',
            3600,
            $id,
            $status,
            $workflowStatus,
        ]);
    }

    private function insertProjection(
        string $entryId,
        int $projectionId,
        bool $billed,
        ?int $invoiceId,
        ?int $invoiceItemId,
        float $rate
    ): void {
        $snapshotId = 'snapshot-' . $entryId;
        $snapshot = $this->pdo->prepare(
            'INSERT INTO work_approval_snapshots
             (id,time_entry_id,entry_revision,voided_at,billing_rate) VALUES (?,?,1,NULL,?)'
        );
        $snapshot->execute([$snapshotId, $entryId, $rate]);

        $projection = $this->pdo->prepare(
            'INSERT INTO time_entries (id,billed,invoice_id,invoice_item_id,rate) VALUES (?,?,?,?,?)'
        );
        $projection->execute([$projectionId, $billed ? 1 : 0, $invoiceId, $invoiceItemId, $rate]);

        $consumption = $this->pdo->prepare(
            "INSERT INTO work_billing_consumptions
             (approval_snapshot_id,billing_time_entry_id,consumption_type) VALUES (?,?,'approved')"
        );
        $consumption->execute([$snapshotId, $projectionId]);
    }
}
