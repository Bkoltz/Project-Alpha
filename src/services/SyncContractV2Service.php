<?php

declare(strict_types=1);

namespace App\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use UnexpectedValueException;

/**
 * Owns the provider-neutral identity, resource-version, event-sequence, and
 * snapshot-session primitives used by Sync Contract v2.
 *
 * Domain mutations are not permitted to append an event outside their existing
 * database transaction. That invariant lets a future mutation adapter update
 * authoritative state, resource state, and the event log atomically.
 */
final class SyncContractV2Service
{
    public const CONTRACT_VERSION = '2.0';
    public const SNAPSHOT_TTL_MINUTES = 30;
    public const MAX_ACTIVE_SNAPSHOTS_PER_KEY = 10;

    public function sourceInstanceId(PDO $pdo): string
    {
        $value = $pdo->query(
            'SELECT source_instance_id FROM sync_source_identity WHERE singleton = 1'
        )->fetchColumn();
        $sourceId = strtolower(trim((string)$value));
        if (!$this->isUuid($sourceId)) {
            throw new RuntimeException('Sync Contract v2 source identity is unavailable.');
        }
        return $sourceId;
    }

    /**
     * @return array{id:string,source_instance_id:string,high_water_sequence:string,generated_at:string,expires_at:string}
     */
    public function beginSnapshot(
        PDO $pdo,
        int $apiKeyId,
        ?DateTimeImmutable $now = null
    ): array {
        $this->requireTransaction($pdo);
        if ($apiKeyId < 1) {
            throw new InvalidArgumentException('A valid API key principal is required.');
        }

        $sourceId = $this->sourceInstanceId($pdo);
        $generatedAt = $this->utc($now);
        $expiresAt = $generatedAt->add(new DateInterval('PT' . self::SNAPSHOT_TTL_MINUTES . 'M'));
        $generatedAtDatabase = $this->databaseTimestamp($generatedAt);
        $cleanup = $pdo->prepare('DELETE FROM sync_snapshot_sessions WHERE expires_at <= ?');
        $cleanup->execute([$generatedAtDatabase]);
        $active = $pdo->prepare(
            'SELECT COUNT(*) FROM sync_snapshot_sessions WHERE api_key_id = ? AND expires_at > ?'
        );
        $active->execute([$apiKeyId, $generatedAtDatabase]);
        if ((int)$active->fetchColumn() >= self::MAX_ACTIVE_SNAPSHOTS_PER_KEY) {
            throw new DomainException('Too many active snapshot sessions for this API principal.');
        }
        $highWater = (string)($pdo->query(
            'SELECT COALESCE(MAX(sequence), 0) FROM sync_event_log'
        )->fetchColumn() ?: '0');
        $snapshotId = $this->uuidV4();

        $statement = $pdo->prepare(
            'INSERT INTO sync_snapshot_sessions
                (snapshot_id, source_instance_id, api_key_id, high_water_sequence, generated_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $snapshotId,
            $sourceId,
            $apiKeyId,
            $highWater,
            $generatedAtDatabase,
            $this->databaseTimestamp($expiresAt),
        ]);

        return [
            'id' => $snapshotId,
            'source_instance_id' => $sourceId,
            'high_water_sequence' => $highWater,
            'generated_at' => $this->contractTimestamp($generatedAt),
            'expires_at' => $this->contractTimestamp($expiresAt),
        ];
    }

    /**
     * @return array{id:string,source_instance_id:string,high_water_sequence:string,generated_at:string,expires_at:string}
     */
    public function resumeSnapshot(
        PDO $pdo,
        string $snapshotId,
        int $apiKeyId,
        ?DateTimeImmutable $now = null
    ): array {
        $this->requireTransaction($pdo);
        $snapshotId = strtolower(trim($snapshotId));
        if (!$this->isUuid($snapshotId)) {
            throw new InvalidArgumentException('snapshot_id must be a UUID.');
        }

        $statement = $pdo->prepare(
            'SELECT snapshot_id, source_instance_id, api_key_id, high_water_sequence,
                    generated_at, expires_at
             FROM sync_snapshot_sessions
             WHERE snapshot_id = ?'
        );
        $statement->execute([$snapshotId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('The snapshot session does not exist.');
        }
        if ((int)$row['api_key_id'] !== $apiKeyId) {
            throw new DomainException('The snapshot session belongs to a different API principal.');
        }

        $expiresAt = $this->parseDatabaseTimestamp((string)$row['expires_at']);
        if ($expiresAt <= $this->utc($now)) {
            throw new DomainException('The snapshot session has expired.');
        }

        return [
            'id' => (string)$row['snapshot_id'],
            'source_instance_id' => (string)$row['source_instance_id'],
            'high_water_sequence' => (string)$row['high_water_sequence'],
            'generated_at' => $this->contractTimestamp(
                $this->parseDatabaseTimestamp((string)$row['generated_at'])
            ),
            'expires_at' => $this->contractTimestamp($expiresAt),
        ];
    }

    /**
     * Seed a resource at version 1 during bootstrap, or verify that a later
     * snapshot projection matches the version state maintained by mutations.
     *
     * @param array<string,mixed> $data
     */
    public function observeResource(PDO $pdo, string $resourceType, string $resourceId, array $data): int
    {
        $this->requireTransaction($pdo);
        [$resourceType, $resourceId] = $this->normalizeResourceIdentity($resourceType, $resourceId);
        $hash = hash('sha256', $this->canonicalJson($data));
        $state = $this->lockResourceState($pdo, $resourceType, $resourceId);

        if ($state === null) {
            $statement = $pdo->prepare(
                'INSERT INTO sync_resource_state
                    (resource_type, resource_id, resource_version, content_sha256, present)
                 VALUES (?, ?, 1, ?, 1)'
            );
            $statement->execute([$resourceType, $resourceId, $hash]);
            return 1;
        }

        if ((int)$state['present'] !== 1 || !hash_equals((string)$state['content_sha256'], $hash)) {
            throw new UnexpectedValueException(
                "Sync state mismatch for {$resourceType}:{$resourceId}; "
                . 'the authoritative mutation was not recorded through Sync Contract v2.'
            );
        }

        return (int)$state['resource_version'];
    }

    /**
     * Append a v2 event inside the caller's authoritative domain transaction.
     * Returns null for an idempotent no-op.
     *
     * @param array<string,mixed>|null $data
     * @return array<string,mixed>|null
     */
    public function recordEvent(
        PDO $pdo,
        string $resourceType,
        string $resourceId,
        string $action,
        ?array $data,
        ?DateTimeImmutable $occurredAt = null
    ): ?array {
        $this->requireTransaction($pdo);
        [$resourceType, $resourceId] = $this->normalizeResourceIdentity($resourceType, $resourceId);
        $action = strtolower(trim($action));
        if (!in_array($action, ['upsert', 'delete'], true)) {
            throw new InvalidArgumentException('Sync Contract v2 actions are upsert or delete.');
        }
        if ($action === 'upsert' && $data === null) {
            throw new InvalidArgumentException('An upsert event requires resource data.');
        }
        if ($action === 'delete') {
            $data = null;
        }

        $present = $action === 'upsert' ? 1 : 0;
        $hash = hash('sha256', $this->canonicalJson($data));
        $state = $this->lockResourceState($pdo, $resourceType, $resourceId);
        if ($state !== null
            && (int)$state['present'] === $present
            && hash_equals((string)$state['content_sha256'], $hash)
        ) {
            return null;
        }

        $version = $state === null ? 1 : ((int)$state['resource_version'] + 1);
        if ($state === null) {
            $statement = $pdo->prepare(
                'INSERT INTO sync_resource_state
                    (resource_type, resource_id, resource_version, content_sha256, present)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $statement->execute([$resourceType, $resourceId, $version, $hash, $present]);
        } else {
            $statement = $pdo->prepare(
                'UPDATE sync_resource_state
                 SET resource_version = ?, content_sha256 = ?, present = ?
                 WHERE resource_type = ? AND resource_id = ?'
            );
            $statement->execute([$version, $hash, $present, $resourceType, $resourceId]);
        }

        $sourceId = $this->sourceInstanceId($pdo);
        $eventId = $this->uuidV4();
        $occurredAt = $this->utc($occurredAt);
        $statement = $pdo->prepare(
            'INSERT INTO sync_event_log
                (event_id, source_instance_id, resource_type, resource_id,
                 resource_version, action, payload, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $eventId,
            $sourceId,
            $resourceType,
            $resourceId,
            $version,
            $action,
            $data === null ? null : $this->canonicalJson($data),
            $this->databaseTimestamp($occurredAt),
        ]);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'source_instance_id' => $sourceId,
            'sequence' => (string)$pdo->lastInsertId(),
            'event_id' => $eventId,
            'occurred_at' => $this->contractTimestamp($occurredAt),
            'resource' => [
                'type' => $resourceType,
                'id' => $resourceId,
                'version' => (string)$version,
            ],
            'action' => $action,
            'data' => $data,
        ];
    }

    /**
     * @param array<string,mixed>|list<mixed>|null $value
     */
    public function canonicalJson(array|null $value): string
    {
        return json_encode(
            $this->sortCanonical($value),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @return array{0:string,1:string}
     */
    private function normalizeResourceIdentity(string $resourceType, string $resourceId): array
    {
        $resourceType = strtolower(trim($resourceType));
        $resourceId = trim($resourceId);
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $resourceType)) {
            throw new InvalidArgumentException('Invalid resource type.');
        }
        if ($resourceId === '' || strlen($resourceId) > 191) {
            throw new InvalidArgumentException('Invalid resource ID.');
        }
        return [$resourceType, $resourceId];
    }

    /**
     * @return array{resource_version:mixed,content_sha256:mixed,present:mixed}|null
     */
    private function lockResourceState(PDO $pdo, string $resourceType, string $resourceId): ?array
    {
        $sql = 'SELECT resource_version, content_sha256, present
                FROM sync_resource_state
                WHERE resource_type = ? AND resource_id = ?';
        if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $pdo->prepare($sql);
        $statement->execute([$resourceType, $resourceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function requireTransaction(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException(
                'Sync Contract v2 metadata must be recorded inside the authoritative database transaction.'
            );
        }
    }

    private function utc(?DateTimeImmutable $value): DateTimeImmutable
    {
        return ($value ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private function databaseTimestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }

    private function contractTimestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d\TH:i:s.u\Z');
    }

    private function parseDatabaseTimestamp(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function isUuid(string $value): bool
    {
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value
        );
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private function sortCanonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->sortCanonical($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortCanonical($item);
        }
        return $value;
    }
}
