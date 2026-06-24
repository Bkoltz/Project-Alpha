<?php
// tests/Surcharge/SurchargeCapTest.php
require_once __DIR__ . '/../../src/utils/StripeFeeCalculator.php';

use PHPUnit\Framework\TestCase;

class SurchargeCapTest extends TestCase {

    public function testClientModeCappedAtActualFee(): void {
        $config = [
            'stripe_surcharge_type' => 'client',
            'stripe_surcharge_percent' => 4.0,
            'stripe_surcharge_fixed' => 0.30,
        ];
        $amount = 100.0;
        $actualFee = 2.90;

        $result = StripeFeeCalculator::calculateSurcharge($amount, $config, $actualFee);

        $this->assertSame('actual', $result['fee_source']);
        $this->assertSame(2.90, $result['client_pays']);
        $this->assertSame(0.00, $result['merchant_pays']);
    }

    public function testSplitUsesActualFee(): void {
        $config = [
            'stripe_surcharge_type' => 'split',
            'stripe_surcharge_split_percent' => 50,
            'stripe_surcharge_percent' => 2.9,
            'stripe_surcharge_fixed' => 0.30,
        ];
        $amount = 100.0;
        $actualFee = 3.00;

        $result = StripeFeeCalculator::calculateSurcharge($amount, $config, $actualFee);

        $this->assertSame('actual', $result['fee_source']);
        $this->assertSame(1.50, $result['client_pays']);
        $this->assertSame(1.50, $result['merchant_pays']);
    }

    public function testFallsBackToEstimate(): void {
        $config = [
            'stripe_surcharge_type' => 'client',
            'stripe_surcharge_percent' => 2.9,
            'stripe_surcharge_fixed' => 0.30,
        ];
        $amount = 100.0;

        $result = StripeFeeCalculator::calculateSurcharge($amount, $config, null);

        $this->assertSame('estimate', $result['fee_source']);
        $this->assertSame(3.20, $result['client_pays']);
    }
}
