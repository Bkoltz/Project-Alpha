<?php

final class ScheduleService
{
    public static function syncProject(PDO $pdo, int $projectId, string $timezone = 'UTC', ?int $userId = null): void
    {
        $statement = $pdo->prepare('SELECT id,name,estimated_start,estimated_end,status FROM projects WHERE id=?');
        $statement->execute([$projectId]);
        $project = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$project) return;
        $location = $pdo->prepare('SELECT service_location_id FROM project_service_locations WHERE project_id=? ORDER BY is_default DESC,id LIMIT 1');
        $location->execute([$projectId]);
        self::upsert($pdo, 'project', $projectId, $projectId, null, (int)($location->fetchColumn() ?: 0) ?: null,
            (string)$project['name'], $project['estimated_start'] ?: null, $project['estimated_end'] ?: null,
            self::status((string)($project['status'] ?? '')), $timezone, $userId);
    }

    public static function syncJob(PDO $pdo, int $jobId, string $timezone = 'UTC', ?int $userId = null): void
    {
        $statement = $pdo->prepare(
            'SELECT j.id,j.job_code,j.project_id,j.default_service_location_id,j.status,
                    MIN(d.starts_at) starts_at,MAX(d.ends_at) ends_at
             FROM jobs j LEFT JOIN (
               SELECT job_id,COALESCE(start_date,fulfillment_date) starts_at,COALESCE(end_date,fulfillment_date) ends_at FROM quotes
               UNION ALL SELECT job_id,COALESCE(start_date,fulfillment_date),COALESCE(end_date,fulfillment_date) FROM contracts
               UNION ALL SELECT job_id,fulfillment_date,fulfillment_date FROM invoices
             ) d ON d.job_id=j.id WHERE j.id=? GROUP BY j.id'
        );
        $statement->execute([$jobId]);
        $job = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$job) return;
        self::upsert($pdo, 'job', $jobId, !empty($job['project_id']) ? (int)$job['project_id'] : null, $jobId,
            !empty($job['default_service_location_id']) ? (int)$job['default_service_location_id'] : null,
            'Job ' . (string)$job['job_code'], $job['starts_at'] ?: null, $job['ends_at'] ?: null,
            self::status((string)$job['status']), $timezone, $userId);
    }

    private static function upsert(PDO $pdo, string $sourceType, int $sourceId, ?int $projectId, ?int $jobId, ?int $locationId,
        string $title, ?string $startsAt, ?string $endsAt, string $status, string $timezone, ?int $userId): void
    {
        $existing = $pdo->prepare('SELECT id FROM schedule_entries WHERE source_type=? AND source_id=? LIMIT 1');
        $existing->execute([$sourceType,$sourceId]);
        $id = (int)$existing->fetchColumn();
        if ($id > 0) {
            $pdo->prepare('UPDATE schedule_entries SET project_id=?,job_id=?,service_location_id=?,title=?,starts_at=?,ends_at=?,timezone=?,status=? WHERE id=?')
                ->execute([$projectId,$jobId,$locationId,$title,$startsAt,$endsAt,$timezone,$status,$id]);
        } else {
            $pdo->prepare('INSERT INTO schedule_entries (project_id,job_id,service_location_id,title,starts_at,ends_at,timezone,status,source_type,source_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$projectId,$jobId,$locationId,$title,$startsAt,$endsAt,$timezone,$status,$sourceType,$sourceId,$userId]);
        }
    }

    private static function status(string $status): string
    {
        return match (strtolower($status)) {
            'active', 'in_progress' => 'confirmed',
            'completed', 'complete' => 'completed',
            'cancelled', 'canceled' => 'cancelled',
            default => 'planned',
        };
    }
}
