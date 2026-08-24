<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use RuntimeException;

/**
 * Owns the locked Project status transition and, while settlement close-out is
 * enabled, prevents a terminal Project from bypassing open Contracts.
 *
 * The caller must own the transaction so schedule synchronization and portal
 * projection can commit or roll back with this decision.
 */
final class ProjectCloseGuardService
{
    private const PROJECT_STATUSES = ['not_started','active','overdue','completed','cancelled'];
    private const TERMINAL_PROJECT_STATUSES = ['completed','cancelled'];
    private const TERMINAL_CONTRACT_STATUSES = ['completed','cancelled','denied','void'];

    private readonly \Closure $authorizer;
    private readonly \Closure $auditor;

    public function __construct(
        private readonly PDO $pdo,
        ?callable $authorizer = null,
        ?callable $auditor = null,
    ) {
        $this->authorizer = $authorizer
            ? \Closure::fromCallable($authorizer)
            : static function (PDO $pdo, int $projectId): void {
                if (!function_exists('require_record_ownership')) {
                    throw new RuntimeException('Project ownership enforcement is unavailable.');
                }
                \require_record_ownership($pdo, 'projects', $projectId);
            };
        $this->auditor = $auditor
            ? \Closure::fromCallable($auditor)
            : static function (PDO $pdo, array $project, string $action, array $details, int $actorId): void {
                $pdo->prepare(
                    'INSERT INTO system_audit
                     (user_id,organization_id,action,entity_type,entity_id,details,ip_address,user_agent)
                     VALUES (?,?,?,?,?,?,NULL,NULL)'
                )->execute([
                    $actorId > 0 ? $actorId : null,
                    !empty($project['organization_id']) ? (int)$project['organization_id'] : null,
                    $action,
                    'project',
                    (int)$project['id'],
                    json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]);
            };
    }

    /**
     * @return array{transitioned:bool,project:array<string,mixed>,blockers:list<array{id:int,doc_number:mixed,status:string,contract_type:string}>}
     */
    public function transition(int $projectId, string $targetStatus, int $actorId): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Project status transitions require an active transaction.');
        }
        if ($projectId < 1 || !in_array($targetStatus, self::PROJECT_STATUSES, true)) {
            throw new DomainException('Invalid project status transition.');
        }

        $suffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $projectStatement = $this->pdo->prepare('SELECT * FROM projects WHERE id=?' . $suffix);
        $projectStatement->execute([$projectId]);
        $project = $projectStatement->fetch(PDO::FETCH_ASSOC);
        if (!$project) {
            throw new DomainException('Project not found.');
        }
        ($this->authorizer)($this->pdo, $projectId);

        $terminal = in_array($targetStatus, self::TERMINAL_PROJECT_STATUSES, true);
        $closeoutFeatureEnabled = $terminal
            ? ProjectContractEligibilityGuardService::featureEnabled($this->pdo)
            : false;
        $blockers = [];
        if ($closeoutFeatureEnabled) {
            $contractStatement = $this->pdo->prepare(
                'SELECT id,doc_number,status,contract_type FROM contracts WHERE project_id=? ORDER BY id' . $suffix
            );
            $contractStatement->execute([$projectId]);
            foreach ($contractStatement->fetchAll(PDO::FETCH_ASSOC) as $contract) {
                $status = strtolower((string)$contract['status']);
                if (in_array($status, self::TERMINAL_CONTRACT_STATUSES, true)) {
                    continue;
                }
                $blockers[] = [
                    'id' => (int)$contract['id'],
                    'doc_number' => $contract['doc_number'],
                    'status' => $status,
                    'contract_type' => (string)($contract['contract_type'] ?? 'regular'),
                ];
            }
        }
        if ($blockers !== []) {
            ($this->auditor)(
                $this->pdo,
                $project,
                'project.closeout.blocked',
                [
                    'target_status' => $targetStatus,
                    'blockers' => array_map(
                        static fn(array $blocker): array => [
                            'contract_id' => $blocker['id'],
                            'status' => $blocker['status'],
                        ],
                        $blockers
                    ),
                ],
                $actorId
            );
            return ['transitioned' => false, 'project' => $project, 'blockers' => $blockers];
        }

        $receivables = $terminal
            ? (new ProjectReceivablesSummaryService($this->pdo))->summarize($projectId)
            : null;
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $nowSql = $mysql ? 'UTC_TIMESTAMP(6)' : 'CURRENT_TIMESTAMP';
        $this->pdo->prepare(
            "UPDATE projects
             SET completed_at=CASE
                   WHEN ?='completed' AND status<>'completed' THEN {$nowSql}
                   WHEN ?<>'completed' THEN NULL
                   ELSE completed_at
                 END,
                 status=?,source_version=?,updated_at={$nowSql}
             WHERE id=?"
        )->execute([$targetStatus, $targetStatus, $targetStatus, 'v-' . bin2hex(random_bytes(16)), $projectId]);

        $auditDetails = [
            'status' => $targetStatus,
            'completed_at_authoritative' => $targetStatus === 'completed',
            'contract_closeout_guarded' => $closeoutFeatureEnabled,
        ];
        if ($receivables !== null) {
            $auditDetails['closed_with_outstanding_receivables'] = $receivables['has_outstanding'];
            $auditDetails['outstanding_receivables_minor'] = $receivables['total_minor'];
            $auditDetails['outstanding_receivables_sources'] = $receivables['sources'];
        }
        ($this->auditor)($this->pdo, $project, 'project.status.changed', $auditDetails, $actorId);
        $project['status'] = $targetStatus;
        return ['transitioned' => true, 'project' => $project, 'blockers' => []];
    }

}
