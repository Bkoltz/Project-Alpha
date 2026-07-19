<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ServiceJobDomainWorkflowTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testMigrationEnforcesExclusiveActiveLinksAndServicePricing(): void
    {
        $migration = (string)file_get_contents($this->root . '/database/migrations/0052_service_activity_links_and_hourly_estimates.sql');
        foreach (['client_pricing_model','client_included_minutes','client_overage_rate','pricing_currency'] as $column) {
            self::assertStringContainsString($column, $migration);
        }
        self::assertStringContainsString('catalog_link_migration_review', $migration);
        self::assertStringContainsString('uq_catalog_active_service_link', $migration);
        self::assertStringContainsString('uq_catalog_active_activity_link', $migration);
    }

    public function testWorkActivitiesDoNotOwnClientPricing(): void
    {
        $view = (string)file_get_contents($this->root . '/src/views/pages/settings/work-types.php');
        $handler = (string)file_get_contents($this->root . '/src/controllers/settings/workforce_catalog_handler.php');
        $planner = (string)file_get_contents($this->root . '/src/services/JobWorkPlanningService.php');
        self::assertStringNotContainsString('<legend>Client billing default</legend>', $view);
        self::assertStringContainsString('client pricing belongs to the Service Library', $view);
        self::assertStringContainsString('default_treatment="undecided",default_billing_rate=NULL', $handler);
        self::assertStringContainsString("'source' => 'work_activity_default'", $planner);
    }

    public function testUnclassifiedTimeDoesNotCreateHiddenJobsAndBillableTimeRequiresAJob(): void
    {
        $unscheduled = (string)file_get_contents($this->root . '/src/services/UnscheduledServiceJobService.php');
        $timekeeping = (string)file_get_contents($this->root . '/src/Modules/Timekeeping/TimekeepingService.php');
        self::assertStringNotContainsString('INSERT INTO jobs', $unscheduled);
        self::assertStringContainsString('Choose a Job before assigning client-billable Service work', $unscheduled);
        self::assertStringContainsString('assertBillableJob', $timekeeping);
        self::assertStringContainsString('Client-billable time must be assigned to a Job', $timekeeping);
    }

    public function testHourlyQuoteAndContractLinesRemainEstimatesDuringConversion(): void
    {
        $quoteCreate = (string)file_get_contents($this->root . '/src/controllers/quote/quotes_create.php');
        $quoteApprove = (string)file_get_contents($this->root . '/src/controllers/quote/quote_approve.php');
        $contractCreate = (string)file_get_contents($this->root . '/src/controllers/contract/contracts_create.php');
        $contractUpdate = (string)file_get_contents($this->root . '/src/controllers/contract/contracts_update.php');
        $publicQuoteAction = (string)file_get_contents($this->root . '/src/controllers/public_view/public_quote_action.php');
        $invoiceEdit = (string)file_get_contents($this->root . '/src/views/pages/invoice/invoices-edit.php');
        self::assertStringContainsString("\$billing_mode==='hourly'?'estimate':'standard'", $quoteCreate);
        self::assertStringContainsString("if((\$it['pricing_status']??'standard')!=='standard')continue", $quoteApprove);
        self::assertStringContainsString("\$invoice_subtotal=\$billing_mode==='hourly'?0.0", $contractCreate);
        self::assertStringContainsString("if(\$billing_mode==='hourly')continue", $contractCreate);
        self::assertStringContainsString("\$invoiceSubtotal=\$billing_mode==='hourly'?0.0", $contractUpdate);
        self::assertStringContainsString("if((\$it['pricing_status']??'standard')!=='standard')continue", $publicQuoteAction);
        self::assertStringContainsString('Contract estimate — reference only', $invoiceEdit);
    }

    public function testAutoLinkRequiresExactlyOneDraftInvoiceForTheJob(): void
    {
        $action = (string)file_get_contents($this->root . '/src/controllers/workforce/action.php');
        $context = (string)file_get_contents($this->root . '/src/services/WorkTimeBillingContextService.php');
        self::assertStringContainsString("WHERE job_id=? AND client_id=? AND status='draft' AND finalized_at IS NULL", $action);
        self::assertStringContainsString('count($candidateIds) !== 1', $action);
        self::assertStringContainsString('Confirm the move before changing its billing context', $context);
    }
}
