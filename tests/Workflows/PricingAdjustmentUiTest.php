<?php
declare(strict_types=1);

namespace Tests\Workflows;

use PDO;
use PHPUnit\Framework\TestCase;

final class PricingAdjustmentUiTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/src/utils/document_pricing_adjustments.php';
    }

    public function testClientRowUsesImmutableAmountAndNeverLeaksPrivateProvenance(): void
    {
        $snapshot = [
            'adjustment_minor' => 123456,
            'currency' => 'EUR',
            'adjustment_name' => 'Private negotiated agreement',
            'source_type' => 'document_override',
            'source_assignment_id' => 77,
            'override_reason' => 'Internal retention reason',
        ];
        $row = pricing_adjustment_client_row($snapshot, 'padding:10px', 'font-weight:600', 2);
        self::assertStringContainsString('Pricing adjustment', $row);
        self::assertStringContainsString('-EUR 1,234.56', $row);
        self::assertSame(4, substr_count($row, '<td'));
        foreach (['Private negotiated agreement', 'document_override', '77', 'Internal retention reason'] as $private) {
            self::assertStringNotContainsString($private, $row);
        }
        self::assertStringContainsString('-$12.34', pricing_adjustment_client_row(['adjustment_minor' => 1234, 'currency' => 'USD']));
        self::assertSame('', pricing_adjustment_client_row(['adjustment_minor' => 0, 'currency' => 'USD']));
    }

    public function testInvoiceTotalAdjustmentRowsAreScopedFilteredAndClientSafe(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE pricing_adjustment_definitions(id INTEGER,scope_type TEXT,scope_key TEXT,is_active INTEGER); CREATE TABLE project_pricing_adjustment_assignments(id INTEGER); CREATE TABLE contract_pricing_adjustment_assignments(id INTEGER); CREATE TABLE document_pricing_adjustment_overrides(id INTEGER); CREATE TABLE document_pricing_adjustment_snapshots(id INTEGER,adjustment_minor INTEGER,derived_from_snapshot_id INTEGER); CREATE TABLE invoices(id INTEGER PRIMARY KEY,organization_id INTEGER); CREATE TABLE invoice_adjustments(id INTEGER PRIMARY KEY,invoice_id INTEGER,adjustment_type TEXT,amount TEXT,affects_total INTEGER,superseded_at TEXT); INSERT INTO invoices VALUES(1,7),(2,NULL),(3,8); INSERT INTO invoice_adjustments VALUES(1,1,'charge','10.00',1,NULL),(2,1,'charge','5.00',1,NULL),(3,1,'credit','2.50',1,NULL),(4,1,'charge','99.00',0,NULL),(5,1,'credit','88.00',1,'2026-08-01'),(6,2,'charge','3.00',1,NULL),(7,3,'charge','4.00',1,NULL)");
        $rows = pricing_invoice_total_adjustments($pdo, 7, 1);
        self::assertCount(3, $rows);
        self::assertSame([], pricing_invoice_total_adjustments($pdo, 7, 3), 'Cross-organization invoice rows must fail closed.');
        self::assertCount(1, pricing_invoice_total_adjustments($pdo, null, 2), 'General-recipient invoices retain null-organization containment.');
        $rows[0]['private_note'] = 'Do not expose negotiated reason';
        $html = pricing_invoice_adjustment_client_rows($rows, 'EUR');
        self::assertStringContainsString('Invoice charge', $html);
        self::assertStringContainsString('EUR 15.00', $html);
        self::assertStringContainsString('Invoice credit', $html);
        self::assertStringContainsString('-EUR 2.50', $html);
        self::assertStringNotContainsString('99.00', $html);
        self::assertStringNotContainsString('88.00', $html);
        self::assertStringNotContainsString('Do not expose negotiated reason', $html);
        $helper = $this->source('src/utils/document_pricing_adjustments.php');
        self::assertStringContainsString('SELECT ia.adjustment_type,ia.amount', $helper);
        self::assertStringContainsString('ia.affects_total=1', $helper);
        self::assertStringContainsString('ia.superseded_at IS NULL', $helper);
        self::assertStringContainsString('i.organization_id IS NULL AND ? IS NULL', $helper);
        $view = $this->source('src/views/pages/invoice/invoice-details.php');
        self::assertStringContainsString('pricing_invoice_adjustment_client_rows($invoiceTotalAdjustments,$invoiceCurrency)', $view);
        self::assertLessThan(strpos($view, "'Invoice Total' : 'Total'"), strpos($view, 'pricing_invoice_adjustment_client_rows'));
    }

    public function testFeatureAndPartialSchemaFailClosed(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE app_config(organization_id INTEGER,config_key TEXT,config_value TEXT); INSERT INTO app_config VALUES(0,'pricing_adjustments_enabled','0')");
        self::assertFalse(pricing_adjustments_enabled($pdo));
        $pdo->exec("UPDATE app_config SET config_value='1'; CREATE TABLE pricing_adjustment_definitions(id INTEGER,scope_type TEXT,scope_key TEXT,is_active INTEGER); CREATE TABLE project_pricing_adjustment_assignments(id INTEGER); CREATE TABLE contract_pricing_adjustment_assignments(id INTEGER); CREATE TABLE document_pricing_adjustment_overrides(id INTEGER); CREATE TABLE document_pricing_adjustment_snapshots(id INTEGER,adjustment_minor INTEGER)");
        self::assertTrue(pricing_adjustments_enabled($pdo));
        self::assertFalse(pricing_adjustment_schema_available($pdo), '0074/0076 columns must be present before the UI enables mutations.');
    }

    public function testEveryClientDocumentSurfaceUsesGenericRowAndStaffOnlyProvenance(): void
    {
        $views = [
            'src/views/pages/quote/quote-details.php',
            'src/views/pages/quote/long-term-quote-details.php',
            'src/views/pages/contract/contract-details.php',
            'src/views/pages/contract/long-term-contract-details.php',
            'src/views/pages/invoice/invoice-details.php',
        ];
        foreach ($views as $view) {
            $source = $this->source($view);
            self::assertStringContainsString('pricing_adjustment_client_row', $source, $view);
            self::assertMatchesRegularExpression("/!defined\('PDF_MODE'\).*?!defined\('PUBLIC_VIEW'\).*?pricing_adjustment_staff_provenance/s", $source, $view);
        }
        $public = $this->source('src/views/public/doc-wrapper.php');
        self::assertStringContainsString('long-term-quote-details.php', $public);
        self::assertStringContainsString('long-term-contract-details.php', $public);
        $attachment = $this->source('src/utils/document_pdf.php');
        self::assertStringContainsString('long-term-quote-details.php', $attachment);
        self::assertStringContainsString('long-term-contract-details.php', $attachment);
    }

    public function testManagementHandlerAndViewsKeepFinancialMutationsExplicit(): void
    {
        $handler = $this->source('src/controllers/settings/pricing_adjustments_handler.php');
        foreach (['csrf_validate()', "'financial.manage'", 'catch(DomainException', 'Unable to update pricing adjustments.', 'strlen($return)>2048', 'str_contains($return,"\\r")'] as $needle) {
            self::assertStringContainsString($needle, $handler);
        }
        self::assertStringContainsString("'deactivate'", $handler);
        self::assertStringNotContainsString("'delete'", $handler);
        $settings = $this->source('src/views/pages/settings/pricing-adjustments.php');
        self::assertStringContainsString('Pricing adjustments are off', $settings);
        self::assertStringContainsString('Database update required', $settings);
        self::assertStringContainsString('min="0.0001"', $settings);
        self::assertStringNotContainsString('Â', $settings);
        $css = $this->source('public/assets/settings.css');
        foreach (['.pricing-definition-actions-wrap', '.pricing-assignment-panel', '.pricing-override-panel', '.pricing-provenance', '@media(max-width:760px)'] as $selector) {
            self::assertStringContainsString($selector, $css);
        }
    }

    private function source(string $relative): string
    {
        $source = file_get_contents($this->root . '/' . $relative);
        self::assertNotFalse($source, $relative);
        return (string)$source;
    }
}
