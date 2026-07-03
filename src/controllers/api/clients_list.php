<?php
// src/controllers/api/clients_list.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
header('Content-Type: application/json');

function api_clients_table_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
             LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

$limit = (int)($_GET['limit'] ?? 25);
if ($limit < 1 || $limit > 100) $limit = 25;
$archived = (int)($_GET['archived'] ?? 0);

$hasClientName = api_clients_table_has_column($pdo, 'clients', 'name');
$hasClientEmail = api_clients_table_has_column($pdo, 'clients', 'email');
$hasClientOrg = api_clients_table_has_column($pdo, 'clients', 'organization_id');
$hasArchived = api_clients_table_has_column($pdo, 'clients', 'archived');
$hasOrgName = api_clients_table_has_column($pdo, 'organizations', 'name');

$select = [
    'c.id',
    $hasClientName ? 'c.name' : 'CONCAT("Client #", c.id) AS name',
    $hasClientEmail ? 'c.email' : 'NULL AS email',
    $hasClientOrg ? 'c.organization_id' : 'NULL AS organization_id',
    ($hasClientOrg && $hasOrgName) ? 'o.name AS organization_name' : 'NULL AS organization_name',
];

$sql = 'SELECT ' . implode(', ', $select) . ' FROM clients c';
if ($hasClientOrg && $hasOrgName) {
    $sql .= ' LEFT JOIN organizations o ON c.organization_id = o.id';
}
$params = [];
if ($hasArchived) {
    $sql .= ' WHERE c.archived = ?';
    $params[] = $archived;
}
$sql .= $hasClientName ? ' ORDER BY c.name' : ' ORDER BY c.id';
$sql .= ' LIMIT ?';

try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $idx => $value) {
        $stmt->bindValue($idx + 1, $value, PDO::PARAM_INT);
    }
    $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_NUMERIC_CHECK);
} catch (Throwable $e) {
    http_response_code(500);
    @error_log('[api/clients_list] Failed to list clients: ' . $e->getMessage());
    echo json_encode(['error' => 'Failed to load clients']);
}
