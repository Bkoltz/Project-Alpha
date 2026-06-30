<?php
// src/controllers/organization/organization_departments_options.php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';

header('Content-Type: application/json; charset=UTF-8');

$organizationId = (int)($_GET['organization_id'] ?? 0);
if ($organizationId <= 0) {
    echo json_encode([]);
    exit;
}

try {
    require_record_ownership($pdo, 'organizations', $organizationId);
    $stmt = $pdo->prepare('
        SELECT id, name, folder_name, resolver_mode
        FROM organization_departments
        WHERE organization_id = ?
        ORDER BY name ASC
    ');
    $stmt->execute([$organizationId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
}
