<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/services/DocumentRevisionService.php';
require_once dirname(__DIR__, 2) . '/src/utils/invoice_lifecycle.php';

use PHPUnit\Framework\TestCase;

final class BusinessIntegrationsFoundationTest extends TestCase
{
    public function testResendIsRequiredOnlyAfterDeliveredRevisionChanges(): void
    {
        self::assertFalse(DocumentRevisionService::requiresResend([
            'revision_number' => 1,
            'last_sent_revision' => null,
        ]));
        self::assertFalse(DocumentRevisionService::requiresResend([
            'revision_number' => 2,
            'last_sent_revision' => 2,
        ]));
        self::assertTrue(DocumentRevisionService::requiresResend([
            'revision_number' => 3,
            'last_sent_revision' => 2,
        ]));
    }

    public function testInvoiceBalanceKeepsEffectiveOverpaymentInsteadOfClamping(): void
    {
        self::assertSame(['paid', 125.0, 0.0], invoice_status_for_balance(100.0, 125.0));
        self::assertSame(['partial', 40.0, 60.0], invoice_status_for_balance(100.0, 40.0));
        self::assertSame(['unpaid', 0.0, 100.0], invoice_status_for_balance(100.0, 0.0));
    }

    public function testApiAndReadinessFailuresAreStructured(): void
    {
        $root = dirname(__DIR__, 2);
        $readiness = (string) file_get_contents($root . '/src/controllers/health/ready.php');
        $mileage = (string) file_get_contents($root . '/src/controllers/financial/mileage_unbilled.php');
        self::assertStringContainsString("schema_out_of_date", $readiness);
        self::assertStringContainsString('PA_REQUIRED_SCHEMA_VERSION = 75', $readiness);
        foreach(['pricing_adjustment_definitions','project_pricing_adjustment_assignments','contract_pricing_adjustment_assignments','document_pricing_adjustment_overrides','document_pricing_adjustment_snapshots','contract_settlement_terms','contract_settlements','contract_settlement_lines'] as $table)self::assertStringContainsString("'{$table}'",$readiness);
        foreach(['generation_key','affects_total','scope_type','scope_key','derived_from_snapshot_id','policy_mode','request_key','basis_hash','source_pricing_snapshot_id','missing_columns'] as $column)self::assertStringContainsString($column,$readiness);
        self::assertStringContainsString("api_json_failure(503,'schema_out_of_date'", $mileage);
        self::assertStringContainsString("'request_id'", (string) file_get_contents($root . '/src/utils/api_response.php'));
    }

    public function testEmailUsesOneActiveProviderAndNoAutomaticFallback(): void
    {
        $root = dirname(__DIR__, 2);
        $manager = (string) file_get_contents($root . '/src/services/EmailProviderManager.php');
        $service = (string) file_get_contents($root . '/src/services/EmailService.php');
        self::assertStringContainsString('JOIN email_provider_connections c ON c.id=s.active_connection_id', $manager);
        self::assertDoesNotMatchRegularExpression('/(?<![A-Za-z])mail\s*\(/', $service);
        self::assertDoesNotMatchRegularExpression('/(?<![A-Za-z])smtp_send\s*\(/', $service);
    }

    public function testAddressAssistanceRunsAfterAjaxNavigation(): void
    {
        $navigation = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/navigation.js');
        self::assertStringContainsString("pageInitializers.get('*')", $navigation);
        self::assertStringContainsString("normalizedPages.includes('*')", $navigation);
    }
}
