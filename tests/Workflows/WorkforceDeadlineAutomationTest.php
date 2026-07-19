<?php

declare(strict_types=1);

use App\Services\PayPeriodDeadlineService;
use PHPUnit\Framework\TestCase;

final class WorkforceDeadlineAutomationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testDeadlineServiceAndCronAreAvailable(): void
    {
        self::assertTrue(class_exists(PayPeriodDeadlineService::class));
        self::assertFileExists($this->root . '/src/cron/process_workforce_deadlines.php');
    }

    public function testRemindersAndAutomaticConfirmationAreIdempotent(): void
    {
        $source = (string)file_get_contents($this->root . '/src/services/PayPeriodDeadlineService.php');
        self::assertStringContainsString('private const REMINDER_HOURS = [4, 2, 1]', $source);
        self::assertStringContainsString('INSERT IGNORE INTO workforce_deadline_events', $source);
        self::assertStringContainsString("workflow_status IN ('draft','returned')", $source);
        self::assertStringContainsString("workflow_status='submitted'", $source);
        self::assertStringContainsString('Automatically confirmed at the configured deadline.', $source);
    }

    public function testMigrationAddsDeadlineConfigurationAndEventLedger(): void
    {
        $migration = (string)file_get_contents($this->root . '/database/migrations/0053_workforce_deadline_automation.sql');
        self::assertStringContainsString('workforce_period_deadline_time', $migration);
        self::assertStringContainsString('workforce_period_auto_confirm', $migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS workforce_deadline_events', $migration);
        self::assertStringContainsString('uq_workforce_deadline_event', $migration);
        self::assertStringContainsString('process_workforce_deadlines', $migration);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE config_value=config_value', $migration);
    }

    public function testLocalPayPeriodDatesBecomeExclusiveUtcBoundsAcrossDst(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
        $service = new PayPeriodDeadlineService(new PDO('sqlite::memory:'));
        $timezone = new ReflectionProperty($service, 'timezoneName');
        $timezone->setValue($service, 'America/Chicago');
        $bounds = new ReflectionMethod($service, 'periodUtcBounds');
        $bounds->setAccessible(true);

        self::assertSame(
            ['2026-03-08 06:00:00.000000', '2026-03-09 05:00:00.000000'],
            $bounds->invoke($service, ['period_start' => '2026-03-08', 'period_end' => '2026-03-08'])
        );
    }

    public function testReminderAndConfirmationQueriesUseLocalPeriodUtcRange(): void
    {
        $source = (string)file_get_contents($this->root . '/src/services/PayPeriodDeadlineService.php');
        self::assertStringContainsString('t.start_time>=? AND t.start_time<?', $source);
        self::assertStringContainsString('start_time>=? AND start_time<?', $source);
        self::assertStringNotContainsString('DATE(t.start_time)', $source);
        self::assertStringNotContainsString("s.status IN ('submitted','accepted','adjusted')", $source);
    }
}
