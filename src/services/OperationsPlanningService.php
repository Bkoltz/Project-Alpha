<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Throwable;

final class OperationsPlanningService
{
    public const OPERATION_STATUSES = ['draft', 'scheduled', 'in_progress', 'completed', 'cancelled'];
    public const TASK_STATUSES = ['todo', 'in_progress', 'blocked', 'completed', 'cancelled'];

    /** @param list<int> $assignedUserIds */
    public function saveOperation(PDO $pdo, array $input, array $assignedUserIds, int $actorUserId): int
    {
        $id = max(0, (int)($input['id'] ?? 0));
        $projectId = (int)($input['project_id'] ?? 0);
        $businessUnitId = (int)($input['business_unit_id'] ?? 0);
        $title = trim((string)($input['title'] ?? ''));
        $status = (string)($input['status'] ?? 'draft');
        $startAt = $this->dateTimeOrNull((string)($input['scheduled_start_at'] ?? ''));
        $endAt = $this->dateTimeOrNull((string)($input['scheduled_end_at'] ?? ''));
        $location = trim((string)($input['location'] ?? ''));
        $notes = trim((string)($input['notes'] ?? ''));
        $assignedUserIds = array_values(array_unique(array_filter(array_map('intval', $assignedUserIds), static fn(int $value): bool => $value > 0)));

        if ($projectId < 1 || $title === '') {
            throw new DomainException('Project and operation title are required.');
        }
        if (!in_array($status, self::OPERATION_STATUSES, true)) {
            throw new DomainException('Invalid operation status.');
        }
        if ($startAt !== null && $endAt !== null && $endAt < $startAt) {
            throw new DomainException('Operation end time cannot be before its start time.');
        }

        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            $this->requireProjectAndUnit($pdo, $projectId, $businessUnitId);
            $this->requireUsers($pdo, $assignedUserIds);

            if ($id > 0) {
                $statement = $pdo->prepare(
                    'UPDATE operations SET project_id=?,business_unit_id=?,title=?,status=?,scheduled_start_at=?,scheduled_end_at=?,location=?,notes=? WHERE id=?'
                );
                $statement->execute([$projectId, $businessUnitId ?: null, $title, $status, $startAt, $endAt, $location ?: null, $notes ?: null, $id]);
                if ($statement->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT 1 FROM operations WHERE id=?');
                    $exists->execute([$id]);
                    if (!$exists->fetchColumn()) {
                        throw new DomainException('Operation not found.');
                    }
                }
            } else {
                $pdo->prepare(
                    'INSERT INTO operations (project_id,business_unit_id,title,status,scheduled_start_at,scheduled_end_at,location,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([$projectId, $businessUnitId ?: null, $title, $status, $startAt, $endAt, $location ?: null, $notes ?: null, $actorUserId ?: null]);
                $id = (int)$pdo->lastInsertId();
            }

            $pdo->prepare('DELETE FROM operation_assignments WHERE operation_id=?')->execute([$id]);
            $assignment = $pdo->prepare('INSERT INTO operation_assignments (operation_id,user_id,assigned_by) VALUES (?,?,?)');
            foreach ($assignedUserIds as $userId) {
                $assignment->execute([$id, $userId, $actorUserId ?: null]);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $id;
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function saveTask(PDO $pdo, array $input, int $actorUserId): int
    {
        $id = max(0, (int)($input['id'] ?? 0));
        $operationId = (int)($input['operation_id'] ?? 0);
        $projectId = (int)($input['project_id'] ?? 0);
        $businessUnitId = (int)($input['business_unit_id'] ?? 0);
        $assigneeUserId = (int)($input['assignee_user_id'] ?? 0);
        $title = trim((string)($input['title'] ?? ''));
        $status = (string)($input['status'] ?? 'todo');
        $dueAt = $this->dateTimeOrNull((string)($input['due_at'] ?? ''));
        $notes = trim((string)($input['notes'] ?? ''));

        if ($projectId < 1 || $title === '') {
            throw new DomainException('Project and task title are required.');
        }
        if (!in_array($status, self::TASK_STATUSES, true)) {
            throw new DomainException('Invalid task status.');
        }

        $this->requireProjectAndUnit($pdo, $projectId, $businessUnitId);
        $this->requireUsers($pdo, $assigneeUserId > 0 ? [$assigneeUserId] : []);
        if ($operationId > 0) {
            $operation = $pdo->prepare('SELECT project_id FROM operations WHERE id=?');
            $operation->execute([$operationId]);
            $operationProjectId = (int)($operation->fetchColumn() ?: 0);
            if ($operationProjectId !== $projectId) {
                throw new DomainException('The selected operation does not belong to this project.');
            }
        }

        if ($id > 0) {
            $statement = $pdo->prepare(
                'UPDATE tasks SET operation_id=?,project_id=?,business_unit_id=?,assignee_user_id=?,title=?,status=?,due_at=?,notes=? WHERE id=?'
            );
            $statement->execute([$operationId ?: null, $projectId, $businessUnitId ?: null, $assigneeUserId ?: null, $title, $status, $dueAt, $notes ?: null, $id]);
            if ($statement->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT 1 FROM tasks WHERE id=?');
                $exists->execute([$id]);
                if (!$exists->fetchColumn()) {
                    throw new DomainException('Task not found.');
                }
            }
            return $id;
        }

        $pdo->prepare(
            'INSERT INTO tasks (operation_id,project_id,business_unit_id,assignee_user_id,title,status,due_at,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$operationId ?: null, $projectId, $businessUnitId ?: null, $assigneeUserId ?: null, $title, $status, $dueAt, $notes ?: null, $actorUserId ?: null]);
        return (int)$pdo->lastInsertId();
    }

    private function requireProjectAndUnit(PDO $pdo, int $projectId, int $businessUnitId): void
    {
        $project = $pdo->prepare("SELECT 1 FROM projects WHERE id=? AND status NOT IN ('cancelled')");
        $project->execute([$projectId]);
        if (!$project->fetchColumn()) {
            throw new DomainException('Project is unavailable.');
        }
        if ($businessUnitId > 0) {
            $unit = $pdo->prepare('SELECT 1 FROM business_units WHERE id=? AND is_active=1');
            $unit->execute([$businessUnitId]);
            if (!$unit->fetchColumn()) {
                throw new DomainException('Business unit is unavailable.');
            }
        }
    }

    /** @param list<int> $userIds */
    private function requireUsers(PDO $pdo, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $statement = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders) AND is_disabled=0 AND deleted_at IS NULL");
        $statement->execute($userIds);
        $valid = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        sort($valid);
        sort($userIds);
        if ($valid !== $userIds) {
            throw new DomainException('One or more assigned users are unavailable.');
        }
    }

    private function dateTimeOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = new DateTimeImmutable($value, new DateTimeZone(date_default_timezone_get()));
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
