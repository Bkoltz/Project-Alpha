<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/AutoPayBetaService.php';

final class AutoPayBetaTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('AUTOPAY_BETA_ENABLED');
        putenv('APP_ENV');
    }

    public function testBetaIsDisabledByDefault(): void
    {
        putenv('AUTOPAY_BETA_ENABLED');
        putenv('APP_ENV');
        self::assertFalse(autopay_beta_enabled());
    }

    public function testProductionCannotEnableBeta(): void
    {
        putenv('AUTOPAY_BETA_ENABLED=true');
        putenv('APP_ENV=production');
        self::assertFalse(autopay_beta_enabled());
    }

    public function testInternalInterfaceCanBeExercisedOnlyInTest(): void
    {
        putenv('AUTOPAY_BETA_ENABLED=true');
        putenv('APP_ENV=test');

        $processor = new class implements AutoPayBetaProcessorInterface {
            public function schedule(array $authorization, array $invoice): string
            {
                return 'fake-attempt-' . $invoice['id'];
            }
        };

        $service = new AutoPayBetaService($processor);
        self::assertSame('fake-attempt-42', $service->schedule(['id' => 1], ['id' => 42]));
    }

    public function testServiceConstructionFailsClosed(): void
    {
        putenv('AUTOPAY_BETA_ENABLED=false');
        putenv('APP_ENV=test');

        $processor = new class implements AutoPayBetaProcessorInterface {
            public function schedule(array $authorization, array $invoice): string
            {
                return 'unreachable';
            }
        };

        $this->expectException(RuntimeException::class);
        new AutoPayBetaService($processor);
    }
}
