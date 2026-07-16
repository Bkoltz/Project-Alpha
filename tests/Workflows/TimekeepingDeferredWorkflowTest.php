<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TimekeepingDeferredWorkflowTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testOwnerTimeUsesTheApprovalSnapshotPipelineWithoutOwnerPay(): void
    {
        $approval = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/ApprovalService.php');
        $time = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/TimekeepingService.php');
        $controller = (string)file_get_contents($this->root . '/src/controllers/workforce/action.php');

        self::assertStringContainsString('public function selfConfirmOwner', $approval);
        self::assertStringContainsString('$effectivePayable=!$ownerSelfConfirmation', $approval);
        self::assertStringContainsString('if(!$ownerSelfConfirmation&&!empty($entry[\'work_assignment_id\']))', $approval);
        self::assertStringContainsString("'time_entry.owner_self_confirmed'", $approval);
        self::assertStringContainsString("'duration'", $time);
        self::assertStringContainsString("'review',NULL,NULL", $time);
        self::assertStringContainsString('$approval->selfConfirmOwner($userId, $entryToSelfConfirm)', $controller);
        self::assertStringContainsString('$entryToSelfConfirm = $time->saveManual', $controller);
        self::assertStringContainsString('$entryToSelfConfirm = $time->saveDuration', $controller);
    }

    public function testSelfApprovalIsRejectedAndMissingBillingRateIsDeferred(): void
    {
        $approval = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/ApprovalService.php');

        self::assertStringContainsString('You cannot approve your own time entry.', $approval);
        self::assertStringContainsString('$billingRate = $this->billingRate($entry);', $approval);
        self::assertStringNotContainsString('A project or business billing rate is required for billable time.', $approval);
    }

    public function testEditableUnbilledTimeKeepsAnAuditedRevisionAndCompatibilityAlias(): void
    {
        $time = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/TimekeepingService.php');

        self::assertStringContainsString('public function reviseEntry(', $time);
        self::assertStringContainsString("status IN ('review','rejected') OR (status='approved' AND owner_self_confirmed=1)", $time);
        self::assertStringContainsString('Billed or invoiced time cannot be edited.', $time);
        self::assertStringContainsString('INSERT INTO work_time_revisions', $time);
        self::assertStringContainsString("'time_entry.revised'", $time);
        self::assertStringContainsString('public function reviseRejected(', $time);
        self::assertStringContainsString('$this->reviseEntry($userId, $userId, $entryId, $input, $manageAll);', $time);
    }

    public function testInvoiceContextInfersJobAndReviewQueueExcludesReviewer(): void
    {
        $time = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/TimekeepingService.php');
        $approvals = (string)file_get_contents($this->root . '/src/views/pages/workforce/approvals.php');
        $approval = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/ApprovalService.php');

        self::assertStringContainsString('SELECT client_id,project_id,job_id FROM invoices', $time);
        self::assertStringContainsString('$jobId ??= $invoiceJobId;', $time);
        self::assertStringContainsString("WHERE t.status='review' AND t.user_id<>?", $approvals);
        self::assertStringContainsString('$queueStmt->execute([$userId]);', $approvals);
        self::assertStringContainsString('s2.entry_revision<=t.revision', $approvals);
        self::assertStringContainsString('s.entry_revision<=?', $approval);
        self::assertStringContainsString('Select the assignment Job before saving time.', $time);
    }
}
