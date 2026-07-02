<?php
// src/controllers/project/project_client_options.php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';

header('Content-Type: application/json; charset=UTF-8');

$organizationId = (int)($_GET['organization_id'] ?? 0);
$departmentId = (int)($_GET['department_id'] ?? 0);
if ($organizationId <= 0) {
    echo json_encode(['clients' => []]);
    exit;
}

try {
    require_record_ownership($pdo, 'organizations', $organizationId);
    if ($departmentId > 0) {
        $dept = $pdo->prepare('SELECT organization_id FROM organization_departments WHERE id = ? LIMIT 1');
        $dept->execute([$departmentId]);
        if ((int)($dept->fetchColumn() ?: 0) !== $organizationId) {
            throw new RuntimeException('Department not found');
        }
    }

    $join = '';
    $select = '0 AS is_department_contact, 0 AS is_primary_department_contact';
    $params = [$organizationId];
    if ($departmentId > 0) {
        $join = 'LEFT JOIN organization_department_contacts odc ON odc.client_id = c.id AND odc.department_id = ?';
        $select = 'IF(odc.client_id IS NULL, 0, 1) AS is_department_contact, IF(COALESCE(odc.is_primary, 0) = 1, 1, 0) AS is_primary_department_contact';
        $params = [$departmentId, $organizationId];
    }

    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.email, {$select}
        FROM clients c
        {$join}
        WHERE c.organization_id = ? AND c.archived = 0
        ORDER BY is_primary_department_contact DESC, is_department_contact DESC, c.name ASC
    ");
    $stmt->execute($params);
    echo json_encode(['clients' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
}
