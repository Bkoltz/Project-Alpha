<?php

declare(strict_types=1);

namespace App\Security;

use PDO;
use SessionHandlerInterface;

final class DatabaseSessionHandler implements SessionHandlerInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $idleSeconds = 900,
        private readonly int $absoluteSeconds = 604800
    ) {}

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT payload FROM app_sessions
             WHERE session_hash=? AND last_activity_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL ? SECOND)
             AND absolute_expires_at>UTC_TIMESTAMP(6) LIMIT 1'
        );
        $stmt->execute([$this->hash($id), $this->idleSeconds]);
        $payload = $stmt->fetchColumn();
        if ($payload === false) {
            $this->destroy($id);
            return '';
        }
        return (string) $payload;
    }

    public function write(string $id, string $data): bool
    {
        $userId = isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_sessions (session_hash,user_id,payload,last_activity_at,absolute_expires_at)
             VALUES (?,?,?,UTC_TIMESTAMP(6),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND))
             ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),payload=VALUES(payload),last_activity_at=VALUES(last_activity_at)'
        );
        return $stmt->execute([$this->hash($id), $userId, $data, $this->absoluteSeconds]);
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM app_sessions WHERE session_hash=?');
        return $stmt->execute([$this->hash($id)]);
    }

    public function gc(int $maxLifetime): int|false
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM app_sessions WHERE absolute_expires_at<=UTC_TIMESTAMP(6)
             OR last_activity_at<DATE_SUB(UTC_TIMESTAMP(6),INTERVAL ? SECOND)'
        );
        $stmt->execute([$this->idleSeconds]);
        return $stmt->rowCount();
    }

    private function hash(string $id): string { return hash('sha256', $id); }
}
