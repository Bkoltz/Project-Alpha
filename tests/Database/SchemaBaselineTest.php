<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';
require_once dirname(__DIR__, 2) . '/src/migrations/admin_sync.php';

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

    public function testComposeAdministratorPasswordIsReconciledOnlyWhenDifferent(): void
    {
        $environment = [
            'ADMIN_EMAIL' => $this->email,
            'ADMIN_USERNAME' => 'phase1-admin',
            'ADMIN_PASSWORD' => 'First-Password-123!',
        ];

        $this->assertSame('created', sync_compose_admin($this->pdo, $environment));
        $firstHash = $this->passwordHash();
        $this->assertTrue(password_verify($environment['ADMIN_PASSWORD'], $firstHash));

        $this->assertSame('unchanged', sync_compose_admin($this->pdo, $environment));
        $this->assertSame($firstHash, $this->passwordHash(), 'Unchanged password must not rewrite its salted hash.');

        $environment['ADMIN_PASSWORD'] = 'Second-Password-456!';
        $this->assertSame('updated', sync_compose_admin($this->pdo, $environment));
        $this->assertTrue(password_verify($environment['ADMIN_PASSWORD'], $this->passwordHash()));
        $this->assertFalse(password_verify('First-Password-123!', $this->passwordHash()));
    }

    private function passwordHash(): string
    {
        $statement = $this->pdo->prepare('SELECT password_hash FROM users WHERE email = ?');
        $statement->execute([$this->email]);
        return (string) $statement->fetchColumn();
    }
}
