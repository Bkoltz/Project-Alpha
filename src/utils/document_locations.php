<?php

declare(strict_types=1);

/**
 * Resolve a document's service location. An explicit selection wins, followed
 * by the Job default and then the Project default. The selected location must
 * belong to the client or be assigned to the Project.
 */
function document_resolve_service_location(
    PDO $pdo,
    int $clientId,
    ?int $projectId,
    ?int $jobId,
    ?int $requestedLocationId
): ?int {
    if ($requestedLocationId !== null && $requestedLocationId > 0) {
        $allowed = $pdo->prepare(
            'SELECT s.id
             FROM service_locations s
             WHERE s.id=? AND s.archived=0 AND (
                s.client_id=? OR s.project_id=? OR
                EXISTS (SELECT 1 FROM project_service_locations psl WHERE psl.project_id=? AND psl.service_location_id=s.id)
             ) LIMIT 1'
        );
        $allowed->execute([$requestedLocationId, $clientId, $projectId, $projectId]);
        if (!$allowed->fetchColumn()) {
            throw new InvalidArgumentException('Choose a service location assigned to this client or Project.');
        }
        return $requestedLocationId;
    }

    if ($jobId !== null && $jobId > 0) {
        $job = $pdo->prepare('SELECT default_service_location_id FROM jobs WHERE id=? AND client_id=?');
        $job->execute([$jobId, $clientId]);
        $locationId = (int)($job->fetchColumn() ?: 0);
        if ($locationId > 0) return $locationId;
    }

    if ($projectId !== null && $projectId > 0) {
        $project = $pdo->prepare(
            'SELECT service_location_id FROM project_service_locations
             WHERE project_id=? ORDER BY is_default DESC,id LIMIT 1'
        );
        $project->execute([$projectId]);
        $locationId = (int)($project->fetchColumn() ?: 0);
        if ($locationId > 0) return $locationId;

        $legacy = $pdo->prepare('SELECT id FROM service_locations WHERE project_id=? AND archived=0 ORDER BY id LIMIT 1');
        $legacy->execute([$projectId]);
        $locationId = (int)($legacy->fetchColumn() ?: 0);
        if ($locationId > 0) return $locationId;
    }

    return null;
}

function document_service_location_options(PDO $pdo): array
{
    return $pdo->query(
        'SELECT s.id,s.name,s.client_id,s.project_id,s.address_line1,s.city,s.state,
                c.name client_name,p.name project_name
         FROM service_locations s
         LEFT JOIN clients c ON c.id=s.client_id
         LEFT JOIN projects p ON p.id=s.project_id
         WHERE s.archived=0 ORDER BY s.name,s.id'
    )->fetchAll(PDO::FETCH_ASSOC);
}
