<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/recurring_expenses.php';

use PHPUnit\Framework\TestCase;

final class RecurringExpensesTest extends TestCase
{
    private ?PDO $pdo = null;
    /** @var int[] */
    private array $scheduleIds = [];

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
        } catch (Throwable $e) {
            $this->markTestSkipped('MySQL backend unavailable: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!$this->pdo) {
            return;
        }
        foreach (array_reverse($this->scheduleIds) as $id) {
            $this->pdo->prepare('DELETE FROM expenses WHERE recurring_expense_id=?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM recurring_expenses WHERE id=?')->execute([$id]);
        }
    }

    public function testCalendarRecurrenceDoesNotDriftAtMonthOrLeapYearBoundaries(): void
    {
        self::assertSame('2027-02-28', recurring_expense_next_date('2027-01-31', 1, 'month', '2027-01-31'));
        self::assertSame('2027-03-31', recurring_expense_next_date('2027-02-28', 1, 'month', '2027-01-31'));
        self::assertSame('2027-04-30', recurring_expense_next_date('2027-01-31', 3, 'month', '2027-01-31'));
        self::assertSame('2025-02-28', recurring_expense_next_date('2024-02-29', 1, 'year', '2024-02-29'));
        self::assertSame('2026-02-28', recurring_expense_next_date('2025-02-28', 1, 'year', '2024-02-29'));
        self::assertSame('2026-07-17', recurring_expense_next_date('2026-07-10', 1, 'week', '2026-07-10'));
    }

    public function testDueScheduleGeneratesOneImmutableExpenseAndAdvancesIdempotently(): void
    {
        $today = date('Y-m-d');
        $stmt = $this->pdo->prepare('
            INSERT INTO recurring_expenses
                (amount,description,interval_count,interval_unit,start_date,next_expense_date,
                 is_billable,is_tax_deductible,status)
            VALUES (?,?,?,?,?,?,0,1,"active")
        ');
        $stmt->execute([18.99, 'Annual domain renewal - clientdomain.com', 1, 'year', $today, $today]);
        $scheduleId = (int)$this->pdo->lastInsertId();
        $this->scheduleIds[] = $scheduleId;

        $generated = recurring_expense_generate_one($this->pdo, $scheduleId, $today);
        self::assertNotNull($generated);
        self::assertSame($scheduleId, $generated['recurring_expense_id']);
        self::assertSame($today, $generated['scheduled_date']);
        self::assertSame(recurring_expense_next_date($today, 1, 'year', $today), $generated['next_expense_date']);

        $expense = $this->pdo->prepare('SELECT * FROM expenses WHERE id=?');
        $expense->execute([(int)$generated['expense_id']]);
        $expense = $expense->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame($scheduleId, (int)$expense['recurring_expense_id']);
        self::assertSame($today, (string)$expense['recurring_occurrence_date']);
        self::assertSame($today, (string)$expense['expense_date']);
        self::assertSame('Annual domain renewal - clientdomain.com', (string)$expense['description']);
        self::assertEqualsWithDelta(18.99, (float)$expense['total_amount'], 0.005);
        self::assertSame('confirmed', (string)$expense['status']);

        self::assertNull(recurring_expense_generate_one($this->pdo, $scheduleId, $today));
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM expenses WHERE recurring_expense_id=?');
        $count->execute([$scheduleId]);
        self::assertSame(1, (int)$count->fetchColumn());

        $schedule = $this->pdo->prepare('SELECT generated_count,last_generated_date,next_expense_date,status FROM recurring_expenses WHERE id=?');
        $schedule->execute([$scheduleId]);
        $schedule = $schedule->fetch(PDO::FETCH_ASSOC) ?: [];
        self::assertSame(1, (int)$schedule['generated_count']);
        self::assertSame($today, (string)$schedule['last_generated_date']);
        self::assertSame($generated['next_expense_date'], (string)$schedule['next_expense_date']);
        self::assertSame('active', (string)$schedule['status']);
    }

    public function testEndedScheduleGeneratesFinalOccurrenceAndStops(): void
    {
        $today = date('Y-m-d');
        $stmt = $this->pdo->prepare('
            INSERT INTO recurring_expenses
                (amount,description,interval_count,interval_unit,start_date,next_expense_date,end_date,status)
            VALUES (25,"Monthly software",1,"month",?,?,?,"active")
        ');
        $stmt->execute([$today, $today, $today]);
        $scheduleId = (int)$this->pdo->lastInsertId();
        $this->scheduleIds[] = $scheduleId;

        $generated = recurring_expense_generate_one($this->pdo, $scheduleId, $today);
        self::assertNotNull($generated);
        self::assertSame('ended', $generated['status']);
        self::assertNull($generated['next_expense_date']);
        self::assertNull(recurring_expense_generate_one($this->pdo, $scheduleId, $today));
    }

    public function testRecurringExpenseWorkflowIsMigratedRoutedVisibleAndScheduled(): void
    {
        $root = dirname(__DIR__, 2);
        $baseline = (string)file_get_contents($root . '/database/baseline.sql');
        $migration = (string)file_get_contents($root . '/database/migrations/0033_recurring_expenses.sql');
        $router = (string)file_get_contents($root . '/public/index.php');
        $acl = (string)file_get_contents($root . '/src/utils/acl_middleware.php');
        $hub = (string)file_get_contents($root . '/src/views/pages/financial/expenses-list.php');
        $tab = (string)file_get_contents($root . '/src/views/pages/financial/_recurring_expenses_tab.php');
        $form = (string)file_get_contents($root . '/src/views/pages/financial/recurring-expense-form.php');
        $handler = (string)file_get_contents($root . '/src/controllers/financial/recurring_expense_handler.php');
        $cron = (string)file_get_contents($root . '/src/cron/generate_recurring_expenses.php');
        $crontab = (string)file_get_contents($root . '/cron/crontab');

        foreach (['recurring_expenses', 'recurring_expense_id', 'recurring_occurrence_date', 'uq_exp_recurring_occurrence'] as $token) {
            self::assertStringContainsString($token, $baseline);
            self::assertStringContainsString($token, $migration);
        }
        self::assertStringContainsString("'financial/recurring-expense-handler'", $router);
        self::assertStringContainsString("'financial/recurring-expense-form' => 'financial.manage'", $acl);
        self::assertStringContainsString("'recurring'", $hub);
        self::assertStringContainsString('Annual forecast', $tab);
        self::assertStringContainsString('Generate Due', $tab);
        self::assertStringContainsString('name="frequency"', $form);
        self::assertStringContainsString('name="next_expense_date"', $form);
        self::assertStringContainsString('INSERT INTO recurring_expenses', $handler);
        self::assertStringContainsString("'yearly' => [1, 'year', 'Yearly']", $handler);
        self::assertStringContainsString("'generate_due'", $handler);
        self::assertStringContainsString('recurring_expense_process_due', $cron);
        self::assertStringContainsString('generate_recurring_expenses.php', $crontab);
    }
}
