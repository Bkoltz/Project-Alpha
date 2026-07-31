<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/migrations/migration_lib.php';

use App\Security\DatabaseSessionHandler;
use App\Security\SessionPolicy;
use App\Security\SessionRevocation;
use PHPUnit\Framework\TestCase;

final class DatabaseSessionHandlerTest extends TestCase
{
    private PDO $pdo;
    private int $userId;
    private string $prefix;

    protected function setUp(): void
    {
        try {
            $this->pdo = migration_connection();
            $column = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema=DATABASE() AND table_name='app_sessions' AND column_name='revoked_at'"
            )->fetchColumn();
            if ((int)$column !== 1) {
                $this->markTestSkipped('Apply migration 0057 in the isolated test database first.');
            }
        } catch (Throwable $error) {
            $this->markTestSkipped('MySQL session backend unavailable: ' . $error->getMessage());
        }
        $this->prefix = 'session-test-' . bin2hex(random_bytes(6));
        $stmt = $this->pdo->prepare("INSERT INTO users (email,username,password_hash,role) VALUES (?,?,?,'member')");
        $stmt->execute([$this->prefix . '@example.invalid', $this->prefix, password_hash('Session-Test-Password-123!', PASSWORD_DEFAULT)]);
        $this->userId = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (!isset($this->pdo) || !isset($this->userId)) {
            return;
        }
        foreach (['normal', 'old', 'new', 'race', 'logout', 'legacy-old', 'legacy-new', 'current', 'other'] as $suffix) {
            $this->pdo->prepare('DELETE FROM app_sessions WHERE session_hash=?')
                ->execute([hash('sha256', $this->prefix . '-' . $suffix)]);
        }
        $this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$this->userId]);
    }

    public function testNormalSessionAndIdleBoundaries(): void
    {
        $id = $this->prefix . '-normal';
        $this->authenticatedSession(time());
        $handler = new DatabaseSessionHandler($this->pdo);
        self::assertTrue($handler->write($id, 'normal-payload'));
        self::assertTrue($handler->close());

        $handler = new DatabaseSessionHandler($this->pdo);
        self::assertSame('normal-payload', $handler->read($id));
        $handler->close();

        $hash = hash('sha256', $id);
        $this->pdo->prepare('UPDATE app_sessions SET last_activity_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 899 SECOND) WHERE session_hash=?')->execute([$hash]);
        $handler = new DatabaseSessionHandler($this->pdo);
        self::assertSame('normal-payload', $handler->read($id));
        $handler->close();

        $this->pdo->prepare('UPDATE app_sessions SET last_activity_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 901 SECOND) WHERE session_hash=?')->execute([$hash]);
        $handler = new DatabaseSessionHandler($this->pdo);
        self::assertSame('', $handler->read($id));
        $handler->close();
        self::assertNotNull($this->value('SELECT revoked_at FROM app_sessions WHERE session_hash=?', [$hash]));

        self::assertSame('1', (string)$this->value(
            "SELECT TIMESTAMP('2026-01-01 00:00:00') >= DATE_SUB(TIMESTAMP('2026-01-01 00:15:00'),INTERVAL 900 SECOND)"
        ));
    }

    public function testAbsoluteBoundaryAndRotationPreserveOriginalDeadline(): void
    {
        $oldId = $this->prefix . '-old';
        $newId = $this->prefix . '-new';
        $authenticatedAt = time() - 3600;
        $this->authenticatedSession($authenticatedAt);
        $handler = new DatabaseSessionHandler($this->pdo);
        $handler->write($oldId, 'authenticated');
        $handler->close();
        $deadline = (string)$this->value('SELECT absolute_expires_at FROM app_sessions WHERE session_hash=?', [hash('sha256', $oldId)]);

        $handler = new DatabaseSessionHandler($this->pdo);
        self::assertSame('authenticated', $handler->read($oldId));
        self::assertTrue($handler->destroy($oldId));
        self::assertTrue($handler->write($newId, 'authenticated'));
        $handler->close();

        self::assertSame($deadline, (string)$this->value('SELECT absolute_expires_at FROM app_sessions WHERE session_hash=?', [hash('sha256', $newId)]));
        self::assertNotNull($this->value('SELECT revoked_at FROM app_sessions WHERE session_hash=?', [hash('sha256', $oldId)]));

        $this->pdo->prepare('UPDATE app_sessions SET absolute_expires_at=UTC_TIMESTAMP(6) WHERE session_hash=?')->execute([hash('sha256', $newId)]);
        $expired = new DatabaseSessionHandler($this->pdo);
        self::assertSame('', $expired->read($newId));
        $expired->close();
    }

    public function testLockSerializesRequestsAndExternalRevocationCannotBeResurrected(): void
    {
        $id = $this->prefix . '-race';
        $this->authenticatedSession(time());
        $seed = new DatabaseSessionHandler($this->pdo);
        $seed->write($id, 'first');
        $seed->close();

        $first = new DatabaseSessionHandler($this->pdo);
        self::assertSame('first', $first->read($id));
        $secondPdo = migration_connection();
        $contender = new DatabaseSessionHandler($secondPdo, 900, 604800, 0);
        try {
            $contender->read($id);
            self::fail('A second request acquired the same session lock concurrently.');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('session lock', $expected->getMessage());
        }

        $secondPdo->prepare('UPDATE app_sessions SET user_id=NULL,payload=?,revoked_at=UTC_TIMESTAMP(6) WHERE session_hash=?')
            ->execute(['', hash('sha256', $id)]);
        self::assertTrue($first->write($id, 'stale-delayed-payload'));
        $first->close();

        self::assertSame('', (string)$this->value('SELECT payload FROM app_sessions WHERE session_hash=?', [hash('sha256', $id)]));
        self::assertNotNull($this->value('SELECT revoked_at FROM app_sessions WHERE session_hash=?', [hash('sha256', $id)]));
    }

    public function testDestroyAndPassiveTimestampCannotReviveOrRefreshSession(): void
    {
        $id = $this->prefix . '-logout';
        $this->authenticatedSession(time());
        $handler = new DatabaseSessionHandler($this->pdo);
        $handler->write($id, 'authenticated');
        $handler->close();
        $hash = hash('sha256', $id);

        $this->pdo->prepare('UPDATE app_sessions SET last_activity_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 120 SECOND) WHERE session_hash=?')->execute([$hash]);
        $before = (string)$this->value('SELECT last_activity_at FROM app_sessions WHERE session_hash=?', [$hash]);
        $passive = new DatabaseSessionHandler($this->pdo);
        self::assertTrue($passive->updateTimestamp($id, 'authenticated'));
        $passive->close();
        self::assertSame($before, (string)$this->value('SELECT last_activity_at FROM app_sessions WHERE session_hash=?', [$hash]));

        $logout = new DatabaseSessionHandler($this->pdo);
        self::assertSame('authenticated', $logout->read($id));
        self::assertTrue($logout->destroy($id));
        self::assertTrue($logout->write($id, 'delayed-after-logout'));
        $logout->close();
        self::assertSame('', (string)$this->value('SELECT payload FROM app_sessions WHERE session_hash=?', [$hash]));
        self::assertNotNull($this->value('SELECT revoked_at FROM app_sessions WHERE session_hash=?', [$hash]));
    }

    public function testLegacyAuthenticatedRotationCannotGainANewAbsoluteWindow(): void
    {
        $oldId = $this->prefix . '-legacy-old';
        $newId = $this->prefix . '-legacy-new';
        $this->authenticatedSession(time() - 3600);
        $seed = new DatabaseSessionHandler($this->pdo);
        self::assertTrue($seed->write($oldId, 'legacy-authenticated'));
        self::assertTrue($seed->close());

        $oldHash = hash('sha256', $oldId);
        $this->pdo->prepare('UPDATE app_sessions SET absolute_expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 HOUR) WHERE session_hash=?')
            ->execute([$oldHash]);
        $deadline = (string)$this->value('SELECT absolute_expires_at FROM app_sessions WHERE session_hash=?', [$oldHash]);
        unset($_SESSION['authn']);

        $handler = new DatabaseSessionHandler($this->pdo);
        self::assertSame('legacy-authenticated', $handler->read($oldId));
        self::assertTrue($handler->destroy($oldId));
        self::assertTrue($handler->write($newId, 'legacy-authenticated'));
        self::assertTrue($handler->close());

        self::assertSame($deadline, (string)$this->value(
            'SELECT absolute_expires_at FROM app_sessions WHERE session_hash=?',
            [hash('sha256', $newId)]
        ));
        self::assertNotNull($this->value('SELECT revoked_at FROM app_sessions WHERE session_hash=?', [$oldHash]));
    }
    public function testBulkRevocationCanPreserveCurrentSessionDeliberately(): void
    {
        $currentId = $this->prefix . '-current';
        $otherId = $this->prefix . '-other';
        $this->authenticatedSession(time());
        foreach ([$currentId, $otherId] as $id) {
            $handler = new DatabaseSessionHandler($this->pdo);
            self::assertTrue($handler->write($id, 'authenticated'));
            self::assertTrue($handler->close());
        }

        self::assertSame(1, SessionRevocation::revokeUserSessions($this->pdo, $this->userId, $currentId));
        self::assertNull($this->value('SELECT revoked_at FROM app_sessions WHERE session_hash=?', [hash('sha256', $currentId)]));
        self::assertNotNull($this->value('SELECT revoked_at FROM app_sessions WHERE session_hash=?', [hash('sha256', $otherId)]));

        self::assertSame(1, SessionRevocation::revokeUserSessions($this->pdo, $this->userId));
        self::assertNotNull($this->value('SELECT revoked_at FROM app_sessions WHERE session_hash=?', [hash('sha256', $currentId)]));
    }

    private function authenticatedSession(int $authenticatedAt): void
    {
        $_SESSION = ['user' => ['id' => $this->userId], 'last_activity' => time()];
        $_SESSION['authn'] = [
            'method' => 'test',
            'authenticated_at' => $authenticatedAt,
            'absolute_expires_at' => $authenticatedAt + SessionPolicy::ABSOLUTE_SECONDS,
        ];
    }

    /** @param list<mixed> $params */
    private function value(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
