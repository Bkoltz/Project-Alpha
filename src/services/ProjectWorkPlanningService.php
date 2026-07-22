<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;

final class ProjectWorkPlanningService
{
    public function addTeamMember(PDO $pdo, int $projectId, int $userId, int $actorUserId): int
    {
        $this->requireProject($pdo, $projectId);
        $this->requireActiveUser($pdo, $userId);
        $existing = $pdo->prepare('SELECT id FROM project_assignments WHERE project_id=? AND user_id=? LIMIT 1');
        $existing->execute([$projectId, $userId]);
        $id = (int)($existing->fetchColumn() ?: 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE project_assignments SET ends_at=NULL,assigned_at=CURRENT_TIMESTAMP,created_by=? WHERE id=?')
                ->execute([$actorUserId ?: null, $id]);
            return $id;
        }
        $pdo->prepare('INSERT INTO project_assignments (project_id,user_id,created_by) VALUES (?,?,?)')
            ->execute([$projectId, $userId, $actorUserId ?: null]);
        return (int)$pdo->lastInsertId();
    }

    public function endTeamMember(PDO $pdo, int $projectId, int $userId): void
    {
        $openOperation = $pdo->prepare(
            "SELECT 1 FROM operation_assignments oa JOIN operations o ON o.id=oa.operation_id
             WHERE o.project_id=? AND oa.user_id=? AND o.status NOT IN ('completed','cancelled') LIMIT 1"
        );
        $openOperation->execute([$projectId, $userId]);
        $openTask = $pdo->prepare(
            "SELECT 1 FROM task_assignments ta JOIN tasks t ON t.id=ta.task_id
             WHERE t.project_id=? AND ta.user_id=? AND t.status NOT IN ('completed','cancelled') LIMIT 1"
        );
        $openTask->execute([$projectId, $userId]);
        if ($openOperation->fetchColumn() || $openTask->fetchColumn()) {
            throw new DomainException('Reassign or complete this worker\'s open Operations and Tasks before removing them from the Project Team.');
        }
        $pdo->prepare('UPDATE project_assignments SET ends_at=CURRENT_TIMESTAMP WHERE project_id=? AND user_id=? AND (ends_at IS NULL OR ends_at>CURRENT_TIMESTAMP)')
            ->execute([$projectId, $userId]);
    }

    public function setProjectBusinessUnit(PDO $pdo, int $projectId, int $businessUnitId): void
    {
        $this->requireProject($pdo, $projectId);
        if ($businessUnitId > 0) {
            $unit = $pdo->prepare('SELECT 1 FROM business_units WHERE id=? AND is_active=1');
            $unit->execute([$businessUnitId]);
            if (!$unit->fetchColumn()) {
                throw new DomainException('Business unit is unavailable.');
            }
        }
        $pdo->prepare('UPDATE projects SET business_unit_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$businessUnitId ?: null, $projectId]);
        $pdo->prepare('UPDATE operations SET business_unit_id=? WHERE project_id=?')->execute([$businessUnitId ?: null, $projectId]);
        $pdo->prepare('UPDATE tasks SET business_unit_id=? WHERE project_id=?')->execute([$businessUnitId ?: null, $projectId]);
    }

    private function requireProject(PDO $pdo, int $projectId): void
    {
        $statement = $pdo->prepare("SELECT 1 FROM projects WHERE id=? AND status<>'cancelled'");
        $statement->execute([$projectId]);
        if (!$statement->fetchColumn()) {
            throw new DomainException('Project is unavailable.');
        }
    }

    private function requireActiveUser(PDO $pdo, int $userId): void
    {
        $statement = $pdo->prepare('SELECT 1 FROM users WHERE id=? AND is_disabled=0 AND deleted_at IS NULL');
        $statement->execute([$userId]);
        if (!$statement->fetchColumn()) {
            throw new DomainException('User is unavailable.');
        }
    }
}
