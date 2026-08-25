<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use RuntimeException;

/** Serializes contract creation/attachment against terminal Project changes. */
final class ProjectContractEligibilityGuardService
{
    private const TERMINAL_PROJECT_STATUSES = ['completed', 'cancelled'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Locks the destination Project first, then affected Contracts by id.
     * A null Project remains an explicitly supported unassigned state.
     *
     * @param list<int> $contractIds
     */
    public function assertCanCreateOrAttach(?int $projectId, array $contractIds = [], ?int $jobId = null): void
    {
        if ($projectId === null) {
            return;
        }
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Contract Project eligibility requires an active transaction.');
        }
        if (!self::featureEnabled($this->pdo)) {
            return;
        }

        $suffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $projectStatement = $this->pdo->prepare('SELECT id,status FROM projects WHERE id=?' . $suffix);
        $projectStatement->execute([$projectId]);
        $project = $projectStatement->fetch(PDO::FETCH_ASSOC);
        if (!$project) {
            throw new DomainException('Project not found.');
        }
        $contractIds = array_values(array_unique(array_filter(array_map('intval', $contractIds), static fn(int $id): bool => $id > 0)));
        sort($contractIds, SORT_NUMERIC);
        $prospectiveContract = $contractIds === [] && ($jobId === null || $jobId <= 0);
        $lockedIds = [];
        if (!$prospectiveContract) {
            $clauses = [];
            $parameters = [];
            if ($contractIds !== []) {
                $placeholders = implode(',', array_fill(0, count($contractIds), '?'));
                $clauses[] = "id IN ({$placeholders})";
                array_push($parameters, ...$contractIds);
            }
            if ($jobId !== null && $jobId > 0) {
                $clauses[] = 'job_id=?';
                $parameters[] = $jobId;
            }
            $statement = $this->pdo->prepare(
                'SELECT id FROM contracts WHERE (' . implode(' OR ', $clauses) . ') ORDER BY id' . $suffix
            );
            $statement->execute($parameters);
            $lockedIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        }
        if (array_diff($contractIds, $lockedIds) !== []) {
            throw new DomainException('Contract not found.');
        }
        if (
            in_array(strtolower((string)$project['status']), self::TERMINAL_PROJECT_STATUSES, true)
            && ($prospectiveContract || $lockedIds !== [])
        ) {
            throw new DomainException('Contracts cannot be created in or attached to a completed or cancelled Project.');
        }
    }

    public static function featureEnabled(PDO $pdo): bool
    {
        $statement = $pdo->prepare(
            "SELECT config_value FROM app_config
             WHERE organization_id=0 AND config_key='contract_settlement_enabled' LIMIT 1"
        );
        $statement->execute();
        return (string)$statement->fetchColumn() === '1';
    }
}
