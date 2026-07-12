<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/admin_recovery.php';
require_once dirname(__DIR__, 2) . '/src/utils/admin_account_policy.php';

use PHPUnit\Framework\TestCase;

final class AdminRecoveryTest extends TestCase
{
    private ?PDO $pdo = null;
    /** @var int[] */
    private array $userIds = [];

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
            migration_schema_health($this->pdo);
        } catch (Throwable $error) {
            $this->markTestSkipped('MySQL recovery backend unavailable: ' . $error->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!$this->pdo) {
            return;
        }
        foreach (array_reverse($this->userIds) as $id) {
            $this->pdo->prepare('DELETE FROM system_audit WHERE entity_type = ? AND entity_id = ?')->execute(['user', $id]);
            $this->pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM trusted_devices WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM user_2fa WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
    }

    public function testRecoveryByUsernameRevokesSessionsAndResetTokensWithoutTouchingTotp(): void
    {
        $id = $this->insertUser('admin');
        $oldVersion = $this->authVersion($id);
        $this->seedResetAndTotp($id);

        $password = recover_admin_account($this->pdo, $this->username($id), false);
        $user = $this->user($id);

        self::assertTrue(password_verify($password, (string)$user['password_hash']));
        self::assertSame(1, (int)$user['force_password_reset']);
        self::assertGreaterThan($oldVersion, (int)$user['auth_version'], 'Existing sessions must be invalidated.');
        self::assertSame(0, (int)$user['totp_reenroll_required']);
        self::assertSame(1, $this->rowCountForUser('user_2fa', $id), 'TOTP must remain intact without --reset-totp.');
        self::assertSame(0, $this->activeResetCount($id));
        self::assertSame(0, $this->rowCountForUser('trusted_devices', $id));
        self::assertSame(1, $this->auditCount($id, 'admin.recovery_password_issued'));
        self::assertSame(0, $this->auditCount($id, 'admin.recovery_totp_reset'));
    }

    public function testRecoveryByEmailWithExplicitTotpResetRequiresReenrollment(): void
    {
        $id = $this->insertUser('admin');
        $this->seedResetAndTotp($id);

        $password = recover_admin_account($this->pdo, $this->email($id), true);
        $user = $this->user($id);

        self::assertTrue(password_verify($password, (string)$user['password_hash']));
        self::assertSame(1, (int)$user['force_password_reset']);
        self::assertSame(1, (int)$user['totp_reenroll_required']);
        self::assertSame(0, $this->rowCountForUser('user_2fa', $id));
        self::assertSame(1, $this->auditCount($id, 'admin.recovery_password_issued'));
        self::assertSame(1, $this->auditCount($id, 'admin.recovery_totp_reset'));
    }

    public function testRecoveryInvalidatesAResetSessionConvertedBeforeRecovery(): void
    {
        $id = $this->insertUser('admin');
        $resetSessionVersion = $this->authVersion($id);
        $temporaryPassword = recover_admin_account($this->pdo, $this->email($id), false);

        $staleReset = $this->pdo->prepare(
            'UPDATE users SET password_hash=?, force_password_reset=0, auth_version=auth_version+1
             WHERE id=? AND auth_version=?'
        );
        $staleReset->execute([password_hash('Attacker-Password-789!', PASSWORD_DEFAULT), $id, $resetSessionVersion]);

        self::assertSame(0, $staleReset->rowCount());
        $user = $this->user($id);
        self::assertTrue(password_verify($temporaryPassword, (string)$user['password_hash']));
        self::assertSame(1, (int)$user['force_password_reset']);
    }

    public function testRecoveryRejectsNonAdministratorsInactiveAndUnknownAccounts(): void
    {
        $employee = $this->insertUser('member');
        $inactive = $this->insertUser('admin', true);

        foreach ([
            [$this->email($employee), 'restricted to administrator'],
            [$this->email($inactive), 'inactive'],
            ['missing-' . bin2hex(random_bytes(4)) . '@example.invalid', 'not found'],
        ] as [$identifier, $message]) {
            try {
                recover_admin_account($this->pdo, $identifier);
                self::fail('Recovery should have been rejected.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString($message, strtolower($error->getMessage()));
            }
        }
    }

    public function testFinalActiveAdministratorCannotBeRemoved(): void
    {
        $admin = $this->insertUser('admin');
        $this->pdo->beginTransaction();
        $this->pdo->prepare("UPDATE users SET is_disabled = 1 WHERE role = 'admin' AND id <> ?")->execute([$admin]);
        try {
            assert_not_removing_final_active_admin($this->pdo, $admin, false);
            self::fail('The final active administrator should be protected.');
        } catch (DomainException $error) {
            self::assertStringContainsString('final active administrator', strtolower($error->getMessage()));
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }

        $this->insertUser('admin');
        $this->pdo->beginTransaction();
        assert_not_removing_final_active_admin($this->pdo, $admin, false);
        $this->pdo->rollBack();
        $this->addToAssertionCount(1);
    }

    public function testForcedRecoveryGatesArePresent(): void
    {
        $root = dirname(__DIR__, 2);
        $front = (string)file_get_contents($root . '/public/index.php');
        $passwordChange = (string)file_get_contents($root . '/src/controllers/auth/account_update.php');
        $totpSetup = (string)file_get_contents($root . '/src/controllers/auth/two_factor_setup.php');
        self::assertStringContainsString('force_password_reset', $front);
        self::assertStringContainsString('totp_reenroll_required', $front);
        self::assertStringContainsString('auth_version=auth_version+1', $passwordChange);
        self::assertStringContainsString('totp_reenroll_required = 0', $totpSetup);
        $totpView = (string)file_get_contents($root . '/src/views/pages/auth/two_factor_setup.php');
        self::assertStringNotContainsString('chart.googleapis.com', $totpView);
        self::assertStringContainsString('does not send it to an external QR service', $totpView);
        $accountUpdate = (string)file_get_contents($root . '/src/controllers/auth/account_update.php');
        $authHandler = (string)file_get_contents($root . '/src/controllers/auth/auth_handler.php');
        self::assertStringContainsString('FOR UPDATE', $accountUpdate);
        self::assertStringContainsString("GET_LOCK('project_alpha_first_admin_setup'", $authHandler);
        self::assertStringContainsString('FOR UPDATE', $totpSetup);
    }

    private function insertUser(string $role, bool $disabled = false): int
    {
        $suffix = bin2hex(random_bytes(6));
        $statement = $this->pdo->prepare(
            'INSERT INTO users (email, username, password_hash, role, is_disabled)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            "recovery-{$suffix}@example.invalid",
            "recovery-{$suffix}",
            password_hash('Original-Password-123!', PASSWORD_DEFAULT),
            $role,
            $disabled ? 1 : 0,
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $this->userIds[] = $id;
        return $id;
    }

    private function seedResetAndTotp(int $id): void
    {
        $this->pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, '123456', DATE_ADD(NOW(), INTERVAL 5 MINUTE))")->execute([$id]);
        $this->pdo->prepare("INSERT INTO trusted_devices (user_id, device_token, device_name, ip_address, user_agent_hash, expires_at) VALUES (?, 'device', 'test', '127.0.0.1', REPEAT('a',64), DATE_ADD(NOW(), INTERVAL 1 DAY))")->execute([$id]);
        $this->pdo->prepare("INSERT INTO user_2fa (user_id, secret, enabled, backup_codes) VALUES (?, 'TESTSECRET', 1, '[]')")->execute([$id]);
    }

    private function user(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $statement->execute([$id]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    private function email(int $id): string { return (string)$this->user($id)['email']; }
    private function username(int $id): string { return (string)$this->user($id)['username']; }
    private function authVersion(int $id): int { return (int)$this->user($id)['auth_version']; }

    private function rowCountForUser(string $table, int $id): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = ?");
        $statement->execute([$id]);
        return (int)$statement->fetchColumn();
    }

    private function activeResetCount(int $id): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND used = 0');
        $statement->execute([$id]);
        return (int)$statement->fetchColumn();
    }

    private function auditCount(int $id, string $action): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM system_audit WHERE entity_type = ? AND entity_id = ? AND action = ?');
        $statement->execute(['user', $id, $action]);
        return (int)$statement->fetchColumn();
    }
}
