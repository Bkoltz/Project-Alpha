<?php
// src/controllers/organization/org_search.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/organization_schema.php';

header('Content-Type: application/json');
$term = isset($_GET['term']) ? trim((string)$_GET['term']) : '';
if ($term === '') {
    echo json_encode([]);
    exit;
}

try {
    $like = '%' . str_replace('%','\\%',$term) . '%';
    $params = [$like];
    $where = ['name LIKE ?'];
    if (($_SESSION['user']['role'] ?? '') !== 'admin') {
        $orgIds = user_org_ids($pdo, (int)($_SESSION['user']['id'] ?? 0));
        if (!$orgIds) {
            echo json_encode([]);
            exit;
        }
        $where[] = 'id IN (' . implode(',', array_fill(0, count($orgIds), '?')) . ')';
        $params = array_merge($params, $orgIds);
    }
    $addressSelect = pa_organization_address_select($pdo);
    $st = $pdo->prepare('SELECT id, name, ' . $addressSelect . ' FROM organizations WHERE ' . implode(' AND ', $where) . ' ORDER BY name ASC LIMIT 15');
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(array_values($rows));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([]);
}
