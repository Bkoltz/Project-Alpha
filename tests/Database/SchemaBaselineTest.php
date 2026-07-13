<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';

use PHPUnit\Framework\TestCase;

final class SchemaBaselineTest extends TestCase
{
    private PDO $pdo;
    private string $email;

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
        } catch (Throwable $error) {
            $this->markTestSkipped('MySQL baseline backend unavailable: ' . $error->getMessage());
        }
        $this->email = 'phase1-' . bin2hex(random_bytes(6)) . '@example.invalid';
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo) || !isset($this->email)) {
            return;
        }
        $find = $this->pdo->prepare('SELECT id FROM users WHERE email = ?');
        $find->execute([$this->email]);
        $id = $find->fetchColumn();
        if ($id !== false) {
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([(int) $id]);
        }
    }

    public function testBaselineMarkerAndSchemaHealth(): void
    {
        $ledger = migration_ledger($this->pdo);
        $this->assertSame('baseline.sql', $ledger[0]['filename']);
        migration_schema_health($this->pdo);
        $this->addToAssertionCount(1);
    }

    public function testCleanInstallationDoesNotRequireComposeAdministratorCredentials(): void
    {
        $root = dirname(__DIR__, 2);
        $migrator = (string)file_get_contents($root . '/docker/migrate.sh');
        $compose = (string)file_get_contents($root . '/docker-compose.yml');
        self::assertStringNotContainsString('admin_sync.php', $migrator);
        self::assertStringNotContainsString('ADMIN_PASSWORD', $compose);
        self::assertStringNotContainsString('ADMIN_EMAIL', $compose);
        self::assertStringContainsString('Create the first administrator in the web setup', $migrator);
    }
}
