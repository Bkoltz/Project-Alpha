<?php

declare(strict_types=1);

use App\Modules\Timekeeping\ApprovalService;
use App\Modules\Timekeeping\AuditRecorder;
use App\Modules\Timekeeping\BillingTimeConsumer;
use App\Modules\Timekeeping\TimekeepingService;
use App\Security\DatabaseSessionHandler;
use App\Services\PayPeriodService;
use App\Services\TimeSubmissionService;
use PHPUnit\Framework\TestCase;

final class UnifiedWorkforceDatabaseTest extends TestCase
{
    public function testCompleteWorkforceLifecycleAgainstMySql(): void
    {
        if (getenv('WORKFORCE_DB_TESTS') !== '1') {
            self::markTestSkipped('Set WORKFORCE_DB_TESTS=1 only for an isolated verification database.');
        }

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: 'db', getenv('DB_PORT') ?: '3306', getenv('MYSQL_DATABASE') ?: 'project_alpha'),
            getenv('MYSQL_USER') ?: 'appuser',
            getenv('MYSQL_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $adminId = (int) $pdo->query("SELECT id FROM users WHERE role='admin' AND is_disabled=0 ORDER BY id LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, $adminId, 'Create the isolated first-run administrator before running this test.');

        $suffix = bin2hex(random_bytes(6));
        $employeeId = 0;
        $projectId = 0;
        $workerProfileId = 0;
        $jobId = 0;
        try {
            $pdo->prepare("INSERT INTO users (email,username,password_hash,role) VALUES (?,?,?,'employee')")
                ->execute(["employee-{$suffix}@example.test", "employee-{$suffix}", password_hash('Verification!Pass123', PASSWORD_DEFAULT)]);
            $employeeId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO employee_profiles (user_id,first_name,last_name,hourly_rate,currency) VALUES (?, 'Test', 'Employee', '25.0000', 'USD')")
                ->execute([$employeeId]);
            $pdo->prepare("INSERT INTO worker_profiles (user_id,relationship_type,time_review_policy,compensation_policy,status,display_name,currency) VALUES (?,'employee','manager_review','rules','active','Test Employee','USD')")
                ->execute([$employeeId]);
            $workerProfileId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO team_members (user_id,display_name,email,is_active,profile_source) VALUES (?, 'Test Employee', ?, 1, 'pa')")
                ->execute([$employeeId, "employee-{$suffix}@example.test"]);
            $pdo->prepare("INSERT INTO projects (created_by,name,status) VALUES (?,?,'active')")
                ->execute([$adminId, "Workforce verification {$suffix}"]);
            $projectId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO jobs (project_id,job_code,status,created_by) VALUES (?,?,'active',?)")
                ->execute([$projectId, "QA-{$suffix}", $employeeId]);
            $jobId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO project_assignments (project_id,user_id,pay_rate_override,created_by) VALUES (?,?,'30.0000',?)")
                ->execute([$projectId, $employeeId, $adminId]);
            $settingsStmt = $pdo->prepare(
                'INSERT INTO app_config (organization_id,config_key,config_value) VALUES (0,?,?)
                 ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)'
            );
            foreach ([
                'timezone' => 'UTC',
                'workforce_currency' => 'USD',
                'workforce_default_hourly_rate' => '20.0000',
                'workforce_default_billing_rate' => '100.0000',
                'workforce_require_project' => '1',
                'workforce_require_description' => '1',
            ] as $key => $value) {
                $settingsStmt->execute([$key, $value]);
            }

            require_once dirname(__DIR__, 2) . '/src/utils/acl.php';
            self::assertTrue(user_can($pdo, $employeeId, 'timekeeping.self', 0));
            self::assertTrue(user_can($pdo, $employeeId, 'employee_pay.self', 0));
            self::assertFalse(user_can($pdo, $employeeId, 'billing.view', 0));
            self::assertFalse(user_can($pdo, $employeeId, 'financial.view', 0));
            self::assertFalse(user_can($pdo, $employeeId, 'approvals.review', 0));

            $_SESSION['user'] = ['id' => $employeeId, 'role' => 'employee'];
            $sessions = new DatabaseSessionHandler($pdo, 900, 604800);
            self::assertTrue($sessions->write("verification-{$suffix}", 'session-payload'));
            self::assertSame('session-payload', $sessions->read("verification-{$suffix}"));
            $pdo->prepare('UPDATE app_sessions SET last_activity_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 901 SECOND) WHERE session_hash=?')
                ->execute([hash('sha256', "verification-{$suffix}")]);
            self::assertSame('', $sessions->read("verification-{$suffix}"));
            self::assertSame('1', (string) $this->value($pdo, 'SELECT COUNT(*) FROM app_sessions WHERE session_hash=? AND revoked_at IS NOT NULL AND payload=""', [hash('sha256', "verification-{$suffix}")]));

            $time = new TimekeepingService($pdo, new AuditRecorder($pdo));
            $approval = new ApprovalService($pdo, new AuditRecorder($pdo), new BillingTimeConsumer($pdo));
            $payPeriods = new PayPeriodService($pdo);
            $submissions = new TimeSubmissionService($pdo);

            $timerId = $time->clockIn($employeeId, [
                'project_id' => $projectId,
                'job_id' => $jobId,
                'description' => 'Server-authoritative timer',
                'billing_treatment' => 'ready',
            ]);
            self::assertSame($timerId, $time->running($employeeId)['id']);
            $pdo->prepare('UPDATE work_time_entries SET start_time=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 2 HOUR) WHERE id=?')->execute([$timerId]);
            $breakId = $time->startBreak($employeeId, $timerId);
            $pdo->prepare('UPDATE work_time_breaks SET start_time=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 15 MINUTE) WHERE id=?')->execute([$breakId]);
            $time->endBreak($employeeId, $breakId);
            $time->clockOut($employeeId, $timerId);
            $timerPeriod = $payPeriods->periodFor(new DateTimeImmutable('now', new DateTimeZone('UTC')));
            $submissions->submit((int)$timerPeriod['id'], $workerProfileId, $employeeId, [$timerId], 'Timer ready for review');
            $timerSnapshot = $approval->approve($adminId, $timerId);
            $timerAccrual = $this->row($pdo, 'SELECT hours,rate,amount,status FROM work_pay_accruals WHERE approval_snapshot_id=?', [$timerSnapshot]);
            self::assertSame('1.7500', $timerAccrual['hours']);
            self::assertSame('30.0000', $timerAccrual['rate']);
            self::assertSame('52.50', $timerAccrual['amount']);
            self::assertSame('pending', $timerAccrual['status']);
            $timerBilling = $this->row($pdo, 'SELECT te.hours,te.rate,c.consumption_type FROM work_billing_consumptions c JOIN time_entries te ON te.id=c.billing_time_entry_id WHERE c.approval_snapshot_id=?', [$timerSnapshot]);
            self::assertSame('1.75', $timerBilling['hours']);
            self::assertSame('100.00', $timerBilling['rate']);
            self::assertSame('approved', $timerBilling['consumption_type']);

            $start = new DateTimeImmutable('tomorrow 09:00', new DateTimeZone('UTC'));
            $end = $start->modify('+1 hour');
            $manualId = $time->saveManual($employeeId, [
                'project_id' => $projectId,
                'job_id' => $jobId,
                'start_time' => $start->format('Y-m-d\\TH:i'),
                'end_time' => $end->format('Y-m-d\\TH:i'),
                'description' => 'Manual entry',
                'billing_treatment' => 'ready',
                'is_payable' => 1,
            ]);
            $manualPeriod = $payPeriods->periodFor($start);
            $submissions->submit((int)$manualPeriod['id'], $workerProfileId, $employeeId, [$manualId], 'Manual entry ready for review');
            $approval->reject($adminId, $manualId, 'Needs detail');
            self::assertSame('rejected', $this->value($pdo, 'SELECT status FROM work_time_entries WHERE id=?', [$manualId]));
            $time->reviseRejected($employeeId, $manualId, [
                'project_id' => $projectId,
                'job_id' => $jobId,
                'start_time' => $start->format('Y-m-d\\TH:i'),
                'end_time' => $end->format('Y-m-d\\TH:i'),
                'description' => 'Manual entry with detail',
                'billing_treatment' => 'ready',
                'is_payable' => 1,
            ]);
            $submissions->submit((int)$manualPeriod['id'], $workerProfileId, $employeeId, [$manualId], 'Corrected detail ready for review');
            $firstManualSnapshot = $approval->approve($adminId, $manualId);
            self::assertSame('approved', $this->value($pdo, 'SELECT consumption_type FROM work_billing_consumptions WHERE approval_snapshot_id=?', [$firstManualSnapshot]));

            $correctedEnd = $start->modify('+2 hours');
            $approval->correct($adminId, $manualId, [
                'reason' => 'Correct duration',
                'project_id' => $projectId,
                'job_id' => $jobId,
                'start_time' => $start->format('Y-m-d\\TH:i'),
                'end_time' => $correctedEnd->format('Y-m-d\\TH:i'),
                'description' => 'Corrected manual entry',
                'billable' => 1,
                'is_payable' => 1,
            ]);
            $submissions->submit((int)$manualPeriod['id'], $workerProfileId, $employeeId, [$manualId], 'Duration correction ready for review');
            $correctedSnapshot = $approval->approve($adminId, $manualId);
            self::assertSame('voided', $this->value($pdo, 'SELECT status FROM work_pay_accruals WHERE approval_snapshot_id=?', [$firstManualSnapshot]));
            self::assertSame('60.00', $this->value($pdo, 'SELECT amount FROM work_pay_accruals WHERE approval_snapshot_id=?', [$correctedSnapshot]));
            self::assertSame('correction', $this->value($pdo, "SELECT consumption_type FROM work_billing_consumptions WHERE approval_snapshot_id=? AND consumption_type='correction'", [$correctedSnapshot]));
            self::assertSame('reversal', $this->value($pdo, "SELECT consumption_type FROM work_billing_consumptions WHERE approval_snapshot_id=? AND consumption_type='reversal'", [$firstManualSnapshot]));

            $approval->void($adminId, $manualId, 'Duplicate time');
            self::assertSame('voided', $this->value($pdo, 'SELECT status FROM work_time_entries WHERE id=?', [$manualId]));
            self::assertSame('voided', $this->value($pdo, 'SELECT status FROM work_pay_accruals WHERE approval_snapshot_id=?', [$correctedSnapshot]));
            self::assertSame('void', $this->value($pdo, "SELECT consumption_type FROM work_billing_consumptions WHERE approval_snapshot_id=? AND consumption_type='void'", [$correctedSnapshot]));

            $cancelId = $time->saveManual($employeeId, [
                'project_id' => $projectId,
                'job_id' => $jobId,
                'start_time' => $start->modify('+1 day')->format('Y-m-d\\TH:i'),
                'end_time' => $end->modify('+1 day')->format('Y-m-d\\TH:i'),
                'description' => 'Cancel me',
                'is_payable' => 1,
            ]);
            $time->cancel($employeeId, $cancelId);
            self::assertSame('cancelled', $this->value($pdo, 'SELECT status FROM work_time_entries WHERE id=?', [$cancelId]));
        } finally {
            if ($employeeId > 0) {
                $pdo->prepare('DELETE se FROM time_submission_entries se JOIN work_time_entries t ON t.id=se.time_entry_id WHERE t.user_id=?')->execute([$employeeId]);
                $pdo->prepare('DELETE s FROM time_submissions s LEFT JOIN time_submission_entries se ON se.submission_id=s.id WHERE s.worker_profile_id=? AND se.submission_id IS NULL')->execute([$workerProfileId]);
                $pdo->prepare('DELETE FROM worker_period_submissions WHERE worker_profile_id=?')->execute([$workerProfileId]);
                $billingIds = $pdo->prepare('SELECT c.billing_time_entry_id FROM work_billing_consumptions c JOIN work_approval_snapshots s ON s.id=c.approval_snapshot_id WHERE s.employee_user_id=?');
                $billingIds->execute([$employeeId]);
                $billingIds = array_map('intval', $billingIds->fetchAll(PDO::FETCH_COLUMN));
                $pdo->prepare('DELETE c FROM work_billing_consumptions c JOIN work_approval_snapshots s ON s.id=c.approval_snapshot_id WHERE s.employee_user_id=?')->execute([$employeeId]);
                if ($billingIds) {
                    $pdo->exec('DELETE FROM time_entries WHERE id IN (' . implode(',', $billingIds) . ')');
                }
                $pdo->prepare('DELETE FROM work_pay_accruals WHERE employee_user_id=?')->execute([$employeeId]);
                $pdo->prepare('DELETE a FROM work_time_billing_allocations a JOIN work_time_entries t ON t.id=a.time_entry_id WHERE t.user_id=?')->execute([$employeeId]);
                $pdo->prepare('DELETE e FROM worker_earnings e JOIN work_time_entries t ON t.id=e.work_time_entry_id WHERE t.user_id=?')->execute([$employeeId]);
                $pdo->prepare('DELETE r FROM work_time_revisions r JOIN work_time_entries t ON t.id=r.time_entry_id WHERE t.user_id=?')->execute([$employeeId]);
                $pdo->prepare('DELETE FROM work_approval_snapshots WHERE employee_user_id=?')->execute([$employeeId]);
                $pdo->prepare('DELETE b FROM work_time_breaks b JOIN work_time_entries t ON t.id=b.time_entry_id WHERE t.user_id=?')->execute([$employeeId]);
                $pdo->prepare('DELETE FROM work_time_entries WHERE user_id=?')->execute([$employeeId]);
                $pdo->prepare("DELETE FROM system_audit WHERE user_id IN (?,?) AND action LIKE 'time_entry.%' OR user_id IN (?,?) AND action LIKE 'timer.%' OR user_id IN (?,?) AND action LIKE 'break.%'")
                    ->execute([$employeeId,$adminId,$employeeId,$adminId,$employeeId,$adminId]);
                $pdo->prepare('DELETE FROM project_assignments WHERE user_id=?')->execute([$employeeId]);
                $pdo->prepare('DELETE FROM team_members WHERE user_id=?')->execute([$employeeId]);
                $pdo->prepare('DELETE FROM employee_profiles WHERE user_id=?')->execute([$employeeId]);
                $pdo->prepare('DELETE FROM worker_profiles WHERE id=?')->execute([$workerProfileId]);
                $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$employeeId]);
            }
            if ($jobId > 0) {
                $pdo->prepare('DELETE FROM jobs WHERE id=?')->execute([$jobId]);
            }
            if ($projectId > 0) {
                $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$projectId]);
            }
        }
    }

    private function value(PDO $pdo, string $sql, array $params): string
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (string) $stmt->fetchColumn();
    }

    private function row(PDO $pdo, string $sql, array $params): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
