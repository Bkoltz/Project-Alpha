<?php

declare(strict_types=1);

use App\Services\ManualPaymentJobService;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/ManualPaymentJobService.php';

final class ManualPaymentJobWorkflowTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testExpectedChargeSupportsHourlyFixedAndBaseOverageServices(): void
    {
        self::assertSame(400.0, ManualPaymentJobService::calculateComponentCharge([
            'treatment' => 'hourly',
            'rate' => 100,
            'planned_quantity' => 1,
        ], 4 * 3600));

        self::assertSame(350.0, ManualPaymentJobService::calculateComponentCharge([
            'treatment' => 'fixed_price_included',
            'base_price' => 350,
            'planned_quantity' => 1,
        ], 15 * 60));

        $deerRecovery = [
            'treatment' => 'base_overage',
            'base_price' => 350,
            'included_minutes' => 60,
            'overage_rate' => 50,
            'planned_quantity' => 1,
        ];
        self::assertSame(350.0, ManualPaymentJobService::calculateComponentCharge($deerRecovery, 60 * 60));
        self::assertSame(362.5, ManualPaymentJobService::calculateComponentCharge($deerRecovery, 75 * 60));
        self::assertSame(400.0, ManualPaymentJobService::calculateComponentCharge($deerRecovery, 120 * 60));

        self::assertSame(0.0, ManualPaymentJobService::calculateComponentCharge([
            'treatment' => 'internal',
            'rate' => 100,
            'planned_quantity' => 1,
        ], 4 * 3600));
    }

    public function testManualPaymentControllerAllowsClientlessIncomeAndSecuresJobLinks(): void
    {
        $controller = file_get_contents($this->root . '/src/controllers/payments_create.php');

        self::assertStringContainsString("\$job_id_input = (int)(\$_POST['job_id'] ?? 0)", (string)$controller);
        self::assertStringContainsString("can_access_record(\$pdo, 'clients'", (string)$controller);
        self::assertStringContainsString('->accessibleJob($job_id_input, $userId)', (string)$controller);
        self::assertStringContainsString('The selected client does not match the service job.', (string)$controller);
        self::assertStringContainsString('(client_id, invoice_id, job_id, contract_id, organization_id', (string)$controller);
        self::assertStringContainsString("'variance' => \$expectedCharge !== null", (string)$controller);
        self::assertStringNotContainsString('Select a client for manual payments.', (string)$controller);
        self::assertStringContainsString('$send_receipt = $send_receipt && $clientEmail !== null;', (string)$controller);
    }

    public function testManualPaymentUiSearchesJobsShowsVarianceAndDisablesUnavailableEmail(): void
    {
        $view = file_get_contents($this->root . '/src/views/pages/payments/payments-create.php');
        $script = file_get_contents($this->root . '/public/assets/js/payments-create-logic.js');
        $list = file_get_contents($this->root . '/src/views/pages/payments/payments-list.php');

        self::assertStringContainsString('id="manualJobSearch"', (string)$view);
        self::assertStringContainsString('name="job_id" id="manualJobSelect"', (string)$view);
        self::assertStringContainsString('Expected service charge', (string)$view);
        self::assertStringContainsString('Client <span style="color:var(--muted);font-weight:400">(optional)', (string)$view);
        self::assertStringContainsString('No service job — standalone income', (string)$view);

        self::assertStringContainsString('function updateJobVariance()', (string)$script);
        self::assertStringContainsString('This is a warning only; the amount actually received will be saved.', (string)$script);
        self::assertStringContainsString('sendReceiptInput.disabled = !hasEmail;', (string)$script);
        self::assertStringNotContainsString('Choose a client from the search results.', (string)$script);

        self::assertStringContainsString('LEFT JOIN jobs j ON j.id=p.job_id', (string)$list);
        self::assertStringContainsString('>Service Job</th>', (string)$list);
        self::assertStringContainsString("\$r['job_code']", (string)$list);
    }
}
