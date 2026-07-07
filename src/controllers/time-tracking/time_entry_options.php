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
$projectJobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);

[$quoteScope, $quoteScopeParams] = scope_clause($pdo, 'q', $userId);
[$docContractScope, $docContractScopeParams] = scope_clause($pdo, 'co', $userId);
[$docInvoiceScope, $docInvoiceScopeParams] = scope_clause($pdo, 'i', $userId);
$documentJobSql = '
    SELECT project_code, MIN(project_id) AS project_id
    FROM (
        SELECT q.project_code, q.project_id
        FROM quotes q
        WHERE q.client_id = ?
          AND q.billing_mode = "hourly"
          AND q.status IN ("pending", "approved")
          AND q.project_code IS NOT NULL
          AND q.project_code != ""
          ' . ($quoteScope !== '' ? ' AND ' . trim($quoteScope) : '') . '
        UNION ALL
        SELECT co.project_code, co.project_id
        FROM contracts co
        WHERE co.client_id = ?
          AND co.billing_mode = "hourly"
          AND co.status = "active"
          AND co.project_code IS NOT NULL
          AND co.project_code != ""
          ' . ($docContractScope !== '' ? ' AND ' . trim($docContractScope) : '') . '
        UNION ALL
        SELECT i.project_code, i.project_id
        FROM invoices i
        WHERE i.client_id = ?
          AND i.billing_mode = "hourly"
          AND i.status NOT IN ("paid", "cancelled", "void")
          AND i.project_code IS NOT NULL
          AND i.project_code != ""
          ' . ($docInvoiceScope !== '' ? ' AND ' . trim($docInvoiceScope) : '') . '
    ) document_jobs
    GROUP BY project_code
    ORDER BY project_code ASC
';
$documentJobParams = array_merge(
    [$clientId],
    $quoteScope !== '' ? $quoteScopeParams : [],
    [$clientId],
    $docContractScope !== '' ? $docContractScopeParams : [],
    [$clientId],
    $docInvoiceScope !== '' ? $docInvoiceScopeParams : []
);
$documentJobsStmt = $pdo->prepare($documentJobSql);
$documentJobsStmt->execute($documentJobParams);
$documentJobs = $documentJobsStmt->fetchAll(PDO::FETCH_ASSOC);

$jobsByKey = [];
foreach ($projectJobs as $job) {
    $key = 'project:' . (int)$job['id'];
    $jobsByKey[$key] = [
        'id' => (int)$job['id'],
        'name' => (string)$job['name'],
        'project_code' => '',
        'status' => (string)$job['status'],
        'notes' => $job['notes'] ?? null,
        'description' => $job['description'] ?? null,
    ];
}
foreach ($documentJobs as $job) {
    $code = (string)($job['project_code'] ?? '');
    if ($code === '') {
        continue;
    }
    foreach ($jobsByKey as &$existingJob) {
        if (($existingJob['project_code'] ?? '') === '' && ($existingJob['name'] ?? '') === $code) {
            $existingJob['project_code'] = $code;
            unset($existingJob);
            continue 2;
        }
    }
    unset($existingJob);
    $key = 'code:' . $code;
    if (!isset($jobsByKey[$key])) {
        $jobsByKey[$key] = [
            'id' => (int)($job['project_id'] ?? 0) ?: null,
            'name' => $code,
            'project_code' => $code,
            'status' => 'active',
            'notes' => null,
            'description' => null,
        ];
    }
}
$jobs = array_values($jobsByKey);

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
    SELECT id, doc_number, contract_id, project_id, project_code, invoice_type, status, total, due_date
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
