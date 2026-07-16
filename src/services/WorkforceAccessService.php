<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/** Capability and data scope are intentionally separate from worker classification. */
final class WorkforceAccessService
{
    public function __construct(private readonly PDO $pdo) {}

    public function actor(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id user_id,u.role,wp.id worker_profile_id,wp.relationship_type,wp.status,wp.display_name
             FROM users u LEFT JOIN worker_profiles wp ON wp.user_id=u.id
             WHERE u.id=? AND u.deleted_at IS NULL AND u.is_disabled=0'
        );
        $stmt->execute([$userId]);
        $actor = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$actor) {
            return [];
        }
        $actor['capabilities'] = $this->capabilities((int)($actor['worker_profile_id'] ?? 0), (string)$actor['role']);
        $actor['business_units'] = $this->businessUnits((int)($actor['worker_profile_id'] ?? 0));
        return $actor;
    }

    public function capabilities(int $workerProfileId, string $role = ''): array
    {
        if (in_array($role, ['admin', 'owner'], true)) {
            return ['*' => 'all'];
        }
        if ($workerProfileId <= 0) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT capability,access_scope FROM worker_capability_scopes
             WHERE worker_profile_id=? AND allowed=1'
        );
        $stmt->execute([$workerProfileId]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function can(int $userId, string $capability, string $resource, int $resourceId): bool
    {
        $actor = $this->actor($userId);
        if (!$actor) {
            return false;
        }
        $capabilities = $actor['capabilities'];
        $scope = $capabilities['*'] ?? $capabilities[$capability] ?? null;
        if ($scope === null) {
            return false;
        }
        if ($scope === 'all') {
            return true;
        }
        return match ($resource) {
            'client' => $this->clientInScope($userId, (int)($actor['worker_profile_id'] ?? 0), $resourceId, $scope),
            'job' => $this->jobInScope($userId, (int)($actor['worker_profile_id'] ?? 0), $resourceId, $scope),
            'assignment' => $this->assignmentInScope((int)($actor['worker_profile_id'] ?? 0), $resourceId, $scope),
            default => false,
        };
    }

    public function clientDirectory(int $userId, string $search, int $limit = 25): array
    {
        $actor = $this->actor($userId);
        if (!$actor) {
            return [];
        }
        $scope = $actor['capabilities']['*'] ?? $actor['capabilities']['clients.search']
            ?? $actor['capabilities']['workforce.directory.search'] ?? null;
        if ($scope === null) {
            return [];
        }
        $params = ['%' . trim($search) . '%'];
        $where = "c.archived=0 AND c.deleted_at IS NULL AND c.name LIKE ?";
        if ($scope === 'own') {
            $where .= ' AND c.created_by=?';
            $params[] = $userId;
        } elseif ($scope === 'assigned' || $scope === 'business_unit') {
            $where .= ' AND EXISTS (SELECT 1 FROM client_business_units cbu JOIN worker_business_units wbu ON wbu.business_unit_id=cbu.business_unit_id WHERE cbu.client_id=c.id AND wbu.worker_profile_id=? AND (wbu.ends_at IS NULL OR wbu.ends_at>UTC_TIMESTAMP(6)))';
            $params[] = (int)$actor['worker_profile_id'];
        }
        $stmt = $this->pdo->prepare("SELECT c.id,c.name,c.organization_id FROM clients c WHERE {$where} ORDER BY c.name LIMIT " . max(1, min(100, $limit)));
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function businessUnits(int $workerProfileId): array
    {
        if ($workerProfileId <= 0) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT bu.id,bu.name,bu.code,wbu.is_lead FROM worker_business_units wbu
             JOIN business_units bu ON bu.id=wbu.business_unit_id
             WHERE wbu.worker_profile_id=? AND bu.is_active=1
               AND (wbu.ends_at IS NULL OR wbu.ends_at>UTC_TIMESTAMP(6)) ORDER BY bu.name'
        );
        $stmt->execute([$workerProfileId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function clientInScope(int $userId, int $workerProfileId, int $clientId, string $scope): bool
    {
        if ($scope === 'own') {
            $stmt = $this->pdo->prepare('SELECT 1 FROM clients WHERE id=? AND created_by=?');
            $stmt->execute([$clientId, $userId]);
            return (bool)$stmt->fetchColumn();
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM client_business_units cbu JOIN worker_business_units wbu ON wbu.business_unit_id=cbu.business_unit_id
             WHERE cbu.client_id=? AND wbu.worker_profile_id=? AND (wbu.ends_at IS NULL OR wbu.ends_at>UTC_TIMESTAMP(6)) LIMIT 1'
        );
        $stmt->execute([$clientId, $workerProfileId]);
        return (bool)$stmt->fetchColumn();
    }

    private function jobInScope(int $userId, int $workerProfileId, int $jobId, string $scope): bool
    {
        if ($scope === 'own') {
            $stmt = $this->pdo->prepare('SELECT 1 FROM jobs WHERE id=? AND created_by=?');
            $stmt->execute([$jobId, $userId]);
            return (bool)$stmt->fetchColumn();
        }
        if ($scope === 'assigned') {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM work_assignments wa JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id
                 WHERE jwc.job_id=? AND wa.worker_profile_id=? AND wa.status NOT IN ("declined","cancelled") LIMIT 1'
            );
            $stmt->execute([$jobId, $workerProfileId]);
            return (bool)$stmt->fetchColumn();
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM jobs j JOIN client_business_units cbu ON cbu.client_id=j.client_id
             JOIN worker_business_units wbu ON wbu.business_unit_id=cbu.business_unit_id
             WHERE j.id=? AND wbu.worker_profile_id=? AND (wbu.ends_at IS NULL OR wbu.ends_at>UTC_TIMESTAMP(6)) LIMIT 1'
        );
        $stmt->execute([$jobId, $workerProfileId]);
        return (bool)$stmt->fetchColumn();
    }

    private function assignmentInScope(int $workerProfileId, int $assignmentId, string $scope): bool
    {
        if (!in_array($scope, ['own', 'assigned', 'business_unit'], true)) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM work_assignments WHERE id=? AND worker_profile_id=?');
        $stmt->execute([$assignmentId, $workerProfileId]);
        return (bool)$stmt->fetchColumn();
    }
}
