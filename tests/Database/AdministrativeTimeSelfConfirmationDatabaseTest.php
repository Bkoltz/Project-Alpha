<?php

declare(strict_types=1);

use App\Modules\Timekeeping\ApprovalService;
use App\Modules\Timekeeping\AuditRecorder;
use App\Modules\Timekeeping\BillingTimeConsumer;
use App\Modules\Timekeeping\TimekeepingService;
use PHPUnit\Framework\TestCase;

final class AdministrativeTimeSelfConfirmationDatabaseTest extends TestCase
{
    public function testAdministrativeRolesKeepWorkerCompensationWhenSelfConfirming(): void
    {
        if (getenv('WORKFORCE_DB_TESTS') !== '1') {
            self::markTestSkipped('Set WORKFORCE_DB_TESTS=1 only for an isolated verification database.');
        }

        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST') ?: 'db',
                getenv('DB_PORT') ?: '3306',
                getenv('MYSQL_DATABASE') ?: 'project_alpha'
            ),
            getenv('MYSQL_USER') ?: 'root',
            getenv('MYSQL_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );

        $suffix = bin2hex(random_bytes(5));
        $password = password_hash('AdministrativeSelfConfirmation!123', PASSWORD_DEFAULT);
        $insertUser = $pdo->prepare('INSERT INTO users (email,username,password_hash,role) VALUES (?,?,?,?)');
        $insertProfile = $pdo->prepare(
            "INSERT INTO worker_profiles
             (user_id,relationship_type,time_review_policy,compensation_policy,status,display_name,currency)
             VALUES (?,?,'manager_review','rules','active',?,'USD')"
        );
        $insertEmployee = $pdo->prepare(
            "INSERT INTO employee_profiles (user_id,first_name,last_name,hourly_rate,currency)
             VALUES (?,'Administrative','Worker','30.0000','USD')"
        );

        $audit = new AuditRecorder($pdo);
        $time = new TimekeepingService($pdo, $audit);
        $approval = new ApprovalService($pdo, $audit, new BillingTimeConsumer($pdo));
        $workDate = new DateTimeImmutable('2037-02-15 09:00:00', new DateTimeZone('UTC'));

        foreach (['admin' => 'employee', 'owner' => 'contractor'] as $role => $relationship) {
            $insertUser->execute([
                "{$role}-self-confirm-{$suffix}@example.test",
                "{$role}-self-confirm-{$suffix}",
                $password,
                $role,
            ]);
            $userId = (int)$pdo->lastInsertId();
            $insertProfile->execute([$userId, $relationship, ucfirst($role) . ' Compensation Worker']);
            $insertEmployee->execute([$userId]);

            $entryId = $time->saveManual($userId, [
                'capture_mode' => 'exact',
                'start_time' => $workDate->format('Y-m-d\\TH:i'),
                'end_time' => $workDate->modify('+1 hour')->format('Y-m-d\\TH:i'),
                'description' => 'Administrative self-confirmation compensation test',
                'billing_treatment' => 'nonbillable',
                'is_payable' => '1',
                'entered_by_user_id' => $userId,
            ], true);

            $approval->selfConfirmAdministrator($userId, $entryId);

            $entry = $this->row($pdo, 'SELECT * FROM work_time_entries WHERE id=?', [$entryId]);
            self::assertSame('approved', $entry['status']);
            self::assertSame('confirmed', $entry['workflow_status']);
            self::assertSame('eligible', $entry['compensation_state']);
            self::assertSame('0', (string)$entry['owner_self_confirmed']);
            self::assertSame('1', (string)$this->value(
                $pdo,
                'SELECT COUNT(*) FROM worker_earnings WHERE work_time_entry_id=? AND status=\'eligible\' AND amount=\'30.00\'',
                [$entryId]
            ));
            self::assertSame('1', (string)$this->value(
                $pdo,
                'SELECT COUNT(*) FROM work_approval_snapshots WHERE time_entry_id=? AND approved_by=? AND is_payable=1',
                [$entryId, $userId]
            ));
        }
    }

    private function value(PDO $pdo, string $sql, array $parameters): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function row(PDO $pdo, string $sql, array $parameters): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
