<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UnifiedServiceActivityWorkflowTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testMigrationAddsServiceActivityBillingAndImmutableJobSnapshots(): void
    {
        $migration = (string)file_get_contents($this->root . '/database/migrations/0050_unified_service_activity_workflow.sql');

        foreach ([
            'client_billing_treatment',
            'client_billing_rate',
            'client_included_minutes',
            'client_overage_rate',
            'client_billing_currency',
            'client_billing_treatment_snapshot',
            'client_billing_rate_snapshot',
            'client_included_minutes_snapshot',
            'client_overage_rate_snapshot',
            'client_billing_currency_snapshot',
        ] as $column) {
            self::assertStringContainsString($column, $migration);
        }

        self::assertStringContainsString("ENUM(''hourly'',''fixed_price_included'',''base_overage'',''internal'')", $migration);
        self::assertStringContainsString("WHEN c.client_billing_treatment IN ('fixed_price_included','base_overage') THEN i.unit_price", $migration);
        self::assertStringContainsString("WHEN i.billing_unit='hour' THEN 'hourly'", $migration);
    }

    public function testMigrationSupportsClientOptionalAdHocJobsAndJobPayments(): void
    {
        $migration = (string)file_get_contents($this->root . '/database/migrations/0050_unified_service_activity_workflow.sql');

        self::assertStringContainsString('jobs MODIFY COLUMN client_id INT NULL', $migration);
        self::assertStringContainsString("job_origin ENUM(''planned'',''unscheduled_time'')", $migration);
        self::assertStringContainsString("source_type ENUM('quote','contract','invoice','catalog','manual','time_entry')", $migration);
        self::assertStringContainsString('payments MODIFY COLUMN client_id INT NULL', $migration);
        self::assertStringContainsString('ADD COLUMN job_id INT NULL AFTER invoice_id', $migration);
        self::assertStringContainsString('fk_payments_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL', $migration);
    }

    public function testServiceLibraryCreatesOrReusesWorkActivitiesWithSeparateRules(): void
    {
        $view = (string)file_get_contents($this->root . '/src/views/pages/settings/item-library.php');
        $handler = (string)file_get_contents($this->root . '/src/controllers/settings/item_library_handler.php');
        $script = (string)file_get_contents($this->root . '/public/assets/js/item-library.js');

        self::assertStringContainsString('<h3>Service Library</h3>', $view);
        self::assertStringContainsString('Create a matching Work Activity', $view);
        self::assertStringContainsString('Client billing for this activity', $view);
        self::assertStringContainsString('Worker compensation for this activity', $view);
        self::assertStringNotContainsString('what the client buys', strtolower($view));

        self::assertStringContainsString("\$requestedWorkType === 'new'", $handler);
        self::assertStringContainsString('SELECT id FROM work_types WHERE LOWER(name)=LOWER(?)', $handler);
        self::assertStringContainsString('INSERT INTO work_types', $handler);
        self::assertStringContainsString('client_billing_treatment=?,client_billing_rate=?', $handler);

        self::assertStringContainsString("addComponent({work_type_id:'new'", $script);
        self::assertStringContainsString('syncClientBillingFields', $script);
        self::assertStringContainsString('data-auto-activity-name', $script);
    }

    public function testDocumentationUsesServiceBusinessTerminology(): void
    {
        $guide = (string)file_get_contents($this->root . '/docs/workflows/service-catalog-and-work-types.md');
        $concepts = (string)file_get_contents($this->root . '/docs/getting-started/core-concepts.md');

        self::assertStringContainsString('# Service Library and Work Activities', $guide);
        self::assertStringContainsString('service a client receives', $guide);
        self::assertStringContainsString('Service price + hourly overage', $guide);
        self::assertStringContainsString('## Services and Work Activities', $concepts);
    }
}
