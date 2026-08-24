<?php
declare(strict_types=1);
namespace Tests\Database;
use PHPUnit\Framework\TestCase;
final class PricingAdjustmentMigrationTest extends TestCase
{
    public function testFoundationIsAdditiveScopedAndDefaultOff(): void
    {
        $root=dirname(__DIR__,2);$sql=(string)file_get_contents($root.'/database/migrations/0072_pricing_adjustment_foundation.sql');
        foreach(['pricing_adjustment_definitions','project_pricing_adjustment_assignments','contract_pricing_adjustment_assignments','document_pricing_adjustment_overrides','document_pricing_adjustment_snapshots'] as $table) self::assertStringContainsString('CREATE TABLE IF NOT EXISTS '.$table,$sql);
        self::assertGreaterThanOrEqual(5,substr_count($sql,'organization_id INT NOT NULL'));
        self::assertStringContainsString("(0,'pricing_adjustments_enabled','0')",$sql);
        self::assertStringContainsString("table_name='project_invoices' AND column_name='revision_number'",$sql);
        self::assertStringContainsString('ALTER TABLE project_invoices ADD COLUMN revision_number',$sql);
        self::assertStringNotContainsString('UPDATE contracts',$sql);
        self::assertStringNotContainsString('UPDATE invoices',$sql);
        self::assertStringNotContainsString('INSERT INTO project_pricing_adjustment_assignments',$sql);
        self::assertStringContainsString('UNIQUE KEY uq_document_pricing_snapshot (document_type,document_id,document_revision)',$sql);
    }
    public function testScopeMigrationIsRetrySafeAndPreservesCustomerRows(): void
    {
        $sql=(string)file_get_contents(dirname(__DIR__,2).'/database/migrations/0073_pricing_adjustment_scopes.sql');
        self::assertStringContainsString("scope_type ENUM('installation','customer')",$sql);
        self::assertStringContainsString("scope_key=IF(organization_id IS NULL,'installation',CONCAT('customer:',organization_id))",$sql);
        self::assertStringContainsString('uq_pricing_adjustment_scope_name (scope_key,name)',$sql);
        self::assertStringContainsString('MODIFY organization_id INT NULL',$sql);
        self::assertStringContainsString('information_schema.columns',$sql);
        self::assertStringContainsString('information_schema.statistics',$sql);
        self::assertStringContainsString('information_schema.table_constraints',$sql);
        self::assertStringContainsString("scope_type='customer' AND organization_id IS NOT NULL",$sql);
        self::assertStringContainsString("scope_type='installation' AND organization_id IS NULL",$sql);
        self::assertStringNotContainsString('ALTER TABLE system_audit',$sql);
    }
    public function testSnapshotLineageMigrationIsRetrySafeAndImmutable(): void
    {
        $sql=(string)file_get_contents(dirname(__DIR__,2).'/database/migrations/0074_pricing_snapshot_lineage.sql');
        self::assertStringContainsString("COLUMN_NAME='derived_from_snapshot_id'",$sql);
        self::assertStringContainsString('ADD COLUMN derived_from_snapshot_id BIGINT UNSIGNED NULL',$sql);
        self::assertStringContainsString('idx_pricing_snapshot_lineage (derived_from_snapshot_id)',$sql);
        self::assertStringContainsString('fk_pricing_snapshot_lineage',$sql);
        self::assertStringContainsString('ON DELETE RESTRICT',$sql);
        self::assertGreaterThanOrEqual(3,substr_count($sql,'INFORMATION_SCHEMA.'));
    }
    public function testOnDemandGenerationMigrationIsRetrySafeAndUnique(): void
    {
        $sql=(string)file_get_contents(dirname(__DIR__,2).'/database/migrations/0075_on_demand_invoice_idempotency.sql');
        self::assertStringContainsString("COLUMN_NAME='generation_key'",$sql);
        self::assertStringContainsString('ADD COLUMN generation_key CHAR(64)',$sql);
        self::assertStringContainsString('ADD UNIQUE KEY uq_invoice_generation_key (generation_key)',$sql);
        self::assertStringContainsString('INFORMATION_SCHEMA.COLUMNS',$sql);
        self::assertStringContainsString('INFORMATION_SCHEMA.STATISTICS',$sql);
    }
    public function testInvoiceAdjustmentCalculationRoleIsRetrySafeAndHistoricalRowsStayInformational(): void
    {
        $sql=(string)file_get_contents(dirname(__DIR__,2).'/database/migrations/0076_invoice_adjustment_calculation_role.sql');
        self::assertStringContainsString("COLUMN_NAME='affects_total'",$sql);
        self::assertStringContainsString('ADD COLUMN affects_total TINYINT(1) NOT NULL DEFAULT 0',$sql);
        self::assertStringContainsString('idx_invoice_adjustment_total_role (invoice_id,affects_total,superseded_at,id)',$sql);
        self::assertStringContainsString('INFORMATION_SCHEMA.COLUMNS',$sql);
        self::assertStringContainsString('INFORMATION_SCHEMA.STATISTICS',$sql);
        self::assertStringNotContainsString('UPDATE invoice_adjustments',$sql);
    }
    public function testContractSettlementFoundationIsDefaultOffRetrySafeAndNonMutating(): void
    {
        $sql=(string)file_get_contents(dirname(__DIR__,2).'/database/migrations/0077_contract_settlement_closeout_foundation.sql');
        self::assertStringContainsString("COLUMN_TYPE LIKE '%''closing''%'",$sql);
        self::assertStringContainsString("'closing'",$sql);
        foreach(['contract_settlement_terms','contract_settlements','contract_settlement_lines'] as $table){
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS '.$table,$sql);
        }
        self::assertStringContainsString("(0,'contract_settlement_enabled','0')",$sql);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE config_value=config_value',$sql);
        self::assertStringContainsString('UNIQUE KEY uq_contract_settlements_request (contract_id,request_key)',$sql);
        self::assertStringContainsString('UNIQUE KEY uq_contract_settlements_basis (contract_id,basis_hash)',$sql);
        self::assertStringContainsString('UNIQUE KEY uq_contract_settlements_draft_invoice (draft_invoice_id)',$sql);
        self::assertStringContainsString('UNIQUE KEY uq_contract_settlement_line_source (settlement_id,source_invoice_id,source_revision)',$sql);
        self::assertStringContainsString('target_definition_id IS NOT NULL',$sql);
        self::assertStringContainsString('delta_minor=target_total_minor-historical_total_minor',$sql);
        self::assertStringContainsString('ON DELETE RESTRICT',$sql);
        self::assertStringContainsString('ON DELETE CASCADE',$sql);
        self::assertStringContainsString('INFORMATION_SCHEMA.COLUMNS',$sql);
        self::assertStringNotContainsString('UPDATE contracts SET',$sql);
        self::assertStringNotContainsString('UPDATE invoices SET',$sql);
        self::assertStringNotContainsString('INSERT INTO contract_settlement_terms',$sql);
        self::assertStringNotContainsString('INSERT INTO contract_settlements',$sql);
    }
}
