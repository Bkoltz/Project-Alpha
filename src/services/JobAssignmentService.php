<?php

declare(strict_types=1);

final class JobAssignmentService
{
    public static function ensureForCode(PDO $pdo, int $clientId, string $jobCode, ?int $projectId = null, ?int $userId = null): int
    {
        $jobCode = trim($jobCode);
        if ($clientId <= 0 || $jobCode === '') throw new InvalidArgumentException('Client and job code are required.');
        $context = $pdo->prepare('SELECT organization_id FROM clients WHERE id=?');
        $context->execute([$clientId]);
        $organizationId = (int)($context->fetchColumn() ?: 0);
        $pdo->prepare('INSERT INTO jobs (client_id,organization_id,project_id,job_code,created_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE organization_id=COALESCE(jobs.organization_id,VALUES(organization_id))')
            ->execute([$clientId, $organizationId ?: null, $projectId, $jobCode, $userId ?: null]);
        $find = $pdo->prepare('SELECT id FROM jobs WHERE client_id=? AND job_code=?');
        $find->execute([$clientId, $jobCode]);
        $jobId = (int)$find->fetchColumn();
        if ($projectId !== null) {
            $current = $pdo->prepare('SELECT project_id FROM jobs WHERE id=?');
            $current->execute([$jobId]);
            $currentProjectId = (int)($current->fetchColumn() ?: 0);
            if ($currentProjectId > 0 && $currentProjectId !== $projectId) {
                throw new RuntimeException('This Job is already assigned to a different Project.', 409);
            }
            if ($currentProjectId === 0) self::assignProject($pdo, $jobId, $projectId);
        }
        require_once __DIR__ . '/ScheduleService.php';
        ScheduleService::syncJob($pdo, $jobId, (string)($GLOBALS['appConfig']['timezone'] ?? 'UTC'), $userId);
        return $jobId;
    }

    public static function assignProject(PDO $pdo, int $jobId, ?int $projectId): void
    {
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $jobStmt = $pdo->prepare('SELECT * FROM jobs WHERE id=? FOR UPDATE');
            $jobStmt->execute([$jobId]);
            $job = $jobStmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) throw new RuntimeException('Job not found.');
            if ($projectId !== null) {
                $project = $pdo->prepare(
                    'SELECT id FROM projects WHERE id=? AND (
                        client_id=? OR (client_id IS NULL AND organization_id IS NOT NULL AND organization_id <=> ?)
                    )'
                );
                $project->execute([$projectId, (int)$job['client_id'], $job['organization_id'] ?? null]);
                if (!$project->fetchColumn()) throw new RuntimeException('Project does not belong to this job client.');
            }
            $pdo->prepare('UPDATE jobs SET project_id=? WHERE id=?')->execute([$projectId, $jobId]);
            foreach (['quote' => 'quotes', 'contract' => 'contracts', 'invoice' => 'invoices'] as $type => $table) {
                $ids = $pdo->prepare("SELECT id FROM {$table} WHERE job_id=?");
                $ids->execute([$jobId]);
                $documentIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
                $pdo->prepare("UPDATE {$table} SET project_id=? WHERE job_id=?")->execute([$projectId, $jobId]);
                foreach ($documentIds as $documentId) {
                    $pdo->prepare('DELETE FROM project_documents WHERE document_type=? AND document_id=?')->execute([$type, $documentId]);
                    if ($projectId !== null) {
                        $pdo->prepare('INSERT INTO project_documents (project_id,document_type,document_id) VALUES (?,?,?)')->execute([$projectId, $type, $documentId]);
                    }
                }
            }
            require_once __DIR__ . '/ScheduleService.php';
            ScheduleService::syncJob($pdo, $jobId, (string)($GLOBALS['appConfig']['timezone'] ?? 'UTC'), (int)($_SESSION['user']['id'] ?? 0) ?: null);
            if ($ownTransaction) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
