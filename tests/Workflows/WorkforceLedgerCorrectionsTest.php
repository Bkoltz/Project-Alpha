<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WorkforceLedgerCorrectionsTest extends TestCase
{
    private string $migration;
    private string $corrections;
    private string $statements;
    private string $payments;
    private string $credits;
    private string $exports;
    private string $billingResolutions;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->migration = (string)file_get_contents($root . '/database/migrations/0051_workforce_corrections_payments_credits_exports.sql');
        $this->corrections = (string)file_get_contents($root . '/src/services/TimeCorrectionService.php');
        $this->statements = (string)file_get_contents($root . '/src/services/WorkerStatementCorrectionService.php');
        $this->payments = (string)file_get_contents($root . '/src/services/WorkerPaymentRecordService.php');
        $this->credits = (string)file_get_contents($root . '/src/services/ClientCreditLedgerService.php');
        $this->exports = (string)file_get_contents($root . '/src/services/PayrollExportService.php');
        $this->billingResolutions = (string)file_get_contents($root . '/src/services/TimeCorrectionBillingResolutionService.php');
    }

    public function testCorrectionSchemaPreservesOriginalAndAppliedRevision(): void
    {
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS time_correction_requests', $this->migration);
        self::assertStringContainsString('original_revision INT UNSIGNED NOT NULL', $this->migration);
        self::assertStringContainsString('original_snapshot JSON NOT NULL', $this->migration);
        self::assertStringContainsString('proposed_snapshot JSON NOT NULL', $this->migration);
        self::assertStringContainsString('applied_revision INT UNSIGNED NULL', $this->migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS time_correction_effects', $this->migration);
    }

    public function testAdminApprovalCreatesRevisionAndSeparatePayAndBillingEffects(): void
    {
        self::assertStringContainsString('INSERT INTO work_time_revisions', $this->corrections);
        self::assertStringContainsString('revision=revision+1', $this->corrections);
        self::assertStringContainsString('compensationImpact', $this->corrections);
        self::assertStringContainsString('billingImpact', $this->corrections);
        self::assertStringContainsString('WorkerStatementCorrectionService', $this->corrections);
        self::assertStringContainsString("'admin_review'", $this->corrections);
        self::assertStringContainsString('Use correction requests only for confirmed time', $this->corrections);
        self::assertStringContainsString('workforce.corrections.manage', $this->corrections);
        self::assertStringContainsString("\$method === 'base_overage'", $this->corrections);
        self::assertStringContainsString('refreshDraftInvoice', $this->corrections);
        self::assertStringContainsString('proposed time context conflicts with the selected invoice', $this->corrections);
        self::assertStringContainsString('A correction can move time only to a mutable draft invoice.', $this->corrections);
        self::assertStringContainsString('status,finalized_at FROM invoices WHERE id=?', $this->corrections);
        self::assertStringNotContainsString('DELETE FROM work_time_entries', $this->corrections);
    }

    public function testStatementCorrectionsUseStateBasedReplacementOrNextPeriodDelta(): void
    {
        self::assertStringContainsString("'void_reissue'", $this->statements);
        self::assertStringContainsString("'next_period_adjustment'", $this->statements);
        self::assertStringContainsString("UPDATE worker_statements SET status='voided'", $this->statements);
        self::assertStringContainsString('replaces_statement_id', $this->statements);
        self::assertStringContainsString('worker_payment_allocations', $this->statements);
        self::assertStringContainsString('payroll_export_rows', $this->statements);
        self::assertStringContainsString("'debit' : 'credit'", $this->statements);
    }

    public function testWorkerPaymentsAreFactsAllocatedSeparatelyFromStatements(): void
    {
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS worker_payment_records', $this->migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS worker_payment_allocations', $this->migration);
        self::assertStringContainsString('allocations must equal the payment amount', $this->payments);
        self::assertStringContainsString('workforce.payments.manage', $this->payments);
        self::assertStringContainsString("p.status='confirmed'", $this->payments);
        self::assertStringContainsString("status='voided'", $this->payments);
    }

    public function testLegacySettledStatementsReceiveIdempotentPaymentFacts(): void
    {
        self::assertStringContainsString("record_source ENUM('admin_confirmed','legacy_statement_backfill')", $this->migration);
        self::assertStringContainsString('UNIQUE KEY uq_worker_payment_legacy_statement', $this->migration);
        self::assertStringContainsString("SHA2(CONCAT('legacy-worker-statement:',s.id),256)", $this->migration);
        self::assertStringContainsString("WHERE s.status='settled' AND s.total_amount>0", $this->migration);
        self::assertStringContainsString('INSERT IGNORE INTO worker_payment_records', $this->migration);
        self::assertStringContainsString('INSERT IGNORE INTO worker_payment_allocations', $this->migration);
        self::assertStringContainsString("p.record_source='legacy_statement_backfill'", $this->migration);
        self::assertLessThan(
            strpos($this->migration, 'INSERT IGNORE INTO worker_payment_allocations'),
            strpos($this->migration, 'CREATE TABLE IF NOT EXISTS worker_payment_allocations')
        );
    }

    public function testClientCreditsAreScopedAndAppendOnly(): void
    {
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS client_credits', $this->migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS client_credit_events', $this->migration);
        self::assertStringContainsString('same client and organization', $this->credits);
        self::assertStringContainsString('billing.client_credits.manage', $this->credits);
        self::assertStringContainsString('credit_applied', $this->credits);
        self::assertStringContainsString("\$balance <= 0.005 => 'credited'", $this->credits);
        self::assertStringContainsString('cash_amount_paid_unchanged', $this->credits);
        self::assertStringNotContainsString('amount_paid=amount_paid+', $this->credits);
        self::assertStringContainsString("'allocation_reversed'", $this->credits);
        self::assertStringContainsString("'refund_recorded'", $this->credits);
        self::assertStringNotContainsString('DELETE FROM client_credit', $this->credits);
    }

    public function testFinalizedInvoiceDeltaRequiresOneAuditedResolution(): void
    {
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS time_correction_billing_resolutions', $this->migration);
        self::assertStringContainsString('UNIQUE KEY uq_time_correction_billing_resolution', $this->migration);
        self::assertStringContainsString("'invoice_adjustment','move_to_draft','absorb'", $this->migration);
        self::assertStringContainsString('ClientCreditLedgerService', $this->billingResolutions);
        self::assertStringContainsString('same client and organization', $this->billingResolutions);
        self::assertStringContainsString('time_correction_billing_resolutions', $this->billingResolutions);
        self::assertStringContainsString('signedMoneyToMinor', $this->billingResolutions);
        self::assertStringContainsString('pricing_invoice_is_fixed_total_installment', $this->billingResolutions);
        self::assertStringContainsString('pricing_finalize_frozen_document_revision', $this->billingResolutions);
        self::assertStringContainsString('pricing_carry_forward_document_revision', $this->billingResolutions);
        self::assertStringContainsString("(string)\$effect['currency']", $this->billingResolutions);
        self::assertStringContainsString(
            '$creditAppliedMinor > max(0, $newTotalMinor - $paidMinor)',
            $this->billingResolutions
        );
        self::assertStringContainsString('affects_total,revision_number', $this->billingResolutions);
        self::assertStringNotContainsString('revision_number=revision_number+1', $this->billingResolutions);
        self::assertStringContainsString('pricing_invoice_is_fixed_total_installment', $this->corrections);
        self::assertStringContainsString('pricing_finalize_frozen_document_revision', $this->corrections);
        self::assertStringContainsString('affects_total,revision_number', $this->corrections);
    }

    public function testCorrectionMovesBillingAsOneConsistentProjection(): void
    {
        self::assertStringContainsString('detachBillingProjection', $this->corrections);
        self::assertStringContainsString('attachReplacementToDraftInvoice', $this->corrections);
        self::assertStringContainsString("'invoice_item_id' => \$sameInvoice ? \$allocation['invoice_item_id'] : null", $this->corrections);
        self::assertStringContainsString('SET bt.billed=0,bt.invoice_id=NULL,bt.invoice_item_id=NULL', $this->corrections);
        self::assertStringContainsString("\$replacementTreatment = !empty(\$after['billable']) ? 'hourly' : 'internal'", $this->corrections);
        self::assertStringContainsString('DELETE FROM invoice_items WHERE id=? AND invoice_id=?', $this->corrections);
    }

    public function testCorrectionCanChangePayabilityWithoutRewritingPriorEarnings(): void
    {
        self::assertStringContainsString("\$newAmount = empty(\$after['is_payable']) ? 0.0", $this->corrections);
        self::assertStringContainsString('WorkerEarningService($this->pdo))->record', $this->corrections);
        self::assertStringContainsString("'new_earning' => true", $this->corrections);
        self::assertStringContainsString("empty(\$pay['new_earning'])", $this->corrections);
    }

    public function testCorrectionCanMoveOnlyToMutableDraftInvoice(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE invoices (
                id INTEGER PRIMARY KEY, client_id INTEGER, project_id INTEGER, job_id INTEGER,
                status TEXT NOT NULL, finalized_at TEXT NULL
            )'
        );
        $pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, client_id INTEGER, project_id INTEGER)');
        $pdo->exec('INSERT INTO jobs VALUES (30,10,20)');
        $pdo->exec(
            "INSERT INTO invoices VALUES
             (1,10,20,30,'draft',NULL),
             (2,10,20,30,'sent','2026-07-22 12:00:00')"
        );
        $method = new ReflectionMethod(App\Services\TimeCorrectionService::class, 'validateBillingContext');
        $method->setAccessible(true);
        $service = new App\Services\TimeCorrectionService($pdo);
        $proposal = [
            'invoice_id' => 1, 'client_id' => null, 'project_id' => null,
            'job_id' => null, 'billable' => 0,
        ];

        $validated = $method->invoke($service, $proposal, ['invoice_id' => null]);
        self::assertSame(10, $validated['client_id']);
        self::assertSame(20, $validated['project_id']);
        self::assertSame(30, $validated['job_id']);

        $proposal['invoice_id'] = 2;
        try {
            $method->invoke($service, $proposal, ['invoice_id' => null]);
            self::fail('A correction moved time to a finalized invoice.');
        } catch (DomainException $error) {
            self::assertStringContainsString('mutable draft invoice', $error->getMessage());
        }

        $validatedFinalizedHistory = $method->invoke($service, $proposal, ['invoice_id' => 2]);
        self::assertSame(2, $validatedFinalizedHistory['invoice_id']);
    }

    public function testBaseOverageCorrectionsUseImmutableJobPricing(): void
    {
        self::assertStringContainsString('client_billing_treatment_snapshot', $this->corrections);
        self::assertStringContainsString('client_included_minutes_snapshot', $this->corrections);
        self::assertStringContainsString('client_overage_rate_snapshot', $this->corrections);
        self::assertStringContainsString('applyDraftBaseOverageDelta', $this->corrections);
        self::assertStringContainsString('lacks its immutable pricing snapshot', $this->corrections);
    }

    public function testNegativePayCorrectionsAreCappedAndCarriedForward(): void
    {
        self::assertStringContainsString('max($delta, -$originalTotal)', $this->statements);
        self::assertStringContainsString('carryforwardAdjustmentId', $this->statements);
        self::assertStringContainsString("\$status = \$deferDebit && \$delta < 0 ? 'pending' : 'reviewed'", $this->statements);
        self::assertStringContainsString('A zero or negative worker statement is not payable', $this->payments);
    }

    public function testReplacementStatementsRetainReconciliationLinks(): void
    {
        self::assertStringContainsString("\\'$.replacement_source\\'", $this->statements);
        self::assertStringContainsString("\\'statement_line_id\\',id", $this->statements);
        self::assertStringContainsString("'$.replacement_source.worker_earning_id'", $this->payments);
    }

    public function testBillableCorrectionsRequireCanonicalJob(): void
    {
        self::assertStringContainsString('Client-billable corrected time must be assigned to a canonical Job.', $this->corrections);
    }

    public function testCorrectionBrowserTimesAreConvertedFromWorkforceTimezone(): void
    {
        self::assertStringContainsString('WorkforceSettings::load($this->pdo)', $this->corrections);
        self::assertStringContainsString("str_contains(\$startValue, 'T') ? \$workforceTimezone : \$utc", $this->corrections);
        self::assertStringContainsString('->setTimezone($utc)', $this->corrections);
    }

    public function testPayrollCsvIsImmutableIdempotentAndDoesNotDoubleExportEarnings(): void
    {
        self::assertStringContainsString('UNIQUE KEY uq_payroll_export_key', $this->migration);
        self::assertStringContainsString('UNIQUE KEY uq_payroll_export_earning', $this->migration);
        self::assertStringContainsString('export_row_number INT UNSIGNED NOT NULL', $this->migration);
        self::assertStringNotContainsString(' row_number INT', $this->migration);
        self::assertStringContainsString("'export_id','statement_id','earning_id'", $this->exports);
        self::assertStringContainsString("\$direction === 'debit' ? -1 : 1", $this->exports);
        self::assertStringContainsString("x.status='generated'", $this->exports);
        self::assertStringContainsString('workforce.payroll_exports.manage', $this->exports);
        self::assertStringContainsString("status='voided'", $this->exports);
    }

    public function testPayrollPeriodLabelCannotContainOtherPeriods(): void
    {
        self::assertStringContainsString('Every selected earning must belong to the labeled payroll pay period.', $this->exports);
        self::assertStringContainsString('e.pay_period_id IS NULL OR e.pay_period_id<>?', $this->exports);
        self::assertStringContainsString('already belongs to a different pay period', $this->exports);
        self::assertStringContainsString('Payroll pay period not found.', $this->exports);
    }
}
