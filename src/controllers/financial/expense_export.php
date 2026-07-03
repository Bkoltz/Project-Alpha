<?php
// src/controllers/financial/expense_export.php
// Export filtered expenses as CSV

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/csv.php';

$userId = $_SESSION['user']['id'] ?? null;
if (!$userId) {
    header('Location: /?page=login');
    exit;
}

$orgId = active_or_default_org_id($pdo);
if ($orgId <= 0 || !user_can($pdo, (int)$userId, 'financial.manage', $orgId)) {
    http_response_code(403);
    exit('Permission denied');
}

// Filters from query params
$start = $_GET['start'] ?? date('Y') . '-01-01';
$end = $_GET['end'] ?? date('Y') . '-12-31';
$categoryId = (int)($_GET['category_id'] ?? 0);
$vendorId = (int)($_GET['vendor_id'] ?? 0);
$clientId = (int)($_GET['client_id'] ?? 0);
$billable = $_GET['billable'] ?? '';
$taxDeductible = $_GET['tax_deductible'] ?? '';
$status = $_GET['status'] ?? '';

[$expenseScopeWhere, $expenseScopeParams] = finance_scope_clause($pdo, 'e', (int)$userId, $orgId, 'created_by');
$where = [$expenseScopeWhere];
$params = $expenseScopeParams;

if ($start) { $where[] = 'e.expense_date >= ?'; $params[] = $start; }
if ($end) { $where[] = 'e.expense_date <= ?'; $params[] = $end; }
if ($categoryId > 0) { $where[] = 'e.category_id = ?'; $params[] = $categoryId; }
if ($vendorId > 0) { $where[] = 'e.vendor_id = ?'; $params[] = $vendorId; }
if ($clientId > 0) { $where[] = 'e.client_id = ?'; $params[] = $clientId; }
if ($billable === '1') { $where[] = 'e.is_billable = 1'; }
if ($billable === '0') { $where[] = 'e.is_billable = 0'; }
if ($taxDeductible === '1') { $where[] = 'e.is_tax_deductible = 1'; }
if ($taxDeductible === '0') { $where[] = 'e.is_tax_deductible = 0'; }
if ($status) { $where[] = 'e.status = ?'; $params[] = $status; }

$whereSQL = implode(' AND ', $where);

$sql = "
    SELECT e.*, v.name as vendor_name, ec.name as category_name, c.name as client_name, p.name as project_name
    FROM expenses e
    LEFT JOIN vendors v ON v.id = e.vendor_id
    LEFT JOIN expense_categories ec ON ec.id = e.category_id
    LEFT JOIN clients c ON c.id = e.client_id
    LEFT JOIN projects p ON p.id = e.project_id
    WHERE {$whereSQL}
    ORDER BY e.expense_date DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Output CSV
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="expenses-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');

// Header row
csv_write_row($out, ['Date', 'Vendor', 'Description', 'Category', 'Amount', 'Total', 'Reference', 'Billable', 'Client', 'Project', 'Tax-Deductible', 'Reimbursed', 'Reconciled', 'Status', 'Notes']);

foreach ($expenses as $e) {
    csv_write_row($out, [
        $e['expense_date'],
        $e['vendor_name'] ?? '',
        $e['description'] ?? '',
        $e['category_name'] ?? '',
        number_format((float)$e['amount'], 2, '.', ''),
        $e['total_amount'] ? number_format((float)$e['total_amount'], 2, '.', '') : number_format((float)$e['amount'], 2, '.', ''),
        $e['reference_number'] ?? '',
        $e['is_billable'] ? 'Yes' : 'No',
        $e['client_name'] ?? '',
        $e['project_name'] ?? '',
        $e['is_tax_deductible'] ? 'Yes' : 'No',
        $e['is_reimbursed'] ? 'Yes' : 'No',
        $e['is_reconciled'] ? 'Yes' : 'No',
        $e['status'],
        $e['notes'] ?? '',
    ]);
}

fclose($out);
