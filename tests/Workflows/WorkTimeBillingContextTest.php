<?php

declare(strict_types=1);

use App\Services\WorkTimeBillingContextService;
use PHPUnit\Framework\TestCase;

final class WorkTimeBillingContextTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testServiceIsAvailableThroughTheApplicationAutoloader(): void
    {
        self::assertTrue(class_exists(WorkTimeBillingContextService::class));
        self::assertTrue(method_exists(WorkTimeBillingContextService::class, 'synchronizeInvoice'));
    }

    public function testServiceMapsBillingProjectionWithoutMutatingApprovalSnapshots(): void
    {
        $source = (string)file_get_contents(
            $this->root . '/src/services/WorkTimeBillingContextService.php'
        );

        self::assertStringContainsString(
            'JOIN work_approval_snapshots s ON s.id=c.approval_snapshot_id',
            $source
        );
        self::assertStringContainsString(
            'JOIN time_entries te ON te.id=c.billing_time_entry_id',
            $source
        );
        self::assertStringContainsString('work_time_revisions', $source);
        self::assertStringContainsString('time_entry.billing_context_linked', $source);
        self::assertStringContainsString('revision=revision+1', $source);
        self::assertStringContainsString('AND entry_revision=?', $source);
        self::assertStringContainsString("status IN ('ready','rate_needed')", $source);
        self::assertStringNotContainsString('UPDATE work_approval_snapshots', $source);
        self::assertStringNotContainsString('DELETE FROM work_approval_snapshots', $source);
    }

    public function testInvoiceCreateAndUpdateSynchronizeInsideTheirTransactions(): void
    {
        $create = (string)file_get_contents(
            $this->root . '/src/controllers/invoice/invoices_create.php'
        );
        $update = (string)file_get_contents(
            $this->root . '/src/controllers/invoice/invoices_update.php'
        );

        foreach ([$create, $update] as $controller) {
            self::assertStringContainsString('WorkTimeBillingContextService.php', $controller);
            self::assertStringContainsString('->synchronizeInvoice(', $controller);
        }

        self::assertLessThan(
            strpos($create, '$pdo->commit();'),
            strpos($create, '->synchronizeInvoice(')
        );
        self::assertLessThan(
            strpos($update, '$pdo->commit();'),
            strpos($update, '->synchronizeInvoice(')
        );
    }

    public function testWorkTypeIsPreservedUnlessOneDistinctTypeIsSupplied(): void
    {
        $source = (string)file_get_contents(
            $this->root . '/src/services/WorkTimeBillingContextService.php'
        );

        self::assertStringContainsString('count($suppliedWorkTypes) === 1', $source);
        self::assertStringContainsString(
            "'work_type_id' => \$suppliedWorkTypeId ?? \$this->nullableInt(\$entry['work_type_id'] ?? null)",
            $source
        );
        self::assertStringContainsString('WHERE id=? AND is_active=1', $source);
    }
}
