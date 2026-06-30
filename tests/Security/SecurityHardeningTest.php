<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/acl.php';
require_once dirname(__DIR__, 2) . '/src/utils/password_reset_tokens.php';

use PHPUnit\Framework\TestCase;

final class SecurityHardeningTest extends TestCase
{
    private string $root;
    private ?PDO $pdo = null;
    /** @var int[] */
    private array $clientIds = [];
    /** @var int[] */
    private array $userIds = [];
    /** @var int[] */
    private array $orgIds = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (!$this->pdo instanceof PDO) {
            return;
        }

        foreach ($this->clientIds as $id) {
            $this->pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        foreach ($this->userIds as $id) {
            $this->pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM user_organizations WHERE user_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
        foreach ($this->orgIds as $id) {
            $this->pdo->prepare('DELETE FROM organizations WHERE id = ?')->execute([$id]);
        }
    }

    public function testClientRecordAccessIsScopedToActiveOrganization(): void
    {
        $pdo = $this->mysql();
        $suffix = bin2hex(random_bytes(5));
        $orgA = $this->insertOrganization("Security Org A {$suffix}");
        $orgB = $this->insertOrganization("Security Org B {$suffix}");
        $userId = $this->insertUser("security-client-{$suffix}@example.invalid");

        $pdo->prepare('INSERT INTO user_organizations (user_id, organization_id, role, is_default) VALUES (?, ?, ?, 1)')
            ->execute([$userId, $orgA, 'member']);

        $sameOrgClient = $this->insertClient('Same Org Client', $orgA, $userId);
        $otherOrgClient = $this->insertClient('Other Org Client', $orgB, $userId);

        $_SESSION['user'] = [
            'id' => $userId,
            'email' => "security-client-{$suffix}@example.invalid",
            'role' => 'user',
            'active_org_id' => $orgA,
        ];

        self::assertTrue(can_access_record($pdo, 'clients', $sameOrgClient, $userId));
        self::assertFalse(can_access_record($pdo, 'clients', $otherOrgClient, $userId));
    }

    public function testPasswordResetFailuresIncrementAndLockSharedCounter(): void
    {
        $pdo = $this->mysql();
        $suffix = bin2hex(random_bytes(5));
        $email = "security-reset-{$suffix}@example.invalid";
        $userId = $this->insertUser($email);

        $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at, attempts, used) VALUES (?, '123456', DATE_ADD(NOW(), INTERVAL 1 HOUR), 0, 0)")
            ->execute([$userId]);

        for ($i = 0; $i < 3; $i++) {
            try {
                password_reset_verify_and_consume($pdo, $email, '000000');
                self::fail('Wrong reset token should not verify.');
            } catch (RuntimeException $expected) {
                self::assertSame('badtoken', $expected->getMessage());
            }
        }

        $row = $pdo->prepare('SELECT attempts, used FROM password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $row->execute([$userId]);
        $state = $row->fetch(PDO::FETCH_ASSOC);

        self::assertSame(3, (int)$state['attempts']);
        self::assertSame(1, (int)$state['used'], 'Third failed attempt should lock/consume the reset token.');
    }

    public function testConfirmedFindingFixesRemainInPlace(): void
    {
        $acl = $this->read('src/utils/acl.php');
        self::assertStringNotContainsString("if (\$table === 'clients') return true", $acl);

        $csv = $this->read('src/controllers/financial/csv_import.php');
        self::assertStringNotContainsString('$orgId = 1;', $csv);
        self::assertStringContainsString('get_active_org_id()', $csv);
        self::assertStringContainsString('financial.manage', $csv);
        self::assertStringContainsString('expense_categories WHERE id = ? AND organization_id = ?', $csv);

        $payments = $this->read('src/controllers/payments_create.php');
        self::assertStringContainsString("can_access_record(\$pdo, 'invoices'", $payments);
        self::assertStringContainsString('WHERE id=? AND organization_id=? FOR UPDATE', $payments);

        $revoke = $this->read('src/controllers/public_link_revoke.php');
        self::assertStringContainsString('document_access_require_manage', $revoke);

        $sign = $this->read('src/controllers/public_view/public_contract_sign.php');
        self::assertStringContainsString("\$status !== 'pending'", $sign);
        self::assertStringContainsString("AND status = 'pending'", $sign);
        self::assertStringContainsString('UPDATE public_links SET revoked = 1 WHERE token = ?', $sign);

        $resetVerify = $this->read('src/controllers/auth/reset_verify.php');
        $resetUpdate = $this->read('src/controllers/auth/reset_update.php');
        self::assertStringContainsString('password_reset_verify_and_consume', $resetVerify);
        self::assertStringContainsString('password_reset_verify_and_consume', $resetUpdate);

        $forms = $this->read('src/controllers/forms_handler.php');
        self::assertStringContainsString('INNER JOIN form_categories c ON c.id = d.category_id', $forms);
        self::assertStringContainsString('$stmt->rowCount() !== 1', $forms);
    }

    public function testStateChangingSecurityRoutesHaveExpectedGuardrails(): void
    {
        $front = $this->read('public/index.php');
        self::assertStringContainsString("'payments/payments-create'", $front);
        self::assertStringContainsString("'public-link-revoke'", $front);
        self::assertStringContainsString("'forms-handler'", $front);
        self::assertStringContainsString("'financial/csv-import'", $front);
        self::assertStringContainsString('csrf_verify_post_or_redirect($page)', $front);
        self::assertStringNotContainsString("'public-link-revoke'", $this->csrfSkipList($front));
        self::assertStringNotContainsString("'payments/payments-create'", $this->csrfSkipList($front));
        self::assertStringContainsString("'public-contract-sign'", $this->csrfSkipList($front));
        self::assertStringContainsString("'stripe-webhook'", $this->csrfSkipList($front));

        $aclMiddleware = $this->read('src/utils/acl_middleware.php');
        foreach (['payments/payments-create', 'public-link-revoke', 'forms-handler', 'financial/csv-import'] as $route) {
            self::assertStringContainsString("'{$route}'", $aclMiddleware, "{$route} must have an ACL decision.");
        }

        $publicContractSign = $this->read('src/controllers/public_view/public_contract_sign.php');
        self::assertStringContainsString("csrf_sf_is_valid('public_contract_sign'", $publicContractSign);
    }

    public function testOperatorHardeningPoliciesAreEnabled(): void
    {
        $front = $this->read('public/index.php');
        self::assertStringContainsString('AUTH_DISABLED ignored because APP_ENV is production', $front);
        self::assertStringContainsString('two_factor_enforce_required', $front);

        $setup = $this->read('src/controllers/auth/two_factor_setup.php');
        self::assertStringContainsString('two_factor_required_for_user', $setup);
        self::assertStringContainsString('Two-factor authentication is required for your account', $setup);

        $docker = $this->read('docker/start.sh');
        self::assertStringContainsString('Production readiness checks:', $docker);
        self::assertStringContainsString('BACKUP_ENCRYPTION_KEY is not set', $docker);
        self::assertStringContainsString('Stripe webhook secret is not configured in app settings', $docker);
        self::assertStringContainsString('AUTH_DISABLED/APP_AUTH_DISABLED is set but ignored in production', $docker);
    }

    public function testFinancialModuleDoesNotHardCodeOrganizationOne(): void
    {
        foreach ([
            $this->root . '/src/controllers/financial',
            $this->root . '/src/views/pages/financial',
        ] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $contents = (string)file_get_contents($file->getPathname());
                self::assertDoesNotMatchRegularExpression('/\$orgId\s*=\s*1\s*;/', $contents, $file->getPathname());
                self::assertDoesNotMatchRegularExpression('/organization_id\s*=\s*1\b/', $contents, $file->getPathname());
            }
        }
    }

    public function testCiAndReleaseGatesAreStrict(): void
    {
        $ci = $this->read('.github/workflows/ci.yml');
        self::assertStringContainsString('composer validate --strict', $ci);
        self::assertStringContainsString('composer audit --locked', $ci);
        self::assertStringContainsString('docker compose -f docker-compose.yml config', $ci);

        $docker = $this->read('.github/workflows/docker-publish.yml');
        self::assertStringContainsString('exit-code: \'1\'', $docker);
        self::assertStringContainsString("severity: 'CRITICAL,HIGH'", $docker);
        self::assertStringContainsString(':sha-${{ github.sha }}', $docker);
        self::assertStringContainsString(':cron-sha-${{ github.sha }}', $docker);
    }

    private function mysql(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        try {
            $this->pdo = migration_connection();
        } catch (Throwable $error) {
            $this->markTestSkipped('MySQL security backend unavailable: ' . $error->getMessage());
        }

        return $this->pdo;
    }

    private function insertOrganization(string $name): int
    {
        $pdo = $this->mysql();
        $pdo->prepare('INSERT INTO organizations (name) VALUES (?)')->execute([$name]);
        $id = (int)$pdo->lastInsertId();
        $this->orgIds[] = $id;
        return $id;
    }

    private function insertUser(string $email): int
    {
        $pdo = $this->mysql();
        $pdo->prepare('INSERT INTO users (email, username, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute([$email, 'security-test', password_hash('Security-Test-123!', PASSWORD_DEFAULT), 'user']);
        $id = (int)$pdo->lastInsertId();
        $this->userIds[] = $id;
        return $id;
    }

    private function insertClient(string $name, int $orgId, int $createdBy): int
    {
        $pdo = $this->mysql();
        $pdo->prepare('INSERT INTO clients (name, organization_id, created_by) VALUES (?, ?, ?)')
            ->execute([$name, $orgId, $createdBy]);
        $id = (int)$pdo->lastInsertId();
        $this->clientIds[] = $id;
        return $id;
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->root . '/' . $relativePath);
        self::assertIsString($contents, $relativePath);
        return $contents;
    }

    private function csrfSkipList(string $frontController): string
    {
        self::assertMatchesRegularExpression('/\$skipCsrfFor\s*=\s*\[(.*?)\];/s', $frontController);
        preg_match('/\$skipCsrfFor\s*=\s*\[(.*?)\];/s', $frontController, $matches);
        return $matches[1] ?? '';
    }
}
