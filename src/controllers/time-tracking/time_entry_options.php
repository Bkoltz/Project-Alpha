<?php
// src/controllers/time-tracking/time_entry_options.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';

header('Content-Type: application/json');

$clientId = (int)($_GET['client_id'] ?? 0);
if ($clientId <= 0) {
    echo json_encode(['jobs' => [], 'contracts' => [], 'invoices' => []]);
    exit;
}

$userId = (int)($_SESSION['user']['id'] ?? 0);

$clientStmt = $pdo->prepare('SELECT organization_id FROM clients WHERE id = ?');
$clientStmt->execute([$clientId]);
$clientOrgId = (int)($clientStmt->fetchColumn() ?: 0);

[$projectScope, $projectScopeParams] = scope_clause($pdo, 'p', $userId);
$jobWhere = ['p.status IN ("not_started", "active", "overdue")'];
$jobParams = [];
if ($clientOrgId > 0) {
    $jobWhere[] = '(p.client_id = ? OR p.organization_id = ?)';
    $jobParams[] = $clientId;
    $jobParams[] = $clientOrgId;
} else {
    $jobWhere[] = 'p.client_id = ?';
    $jobParams[] = $clientId;
}
if ($projectScope !== '') {
    $jobWhere[] = trim($projectScope);
    $jobParams = array_merge($jobParams, $projectScopeParams);
}
$jobsStmt = $pdo->prepare('
    SELECT p.id, p.name, p.status, p.notes, p.description
    FROM projects p
    WHERE ' . implode(' AND ', $jobWhere) . '
    ORDER BY p.name ASC
');
$jobsStmt->execute($jobParams);
$jobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);

[$contractScope, $contractScopeParams] = scope_clause($pdo, 'co', $userId);
$contractWhere = [
    'co.client_id = ?',
    'co.billing_mode = "hourly"',
    'co.status = "active"',
];
$contractParams = [$clientId];
if ($contractScope !== '') {
    $contractWhere[] = trim($contractScope);
    $contractParams = array_merge($contractParams, $contractScopeParams);
}
$contractsStmt = $pdo->prepare('
    SELECT id, doc_number, project_id, project_code, contract_type, scope, status
    FROM contracts co
    WHERE ' . implode(' AND ', $contractWhere) . '
    ORDER BY created_at DESC
');
$contractsStmt->execute($contractParams);
$contracts = $contractsStmt->fetchAll(PDO::FETCH_ASSOC);

[$invoiceScope, $invoiceScopeParams] = scope_clause($pdo, 'i', $userId);
$invoiceWhere = [
    'i.client_id = ?',
    'i.billing_mode = "hourly"',
    'i.status NOT IN ("paid", "cancelled", "void")',
];
$invoiceParams = [$clientId];
if ($invoiceScope !== '') {
    $invoiceWhere[] = trim($invoiceScope);
    $invoiceParams = array_merge($invoiceParams, $invoiceScopeParams);
}
$invoicesStmt = $pdo->prepare('
    SELECT id, doc_number, project_id, project_code, invoice_type, status, total, due_date
    FROM invoices i
    WHERE ' . implode(' AND ', $invoiceWhere) . '
    ORDER BY created_at DESC
');
$invoicesStmt->execute($invoiceParams);
$invoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'jobs' => $jobs,
    'contracts' => $contracts,
    'invoices' => $invoices,
]);
