<?php

declare(strict_types=1);

namespace Tests\Workflows;

use App\Services\TimeSubmissionService;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;

final class TimeEntryWithdrawalWorkflowTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE time_submissions (
                id TEXT PRIMARY KEY, worker_profile_id INTEGER, status TEXT,
                reviewed_by INTEGER, reviewed_at TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE work_time_entries (
                id TEXT PRIMARY KEY, user_id INTEGER, workflow_status TEXT,
                revision INTEGER, current_submission_id TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE time_submission_entries (
                submission_id TEXT, time_entry_id TEXT, entry_revision INTEGER,
                decision TEXT, decision_reason TEXT, reviewed_by INTEGER,
                reviewed_at TEXT,
                PRIMARY KEY (submission_id, time_entry_id, entry_revision)
            )'
        );
        $this->pdo->exec(
            "INSERT INTO time_submissions (id,worker_profile_id,status)
             VALUES ('submission-1',20,'submitted')"
        );
        $this->pdo->exec(
            "INSERT INTO work_time_entries (id,user_id,workflow_status,revision,current_submission_id)
             VALUES ('entry-1',2,'submitted',3,'submission-1')"
        );
        $this->pdo->exec(
            "INSERT INTO time_submission_entries (submission_id,time_entry_id,entry_revision,decision)
             VALUES ('submission-1','entry-1',3,'pending')"
        );
    }

    public function testWorkerCanWithdrawOwnPendingRevisionWithoutRewritingSnapshot(): void
    {
        (new TimeSubmissionService($this->pdo))->withdrawPendingForEdit(
            'submission-1',
            'entry-1',
            3,
            2
        );

        $snapshot = $this->pdo->query(
            "SELECT decision,decision_reason,reviewed_by,reviewed_at
             FROM time_submission_entries WHERE submission_id='submission-1'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('withdrawn', $snapshot['decision']);
        self::assertSame('Withdrawn for editing', $snapshot['decision_reason']);
        self::assertSame(2, (int)$snapshot['reviewed_by']);
        self::assertNotEmpty($snapshot['reviewed_at']);
        self::assertSame('returned', $this->pdo->query(
            "SELECT status FROM time_submissions WHERE id='submission-1'"
        )->fetchColumn());
        self::assertSame(3, (int)$this->pdo->query(
            "SELECT revision FROM work_time_entries WHERE id='entry-1'"
        )->fetchColumn(), 'The submitted snapshot service must not overwrite the live entry revision.');
    }

    public function testWorkerCannotWithdrawAnotherWorkersTime(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('only your own');
        (new TimeSubmissionService($this->pdo))->withdrawPendingForEdit(
            'submission-1',
            'entry-1',
            3,
            99
        );
    }

    public function testManagerCanWithdrawAccessibleWorkersTime(): void
    {
        (new TimeSubmissionService($this->pdo))->withdrawPendingForEdit(
            'submission-1',
            'entry-1',
            3,
            99,
            true
        );
        self::assertSame('withdrawn', $this->pdo->query(
            "SELECT decision FROM time_submission_entries WHERE submission_id='submission-1'"
        )->fetchColumn());
    }

    public function testStaleRevisionAndRepeatedWithdrawalAreRejectedAtomically(): void
    {
        $service = new TimeSubmissionService($this->pdo);
        try {
            $service->withdrawPendingForEdit('submission-1', 'entry-1', 2, 2);
            self::fail('A stale revision was withdrawn.');
        } catch (DomainException $error) {
            self::assertStringContainsString('changed', $error->getMessage());
        }
        self::assertSame('pending', $this->pdo->query(
            "SELECT decision FROM time_submission_entries WHERE submission_id='submission-1'"
        )->fetchColumn());

        $service->withdrawPendingForEdit('submission-1', 'entry-1', 3, 2);
        $this->expectException(DomainException::class);
        $service->withdrawPendingForEdit('submission-1', 'entry-1', 3, 2);
    }

    public function testForwardMigrationAndBaselineAllowWithdrawnDecision(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string)file_get_contents(
            $root . '/database/migrations/0056_time_withdrawal_and_external_access_consistency.sql'
        );
        $baseline = (string)file_get_contents($root . '/database/baseline.sql');

        self::assertStringContainsString("'withdrawn'", $migration);
        self::assertStringContainsString("'withdrawn'", $baseline);
        self::assertStringContainsString('manual_enabled=CASE WHEN enabled=1 THEN 1 ELSE 0 END', $migration);
        self::assertStringContainsString('automatic_enabled=0', $migration);
        self::assertStringContainsString('oversight_enabled=0', $migration);
    }

    public function testWorkerEditPreservesServerControlledBillingAndPayState(): void
    {
        $service = (string)file_get_contents(
            dirname(__DIR__, 2) . '/src/Modules/Timekeeping/TimekeepingService.php'
        );
        self::assertStringContainsString(
            '$billable = $manageAll ? $this->explicitBillableFlag($input) : (int)$entry[\'billable\'];',
            $service
        );
        self::assertStringContainsString(
            ": (int)\$entry['is_payable'];",
            $service
        );
        self::assertStringContainsString(
            ": (string)\$entry['billing_state'];",
            $service
        );
    }

    public function testApprovedTimeUsesOneAuditedCorrectionWorkflow(): void
    {
        $root = dirname(__DIR__, 2);
        $approvals = (string)file_get_contents($root . '/src/views/pages/workforce/approvals.php');
        $time = (string)file_get_contents($root . '/src/views/pages/workforce/time.php');
        $controller = (string)file_get_contents($root . '/src/controllers/workforce/action.php');
        $corrections = (string)file_get_contents($root . '/src/services/TimeCorrectionService.php');

        self::assertStringContainsString('value="admin-correction-apply"', $approvals);
        self::assertStringContainsString("\$manageAll ? 'admin-correction-apply' : 'correction-request'", $time);
        self::assertStringNotContainsString("['approve','reject','correct','void']", $controller);
        self::assertStringContainsString('A correction for this time revision is already awaiting review.', $corrections);
        self::assertStringContainsString('a.entry_revision<=?', $corrections);
        self::assertStringNotContainsString('name="billable"', $approvals);
        self::assertStringNotContainsString('name="is_payable"', $approvals);
        self::assertStringContainsString('name="billable"', $time);
        self::assertStringContainsString('name="is_payable"', $time);
        self::assertStringContainsString("'description','billable','is_payable'", $controller);
        self::assertStringContainsString('Save audited edit', $time);
    }

    public function testInvoiceLinkingRequiresBillingAccessAndOffersOnlyMatchingJobDrafts(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string)file_get_contents($root . '/src/controllers/workforce/action.php');
        $service = (string)file_get_contents($root . '/src/Modules/Timekeeping/TimekeepingService.php');
        $time = (string)file_get_contents($root . '/src/views/pages/workforce/time.php');
        $javascript = (string)file_get_contents($root . '/public/assets/js/workforce.js');

        self::assertStringContainsString("user_can(\$pdo, \$actorId, 'invoices.edit', 0)", $controller);
        self::assertStringContainsString("can_access_record(\$pdo, 'invoices', \$invoiceId, \$actorId)", $controller);
        self::assertStringContainsString('i.job_id IS NOT NULL', $service);
        self::assertStringContainsString("(int)\$invoice['job_id'] === (int)\$entry['job_id']", $time);
        self::assertStringContainsString('option.dataset.jobId === job.value', $javascript);
    }
}
