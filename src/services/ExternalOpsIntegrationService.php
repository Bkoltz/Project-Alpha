<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use RuntimeException;
use Throwable;

final class ExternalOpsIntegrationService
{
    public const SCHEMA_VERSION = 1;
    public const ROLES = [
        'role-admin',
        'role-operator',
    ];

    /**
     * Incremental events use the same least-privilege boundary as the full
     * snapshot. Keep this allowlist here so a future caller cannot expose a
     * pay-rate override, private sharing token, password hash, or unrelated
     * accounting field by passing a raw database row.
     *
     * @var array<string,list<string>>
     */
    private const PROJECTION_FIELDS = [
        'business_unit' => [
            'id', 'name', 'code', 'description', 'is_active', 'created_by',
            'created_at', 'updated_at',
        ],
        'project' => [
            'id', 'client_id', 'parent_id', 'organization_id', 'department_id',
            'business_unit_id', 'created_by', 'name', 'description', 'status',
            'start_date', 'end_date', 'estimated_start', 'estimated_end',
            'created_at', 'updated_at',
        ],
        'project_assignment' => [
            'id', 'project_id', 'user_id', 'assigned_at', 'ends_at',
            'created_by', 'created_at', 'updated_at', 'active',
        ],
        'operation' => [
            'id', 'project_id', 'business_unit_id', 'title', 'status',
            'scheduled_start_at', 'scheduled_end_at', 'location', 'notes',
            'created_by', 'created_at', 'updated_at',
        ],
        'operation_assignment' => [
            'operation_id', 'user_id', 'assignment_role', 'assigned_by',
            'assigned_at', 'updated_at',
        ],
        'task' => [
            'id', 'operation_id', 'project_id', 'business_unit_id',
            'assignee_user_id', 'title', 'status', 'due_at', 'notes',
            'created_by', 'created_at', 'updated_at',
        ],
        'task_assignment' => [
            'task_id', 'user_id', 'assigned_by', 'assigned_at', 'updated_at',
        ],
    ];

    /**
     * Save a deliberate access exception. Project Team membership is tracked
     * separately as automatic access and can never be erased by this method.
     *
     * @param list<int>|null $businessUnitIds Read-only oversight units.
     * @return array{event_id:string,entitlement_id:int}
     */
    public function saveAccountAccess(
        PDO $pdo,
        int $userId,
        string $applicationKey,
        bool $requestedAccess,
        int $actorUserId,
        ?array $businessUnitIds = null
    ): array {
        $account = $this->account($pdo, $userId);
        $isAdmin = $account['role'] === 'admin';
        $businessUnitIds = $isAdmin ? [] : ($businessUnitIds ?? []);
        if ($requestedAccess && !$isAdmin && $businessUnitIds === []) {
            throw new DomainException('Choose at least one Business Unit for a read-only access exception.');
        }
        return $this->persistEntitlement($pdo, $userId, $applicationKey, $requestedAccess, $businessUnitIds, $actorUserId);
    }

    /**
     * Refresh effective access after account, worker, or Project Team changes.
     *
     * @return array{event_id:string,entitlement_id:int}|null
     */
    public function resyncAccountAccess(
        PDO $pdo,
        int $userId,
        string $applicationKey,
        int $actorUserId
    ): ?array {
        $applicationKey = self::normalizeApplicationKey($applicationKey);
        $statement = $pdo->prepare('SELECT id FROM application_entitlements WHERE user_id=? AND application_key=? LIMIT 1');
        $statement->execute([$userId, $applicationKey]);
        $existing = $statement->fetchColumn();
        if ($existing === false && !$this->hasActiveProjectAssignment($pdo, $userId)) {
            return null;
        }
        return $this->recomputeEntitlement($pdo, $userId, $applicationKey, $actorUserId);
    }

    /**
     * @param list<int> $businessUnitIds
     * @return array{event_id:string,entitlement_id:int}
     */
    private function persistEntitlement(
        PDO $pdo,
        int $userId,
        string $applicationKey,
        bool $manualEnabled,
        array $businessUnitIds,
        int $actorUserId
    ): array {
        if ($userId < 1 || $actorUserId < 1) {
            throw new DomainException('A valid user and administrator are required.');
        }
        $applicationKey = self::normalizeApplicationKey($applicationKey);
        $businessUnitIds = array_values(array_unique(array_filter(
            array_map('intval', $businessUnitIds),
            static fn(int $id): bool => $id > 0
        )));
        $ownsTransaction = !$pdo->inTransaction();

        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }

            if ($businessUnitIds !== []) {
                $placeholders = implode(',', array_fill(0, count($businessUnitIds), '?'));
                // Existing selections remain valid when a business unit is
                // temporarily inactive; the receiving projection separately
                // filters inactive units from operational access.
                $unitStatement = $pdo->prepare("SELECT id FROM business_units WHERE id IN ($placeholders)");
                $unitStatement->execute($businessUnitIds);
                $validUnits = array_map('intval', $unitStatement->fetchAll(PDO::FETCH_COLUMN));
                sort($validUnits);
                $expectedUnits = $businessUnitIds;
                sort($expectedUnits);
                if ($validUnits !== $expectedUnits) {
                    throw new DomainException('One or more business units are unavailable.');
                }
            }

            $account = $this->account($pdo, $userId);
            $roleKey = $account['role'] === 'admin' ? 'role-admin' : 'role-operator';
            $automaticEnabled = !empty($account['active']) && $this->hasActiveProjectAssignment($pdo, $userId);
            $effectiveEnabled = !empty($account['active']) && ($manualEnabled || $automaticEnabled);
            $oversightEnabled = $manualEnabled && $roleKey !== 'role-admin' && $businessUnitIds !== [];

            $existing = $pdo->prepare('SELECT id FROM application_entitlements WHERE user_id = ? AND application_key = ? LIMIT 1');
            $existing->execute([$userId, $applicationKey]);
            $entitlementId = (int)($existing->fetchColumn() ?: 0);
            if ($entitlementId > 0) {
                $pdo->prepare('UPDATE application_entitlements SET enabled=?,manual_enabled=?,automatic_enabled=?,oversight_enabled=?,role_key=?,updated_by=? WHERE id=?')
                    ->execute([$effectiveEnabled ? 1 : 0, $manualEnabled ? 1 : 0, $automaticEnabled ? 1 : 0, $oversightEnabled ? 1 : 0, $roleKey, $actorUserId, $entitlementId]);
            } else {
                $pdo->prepare('INSERT INTO application_entitlements (user_id,application_key,enabled,manual_enabled,automatic_enabled,oversight_enabled,role_key,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$userId, $applicationKey, $effectiveEnabled ? 1 : 0, $manualEnabled ? 1 : 0, $automaticEnabled ? 1 : 0, $oversightEnabled ? 1 : 0, $roleKey, $actorUserId, $actorUserId]);
                $entitlementId = (int)$pdo->lastInsertId();
            }

            // Keep the legacy scope projection synchronized during the
            // compatibility window; older receivers read business_unit_ids,
            // while newer receivers use oversight_business_unit_ids.
            $pdo->prepare('DELETE FROM application_entitlement_business_units WHERE entitlement_id = ?')->execute([$entitlementId]);
            $pdo->prepare('DELETE FROM application_entitlement_oversight_units WHERE entitlement_id = ?')->execute([$entitlementId]);
            if ($businessUnitIds !== []) {
                $legacyScopeInsert = $pdo->prepare('INSERT INTO application_entitlement_business_units (entitlement_id,business_unit_id) VALUES (?,?)');
                $scopeInsert = $pdo->prepare('INSERT INTO application_entitlement_oversight_units (entitlement_id,business_unit_id) VALUES (?,?)');
                foreach ($businessUnitIds as $businessUnitId) {
                    $legacyScopeInsert->execute([$entitlementId, $businessUnitId]);
                    $scopeInsert->execute([$entitlementId, $businessUnitId]);
                }
            }

            $eventId = $this->enqueueCurrentState(
                $pdo,
                $userId,
                $applicationKey,
                $effectiveEnabled ? 'application_entitlement.changed' : 'application_entitlement.revoked'
            );
            if ($eventId === null) {
                throw new RuntimeException('Failed to queue the entitlement change.');
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
            return ['event_id' => $eventId, 'entitlement_id' => $entitlementId];
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{event_id:string,entitlement_id:int} */
    private function recomputeEntitlement(PDO $pdo, int $userId, string $applicationKey, int $actorUserId): array
    {
        $applicationKey = self::normalizeApplicationKey($applicationKey);
        $statement = $pdo->prepare('SELECT manual_enabled FROM application_entitlements WHERE user_id=? AND application_key=? LIMIT 1');
        $statement->execute([$userId, $applicationKey]);
        $manualEnabled = (bool)($statement->fetchColumn() ?: false);
        return $this->persistEntitlement(
            $pdo,
            $userId,
            $applicationKey,
            $manualEnabled,
            $this->existingOversightUnitIds($pdo, $userId, $applicationKey) ?? [],
            $actorUserId
        );
    }

    public function enqueueCurrentState(PDO $pdo, int $userId, string $applicationKey, string $eventType = 'user.changed'): ?string
    {
        $state = $this->entitlementState($pdo, $userId, self::normalizeApplicationKey($applicationKey));
        if ($state === null) {
            return null;
        }
        return $this->enqueueState($pdo, $state, $eventType);
    }

    public function enqueueDeprovisionBeforeDelete(PDO $pdo, int $userId, string $applicationKey): ?string
    {
        $state = $this->entitlementState($pdo, $userId, self::normalizeApplicationKey($applicationKey));
        if ($state === null) {
            return null;
        }
        $state['entitlement']['enabled'] = false;
        $state['user']['active'] = false;
        return $this->enqueueState($pdo, $state, 'application_entitlement.revoked');
    }

    /** @return array<string,mixed>|null */
    private function entitlementState(PDO $pdo, int $userId, string $applicationKey): ?array
    {
        $statement = $pdo->prepare(
            'SELECT ae.id AS entitlement_id,ae.application_key,ae.enabled,ae.manual_enabled,ae.automatic_enabled,ae.oversight_enabled,
                    u.id AS user_id,u.email,u.username,u.role,u.is_disabled,u.deleted_at,wp.display_name,wp.status AS worker_status
             FROM application_entitlements ae
             JOIN users u ON u.id = ae.user_id
             LEFT JOIN worker_profiles wp ON wp.user_id = u.id
             WHERE ae.user_id = ? AND ae.application_key = ?
             LIMIT 1'
        );
        $statement->execute([$userId, $applicationKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $legacyScope = $pdo->prepare('SELECT business_unit_id FROM application_entitlement_business_units WHERE entitlement_id = ? ORDER BY business_unit_id');
        $legacyScope->execute([(int)$row['entitlement_id']]);
        $oversightScope = $pdo->prepare('SELECT business_unit_id FROM application_entitlement_oversight_units WHERE entitlement_id = ? ORDER BY business_unit_id');
        $oversightScope->execute([(int)$row['entitlement_id']]);
        $isAdmin = (string)$row['role'] === 'admin';
        $legacyBusinessUnitIds = $isAdmin ? [] : array_map('intval', $legacyScope->fetchAll(PDO::FETCH_COLUMN));
        $oversightBusinessUnitIds = $isAdmin ? [] : array_map('intval', $oversightScope->fetchAll(PDO::FETCH_COLUMN));
        $businessUnitIds = array_values(array_unique(array_merge($legacyBusinessUnitIds, $oversightBusinessUnitIds)));
        $workerStatus = trim((string)($row['worker_status'] ?? ''));
        $userActive = empty($row['is_disabled'])
            && empty($row['deleted_at'])
            && ($workerStatus === '' || $workerStatus === 'active');

        return [
            'user' => [
                'id' => (int)$row['user_id'],
                'email' => strtolower(trim((string)$row['email'])),
                'display_name' => trim((string)($row['display_name'] ?: $row['username'] ?: $row['email'])),
                'active' => $userActive,
            ],
            'entitlement' => [
                'application_key' => (string)$row['application_key'],
                'enabled' => !empty($row['enabled']) && $userActive,
                'role_key' => $isAdmin ? 'role-admin' : 'role-operator',
                'business_unit_ids' => $businessUnitIds,
                'oversight_business_unit_ids' => $oversightBusinessUnitIds,
                'manual_access' => !empty($row['manual_enabled']),
                'automatic_access' => !empty($row['automatic_enabled']),
                'unit_oversight' => !empty($row['oversight_enabled']),
            ],
        ];
    }

    /**
     * Queue a least-privilege incremental projection change. The daily
     * snapshot remains the reconciliation authority.
     *
     * @param array<string,mixed> $data
     */
    public function enqueueProjectionChange(PDO $pdo, string $applicationKey, string $entityType, string|int $entityId, string $action, array $data): string
    {
        if (!in_array($action, ['upsert', 'revoke'], true)) {
            throw new DomainException('Invalid projection action.');
        }
        if (!isset(self::PROJECTION_FIELDS[$entityType])) {
            throw new DomainException('Unsupported projection entity type.');
        }
        $applicationKey = self::normalizeApplicationKey($applicationKey);
        $data = array_intersect_key($data, array_fill_keys(self::PROJECTION_FIELDS[$entityType], true));
        $eventId = $this->uuidV4();
        $occurredAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $sourceUpdatedAt = $occurredAt;
        if (!empty($data['updated_at'])) {
            try {
                $sourceUpdatedAt = (new DateTimeImmutable((string)$data['updated_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
            } catch (Throwable $ignored) {
                $sourceUpdatedAt = $occurredAt;
            }
        }
        $payload = [
            'event_id' => $eventId,
            'event_type' => 'projection.changed',
            'occurred_at' => $occurredAt,
            'schema_version' => self::SCHEMA_VERSION,
            'application_key' => $applicationKey,
            'projection' => [
                'entity_type' => $entityType,
                'entity_id' => (string)$entityId,
                'action' => $action,
                'source_updated_at' => $sourceUpdatedAt,
                'data' => $data,
            ],
        ];
        $databaseTime = (new DateTimeImmutable($occurredAt))->format('Y-m-d H:i:s.u');
        $pdo->prepare('INSERT INTO integration_outbox (event_id,integration_key,event_type,schema_version,payload_json,occurred_at,next_attempt_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$eventId, $applicationKey, 'projection.changed', self::SCHEMA_VERSION, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $databaseTime, $databaseTime]);
        return $eventId;
    }

    /** @param array<string,mixed> $state */
    private function enqueueState(PDO $pdo, array $state, string $eventType): string
    {
        $eventId = $this->uuidV4();
        $occurredAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $payload = [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'occurred_at' => $occurredAt,
            'schema_version' => self::SCHEMA_VERSION,
            'user' => $state['user'],
            'entitlement' => $state['entitlement'],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $databaseTime = (new DateTimeImmutable($occurredAt))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        $pdo->prepare(
            'INSERT INTO integration_outbox
                (event_id,integration_key,event_type,schema_version,payload_json,occurred_at,next_attempt_at)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $eventId,
            (string)$state['entitlement']['application_key'],
            $eventType,
            self::SCHEMA_VERSION,
            $json,
            $databaseTime,
            $databaseTime,
        ]);
        return $eventId;
    }

    public static function normalizeApplicationKey(string $applicationKey): string
    {
        $applicationKey = strtolower(trim($applicationKey));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $applicationKey)) {
            throw new DomainException('The application key must be 2 to 64 characters using letters, numbers, underscores, or hyphens.');
        }
        return $applicationKey;
    }

    /** @return array{role:string,active:bool} */
    private function account(PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare('SELECT u.role,u.is_disabled,u.deleted_at,wp.status AS worker_status FROM users u LEFT JOIN worker_profiles wp ON wp.user_id=u.id WHERE u.id=? LIMIT 1');
        $statement->execute([$userId]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account)) {
            throw new DomainException('User not found.');
        }
        $workerStatus = trim((string)($account['worker_status'] ?? ''));
        return ['role' => (string)$account['role'], 'active' => empty($account['is_disabled']) && empty($account['deleted_at']) && ($workerStatus === '' || $workerStatus === 'active')];
    }

    private function hasActiveProjectAssignment(PDO $pdo, int $userId): bool
    {
        $statement = $pdo->prepare('SELECT 1 FROM project_assignments WHERE user_id=? AND (ends_at IS NULL OR ends_at>CURRENT_TIMESTAMP) LIMIT 1');
        $statement->execute([$userId]);
        return $statement->fetchColumn() !== false;
    }

    /** @return list<int>|null */
    private function existingOversightUnitIds(PDO $pdo, int $userId, string $applicationKey): ?array
    {
        $applicationKey = self::normalizeApplicationKey($applicationKey);
        $entitlement = $pdo->prepare(
            'SELECT id FROM application_entitlements WHERE user_id=? AND application_key=? LIMIT 1'
        );
        $entitlement->execute([$userId, $applicationKey]);
        $entitlementId = (int)($entitlement->fetchColumn() ?: 0);
        if ($entitlementId < 1) {
            return null;
        }
        $scope = $pdo->prepare(
            'SELECT business_unit_id FROM application_entitlement_oversight_units
             WHERE entitlement_id=? ORDER BY business_unit_id'
        );
        $scope->execute([$entitlementId]);
        return array_map('intval', $scope->fetchAll(PDO::FETCH_COLUMN));
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
