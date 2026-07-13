<?php

declare(strict_types=1);

use App\Modules\Timekeeping\DecimalMoney;
use App\Modules\Timekeeping\Uuid;
use PHPUnit\Framework\TestCase;

final class UnifiedAlphaLedgerModuleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testMoneyUsesFixedPrecisionDecimalArithmetic(): void
    {
        self::assertSame('32.50', DecimalMoney::payAmount(3600, '32.5000'));
        self::assertSame('16.25', DecimalMoney::payAmount(1800, '32.5000'));
        self::assertSame('0.01', DecimalMoney::payAmount(1, '36.0000'));
    }

    public function testDomainIdsAreUuidV4(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Uuid::v4());
    }

    public function testForwardMigrationCreatesCanonicalModuleBoundaries(): void
    {
        $sql = (string) file_get_contents($this->root . '/database/migrations/0039_unified_workforce_timekeeping.sql');
        foreach ([
            'business_settings','employee_profiles','project_assignments','work_time_entries','work_time_breaks',
            'work_timer_locks','work_break_locks','work_time_revisions','work_approval_snapshots','work_pay_accruals',
            'work_billing_consumptions','app_sessions','background_jobs',
        ] as $table) {
            self::assertStringContainsString($table, $sql);
        }
        self::assertStringContainsString("'employee'", $sql);
        self::assertStringContainsString('timekeeping.self', $sql);
        self::assertStringContainsString('approvals.review', $sql);
    }

    public function testBillingConsumesSnapshotsWithoutRewritingWorkEntries(): void
    {
        $consumer = (string) file_get_contents($this->root . '/src/Modules/Timekeeping/BillingTimeConsumer.php');
        self::assertStringContainsString('INSERT INTO time_entries', $consumer);
        self::assertStringNotContainsString('UPDATE work_time_entries', $consumer);
        self::assertStringContainsString("'correction'", $consumer);
        self::assertStringContainsString("'void'", $consumer);
    }

    public function testStandaloneIntegrationRuntimeIsNotRouted(): void
    {
        $front = (string) file_get_contents($this->root . '/public/index.php');
        $scopes = (string) file_get_contents($this->root . '/src/utils/api_scopes.php');
        $cron = (string) file_get_contents($this->root . '/cron/crontab');
        self::assertStringNotContainsString("\$page === 'api-v1-alphaledger'", $front);
        self::assertStringNotContainsString("'alphaledger.sync'", $scopes);
        self::assertStringNotContainsString('sync_alphaledger.php', $cron);
        self::assertStringContainsString("'/time' => 'workforce/time'", $front);
    }

    public function testSessionAndAclSecurityInvariantsAreExplicit(): void
    {
        $front = (string) file_get_contents($this->root . '/public/index.php');
        $handler = (string) file_get_contents($this->root . '/src/Security/DatabaseSessionHandler.php');
        $policy = (string) file_get_contents($this->root . '/src/utils/two_factor_policy.php');
        self::assertStringContainsString("'samesite' => 'Strict'", $front);
        self::assertStringContainsString('$sessionTimeout = 15 * 60', $front);
        self::assertStringContainsString("hash('sha256', \$id)", $handler);
        self::assertStringContainsString('604800', $handler);
        self::assertStringContainsString('approvals.review', $policy);
    }
}
