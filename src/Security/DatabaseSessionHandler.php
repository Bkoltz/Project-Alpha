<?php

declare(strict_types=1);

namespace App\Security;

use PDO;
use RuntimeException;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /** @var array<string,string> hash => lock name */
    private array $locks = [];
    /** @var array<string,'active'|'new'|'revoked'> */
    private array $states = [];
    private ?string $preservedAbsoluteDeadline = null;
    private int $requestStartedAt;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $idleSeconds = SessionPolicy::IDLE_SECONDS,
        private readonly int $absoluteSeconds = SessionPolicy::ABSOLUTE_SECONDS,
        private readonly int $lockWaitSeconds = 30
    ) {
        $this->requestStartedAt = time();
    }

    public function open(string $path, string $name): bool { return true; }

    public function close(): bool
    {
        $ok = true;
        foreach ($this->locks as $lockName) {
            try {
                $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
                $stmt->execute([$lockName]);
                $ok = (int)$stmt->fetchColumn() === 1 && $ok;
            } catch (\Throwable) {
                $ok = false;
            }
        }
        $this->locks = [];
        $this->states = [];
        $this->preservedAbsoluteDeadline = null;
        return $ok;
    }

    public function read(string $id): string|false
    {
        $hash = $this->hash($id);
        $this->lock($hash);
        $row = $this->row($hash);
        if ($row === null) {
            $this->states[$hash] = 'new';
            return '';
        }
        if (!$this->rowIsActive($row)) {
            $this->tombstone($hash);
            $this->states[$hash] = 'revoked';
            return '';
        }
        $this->states[$hash] = 'active';
        $rowDeadline = (string)$row['absolute_expires_at'];
        if (strtotime($rowDeadline . ' UTC') !== false
            && ($this->preservedAbsoluteDeadline === null
                || strcmp($rowDeadline, $this->preservedAbsoluteDeadline) < 0)) {
            $this->preservedAbsoluteDeadline = $rowDeadline;
        }
        return (string)$row['payload'];
    }

    public function write(string $id, string $data): bool
    {
        $hash = $this->hash($id);
        $this->lock($hash);
        $state = $this->states[$hash] ?? null;
        if ($state === null) {
            $row = $this->row($hash);
            $state = $row === null ? 'new' : ($this->rowIsActive($row) ? 'active' : 'revoked');
            $this->states[$hash] = $state;
        }
        if ($state === 'revoked') {
            return true;
        }

        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        $now = time();
        $lastActivity = $userId === null ? $now : (int)($_SESSION['last_activity'] ?? $now);
        $deadline = $userId === null
            ? $now + $this->absoluteSeconds
            : SessionPolicy::authenticationDeadline($now);
        $deadlineUtc = $this->utc($deadline);
        $authenticatedAt = (int)($_SESSION['authn']['authenticated_at'] ?? 0);
        $isNewAuthentication = $authenticatedAt >= $this->requestStartedAt;
        $deadlineComparable = $deadlineUtc . '.000000';
        if ($userId !== null && !$isNewAuthentication && $this->preservedAbsoluteDeadline !== null
            && strcmp($this->preservedAbsoluteDeadline, $deadlineComparable) <= 0) {
            $deadlineUtc = $this->preservedAbsoluteDeadline;
        }

        if ($state === 'active') {
            $stmt = $this->pdo->prepare(
                'UPDATE app_sessions
                 SET user_id=?,payload=?,last_activity_at=?,absolute_expires_at=LEAST(absolute_expires_at,?)
                 WHERE session_hash=? AND revoked_at IS NULL
                   AND last_activity_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL ? SECOND)
                   AND absolute_expires_at>UTC_TIMESTAMP(6)'
            );
            $stmt->execute([
                $userId,
                $data,
                $this->utc($lastActivity),
                $deadlineUtc,
                $hash,
                $this->idleSeconds,
            ]);
            if ($stmt->rowCount() === 0 && !$this->validateId($id)) {
                $this->tombstone($hash);
                $this->states[$hash] = 'revoked';
            }
            return true;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO app_sessions
                (session_hash,user_id,payload,last_activity_at,absolute_expires_at,revoked_at)
             VALUES (?,?,?,?,?,NULL)'
        );
        try {
            $created = $stmt->execute([
                $hash,
                $userId,
                $data,
                $this->utc($lastActivity),
                $deadlineUtc,
            ]);
            $this->states[$hash] = $created ? 'active' : 'revoked';
            return $created;
        } catch (\Throwable $error) {
            // A collision or tombstone must never be overwritten/upserted.
            $this->states[$hash] = 'revoked';
            if ($this->row($hash) !== null) {
                $this->tombstone($hash);
                return true;
            }
            throw $error;
        }
    }

    public function destroy(string $id): bool
    {
        $hash = $this->hash($id);
        $this->lock($hash);
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_sessions
                (session_hash,user_id,payload,last_activity_at,absolute_expires_at,revoked_at)
             VALUES (?,NULL,?,UTC_TIMESTAMP(6),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND),UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE user_id=NULL,payload=VALUES(payload),revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP(6))'
        );
        $ok = $stmt->execute([$hash, '', $this->absoluteSeconds]);
        $this->states[$hash] = 'revoked';
        return $ok;
    }

    public function gc(int $maxLifetime): int|false
    {
        $expired = $this->pdo->prepare(
            'UPDATE app_sessions SET user_id=NULL,payload=?,revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP(6))
             WHERE revoked_at IS NULL AND (
                absolute_expires_at<=UTC_TIMESTAMP(6)
                OR last_activity_at<DATE_SUB(UTC_TIMESTAMP(6),INTERVAL ? SECOND)
             )'
        );
        $expired->execute(['', $this->idleSeconds]);
        $expiredCount = $expired->rowCount();

        $delete = $this->pdo->prepare(
            'DELETE FROM app_sessions
             WHERE revoked_at<DATE_SUB(UTC_TIMESTAMP(6),INTERVAL ? SECOND)'
        );
        $delete->execute([$this->absoluteSeconds]);
        return $expiredCount + $delete->rowCount();
    }

    public function validateId(string $id): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM app_sessions
             WHERE session_hash=? AND revoked_at IS NULL
               AND last_activity_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL ? SECOND)
               AND absolute_expires_at>UTC_TIMESTAMP(6) LIMIT 1'
        );
        $stmt->execute([$this->hash($id), $this->idleSeconds]);
        return $stmt->fetchColumn() !== false;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        // Intentional activity changes $_SESSION['last_activity'], causing PHP
        // to call write(). Passive/read-only traffic must not move DB idle time.
        return $this->validateId($id);
    }

    /** @return array<string,mixed>|null */
    private function row(string $hash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT payload,last_activity_at,absolute_expires_at,revoked_at,
                    last_activity_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL ? SECOND) idle_valid,
                    absolute_expires_at>UTC_TIMESTAMP(6) absolute_valid
             FROM app_sessions WHERE session_hash=? LIMIT 1'
        );
        $stmt->execute([$this->idleSeconds, $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $row */
    private function rowIsActive(array $row): bool
    {
        return $row['revoked_at'] === null
            && (int)$row['idle_valid'] === 1
            && (int)$row['absolute_valid'] === 1;
    }

    private function tombstone(string $hash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE app_sessions
             SET user_id=NULL,payload=?,revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP(6))
             WHERE session_hash=?'
        );
        $stmt->execute(['', $hash]);
    }

    private function lock(string $hash): void
    {
        if (isset($this->locks[$hash])) {
            return;
        }
        $lockName = 'pa:' . substr($hash, 0, 61);
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?,?)');
        $stmt->execute([$lockName, $this->lockWaitSeconds]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire the database session lock.');
        }
        $this->locks[$hash] = $lockName;
    }

    private function hash(string $id): string { return hash('sha256', $id); }
    private function utc(int $timestamp): string { return gmdate('Y-m-d H:i:s', $timestamp); }
}
