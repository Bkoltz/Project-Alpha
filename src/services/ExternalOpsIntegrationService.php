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
    public const APPLICATION_KEY = 'ltds_ops';
    public const ROLES = [
        'role-admin',
        'role-operator',
    ];

    /**
     * Persist the account-form access decision using PA's account role and
     * workforce assignments as the only sources of authorization truth.
     *
     * @param list<int>|null $businessUnitIds Explicit non-admin scope, or null
     *        to preserve an existing scope and default a new entitlement from Workforce.
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

        // Only PA's exact administrator role maps to Ops administration. In
        // particular, PA owner is a workforce relationship, not Ops admin.
        $isAdmin = $account['role'] === 'admin';
        $roleKey = $isAdmin ? 'role-admin' : 'role-operator';
        if ($isAdmin) {
            // Retain a prior employee scope through a promotion. Admin
            // payloads remain global because entitlementState omits units.
            $businessUnitIds = $this->existingBusinessUnitIds($pdo, $userId, $applicationKey) ?? [];
        } elseif ($businessUnitIds === null) {
            $businessUnitIds = $this->existingBusinessUnitIds($pdo, $userId, $applicationKey)
                ?? $this->workerBusinessUnitIds($pdo, $userId);
        }

        return $this->persistEntitlement(
            $pdo,
            $userId,
            $applicationKey,
            $requestedAccess,
            $roleKey,
            $businessUnitIds,
            $actorUserId
        );
    }

    /**
     * Refresh an existing ACL record after PA account or Workforce scope data
     * changes. Accounts without an LTDS Ops ACL are intentionally ignored.
     *
     * @return array{event_id:string,entitlement_id:int}|null
     */
    public function resyncAccountAccess(
        PDO $pdo,
        int $userId,
        string $applicationKey,
        int $actorUserId
    ): ?array {
        $applicationKey = $this->normalizeApplicationKey($applicationKey);
        $statement = $pdo->prepare(
            'SELECT enabled FROM application_entitlements WHERE user_id=? AND application_key=? LIMIT 1'
        );
        $statement->execute([$userId, $applicationKey]);
        $enabled = $statement->fetchColumn();
        if ($enabled === false) {
            return null;
        }

        return $this->saveAccountAccess($pdo, $userId, $applicationKey, !empty($enabled), $actorUserId);
    }

    /**
     * @param list<int> $businessUnitIds
     * @return array{event_id:string,entitlement_id:int}
     */
    private function persistEntitlement(
        PDO $pdo,
        int $userId,
        string $applicationKey,
        bool $enabled,
        string $roleKey,
        array $businessUnitIds,
        int $actorUserId
    ): array {
        if ($userId < 1 || $actorUserId < 1) {
            throw new DomainException('A valid user and administrator are required.');
        }
        if (!in_array($roleKey, self::ROLES, true)) {
            throw new DomainException('Invalid external application role.');
        }
        if ($roleKey === 'role-owner') {
            throw new DomainException('The protected owner role cannot be provisioned from Project Alpha.');
        }

        $applicationKey = $this->normalizeApplicationKey($applicationKey);
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

            $existing = $pdo->prepare('SELECT id FROM application_entitlements WHERE user_id = ? AND application_key = ? LIMIT 1');
            $existing->execute([$userId, $applicationKey]);
            $entitlementId = (int)($existing->fetchColumn() ?: 0);
            if ($entitlementId > 0) {
                $pdo->prepare('UPDATE application_entitlements SET enabled = ?, role_key = ?, updated_by = ? WHERE id = ?')
                    ->execute([$enabled ? 1 : 0, $roleKey, $actorUserId, $entitlementId]);
            } else {
                $pdo->prepare('INSERT INTO application_entitlements (user_id,application_key,enabled,role_key,created_by,updated_by) VALUES (?,?,?,?,?,?)')
                    ->execute([$userId, $applicationKey, $enabled ? 1 : 0, $roleKey, $actorUserId, $actorUserId]);
                $entitlementId = (int)$pdo->lastInsertId();
            }

            $pdo->prepare('DELETE FROM application_entitlement_business_units WHERE entitlement_id = ?')->execute([$entitlementId]);
            if ($businessUnitIds !== []) {
                $scopeInsert = $pdo->prepare('INSERT INTO application_entitlement_business_units (entitlement_id,business_unit_id) VALUES (?,?)');
                foreach ($businessUnitIds as $businessUnitId) {
                    $scopeInsert->execute([$entitlementId, $businessUnitId]);
                }
            }

            $eventId = $this->enqueueCurrentState(
                $pdo,
                $userId,
                $applicationKey,
                $enabled ? 'application_entitlement.changed' : 'application_entitlement.revoked'
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

    public function enqueueCurrentState(PDO $pdo, int $userId, string $applicationKey, string $eventType = 'user.changed'): ?string
    {
        $state = $this->entitlementState($pdo, $userId, $this->normalizeApplicationKey($applicationKey));
        if ($state === null) {
            return null;
        }
        return $this->enqueueState($pdo, $state, $eventType);
    }

    public function enqueueDeprovisionBeforeDelete(PDO $pdo, int $userId, string $applicationKey): ?string
    {
        $state = $this->entitlementState($pdo, $userId, $this->normalizeApplicationKey($applicationKey));
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
            'SELECT ae.id AS entitlement_id,ae.application_key,ae.enabled,
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

        $scope = $pdo->prepare('SELECT business_unit_id FROM application_entitlement_business_units WHERE entitlement_id = ? ORDER BY business_unit_id');
        $scope->execute([(int)$row['entitlement_id']]);
        $isAdmin = (string)$row['role'] === 'admin';
        $businessUnitIds = $isAdmin ? [] : array_map('intval', $scope->fetchAll(PDO::FETCH_COLUMN));
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
            ],
        ];
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

    private function normalizeApplicationKey(string $applicationKey): string
    {
        $applicationKey = strtolower(trim($applicationKey));
        if ($applicationKey !== self::APPLICATION_KEY) {
            throw new DomainException('LTDS Operations must use the application key ltds_ops.');
        }
        return $applicationKey;
    }

    /** @return array{role:string} */
    private function account(PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare('SELECT role FROM users WHERE id=? AND deleted_at IS NULL LIMIT 1');
        $statement->execute([$userId]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account)) {
            throw new DomainException('User not found.');
        }
        return ['role' => (string)$account['role']];
    }

    /** @return list<int> */
    private function workerBusinessUnitIds(PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare(
            'SELECT wbu.business_unit_id
             FROM worker_profiles wp
             JOIN worker_business_units wbu ON wbu.worker_profile_id = wp.id
             JOIN business_units bu ON bu.id = wbu.business_unit_id AND bu.is_active = 1
             WHERE wp.user_id = ?
               AND (wbu.ends_at IS NULL OR wbu.ends_at > CURRENT_TIMESTAMP)
             ORDER BY wbu.business_unit_id'
        );
        $statement->execute([$userId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<int>|null */
    private function existingBusinessUnitIds(PDO $pdo, int $userId, string $applicationKey): ?array
    {
        $applicationKey = $this->normalizeApplicationKey($applicationKey);
        $entitlement = $pdo->prepare(
            'SELECT id FROM application_entitlements WHERE user_id=? AND application_key=? LIMIT 1'
        );
        $entitlement->execute([$userId, $applicationKey]);
        $entitlementId = (int)($entitlement->fetchColumn() ?: 0);
        if ($entitlementId < 1) {
            return null;
        }
        $scope = $pdo->prepare(
            'SELECT business_unit_id FROM application_entitlement_business_units
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
