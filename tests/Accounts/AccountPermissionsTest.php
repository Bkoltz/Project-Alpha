<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/utils/acl.php';

use PHPUnit\Framework\TestCase;

final class AccountPermissionsTest extends TestCase
{
    private PDO $pdo;
    private array $userIds = [];

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
        } catch (Throwable $error) {
            $this->markTestSkipped('MySQL backend unavailable: ' . $error->getMessage());
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->userIds) as $userId) {
            $this->pdo->prepare('DELETE FROM user_permissions_overrides WHERE user_id = ?')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
    }

    public function testDashboardModulePermissionsDoNotRequireCustomerOrganizationMembership(): void
    {
        $userId = $this->insertUser();

        self::assertTrue(user_can($this->pdo, $userId, 'clients.view', 0));
        self::assertTrue(user_can($this->pdo, $userId, 'projects.view', 0));
        self::assertTrue(user_can($this->pdo, $userId, 'quotes.view', 0));
        self::assertFalse(user_can($this->pdo, $userId, 'financial.view', 0));
        self::assertFalse(user_can($this->pdo, $userId, 'settings.manage', 0));
    }

    public function testExplicitGlobalDenyStillOverridesFallbackRole(): void
    {
        $userId = $this->insertUser();
        $stmt = $this->pdo->prepare('INSERT INTO user_permissions_overrides (user_id, organization_id, permission, allowed) VALUES (?, NULL, ?, 0)');
        $stmt->execute([$userId, 'clients.view']);

        self::assertFalse(user_can($this->pdo, $userId, 'clients.view', 0));
        self::assertTrue(user_can($this->pdo, $userId, 'projects.view', 0));
    }

    private function insertUser(): int
    {
        $email = 'acl-orphan-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $stmt = $this->pdo->prepare('INSERT INTO users (email, username, password_hash, role) VALUES (?, ?, ?, "member")');
        $stmt->execute([$email, 'ACL Orphan', password_hash('Password123!', PASSWORD_DEFAULT)]);
        $userId = (int)$this->pdo->lastInsertId();
        $this->userIds[] = $userId;
        return $userId;
    }
}
