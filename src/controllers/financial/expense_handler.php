<?php
// src/controllers/financial/expense_handler.php
// POST controller for expense CRUD: create, update, delete, mark_reimbursed, mark_reconciled

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';

function expense_handler_is_ajax(): bool
{
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return $requestedWith === 'xmlhttprequest' || ($_GET['ajax'] ?? '') === '1' || ($_POST['ajax'] ?? '') === '1';
}

function expense_handler_redirect_with_message(string $url, string $key, string $message): void
{
    $join = str_contains($url, '?') ? '&' : '?';
    header('Location: ' . $url . $join . $key . '=' . urlencode($message));
    exit;
}

function expense_handler_finish(array $response, int $status = 200, string $fallback = '/?page=financial/expenses-list&tab=expenses'): void
{
    if (expense_handler_is_ajax()) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    if (!empty($response['success'])) {
        header('Location: ' . (string)($response['redirect'] ?? '/?page=financial/expenses-list&tab=expenses'));
        exit;
    }

    expense_handler_redirect_with_message($fallback, 'error', (string)($response['error'] ?? 'Expense request failed'));
}

function expense_handler_assert_org_row(PDO $pdo, string $table, int $id, int $orgId, string $label, ?string $ownerColumn = null, int $userId = 0): void
{
    if ($id <= 0) {
        return;
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        throw new RuntimeException('Invalid table.');
    }

    $where = 'id = ?';
    $params = [$id];
    if ($orgId > 0) {
        $where .= ' AND organization_id = ?';
        $params[] = $orgId;
    }
    if ($ownerColumn !== null && ($_SESSION['user']['role'] ?? '') !== 'admin') {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $ownerColumn)) {
            throw new RuntimeException('Invalid owner column.');
        }
        $where .= " AND {$ownerColumn} = ?";
        $params[] = $userId;
    }

    $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$where} LIMIT 1");
    $stmt->execute($params);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException($label . ' was not found.');
    }
}

function expense_handler_assert_client(PDO $pdo, int $clientId, int $orgId): void
{
    if ($clientId <= 0) {
        return;
    }
    $where = 'id = ?';
    $params = [$clientId];
    if ($orgId > 0) {
        $where .= ' AND organization_id = ?';
        $params[] = $orgId;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM clients WHERE {$where} LIMIT 1");
    $stmt->execute($params);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Client was not found.');
    }
}

$userId = $_SESSION['user']['id'] ?? null;
if (!$userId) {
    expense_handler_finish(['success' => false, 'error' => 'Not authenticated'], 401, '/?page=login');
}

$csrfOk = false;
$submitted = $_POST['_token'] ?? '';
if (is_string($submitted) && $submitted !== '') {
    $csrfOk = csrf_sf_is_valid('expense', $submitted);
} else {
    $csrfOk = csrf_validate();
}
if (!$csrfOk) {
    expense_handler_finish(['success' => false, 'error' => 'Invalid CSRF token'], 400, '/?page=financial/expense-create');
}

$orgId = request_client_org_id();
if (!user_can($pdo, (int)$userId, 'financial.manage', 0)) {
    expense_handler_finish(['success' => false, 'error' => 'Permission denied'], 403, '/?page=financial/expenses-list&tab=expenses');
}
$action = $_POST['action'] ?? '';
$response = ['success' => false, 'error' => ''];
$fallback = '/?page=financial/expense-create';

try {
    switch ($action) {
        case 'create':
            $vendorId = (int)($_POST['vendor_id'] ?? 0);
            $vendorName = trim($_POST['vendor_name'] ?? '');
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $clientId = (int)($_POST['client_id'] ?? 0);
            $projectId = (int)($_POST['project_id'] ?? 0);
            $receiptId = (int)($_POST['receipt_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $taxAmount = null;
            $expenseDate = $_POST['expense_date'] ?? date('Y-m-d');
            $description = trim($_POST['description'] ?? '');
            $paymentMethod = null;
            $referenceNumber = trim($_POST['reference_number'] ?? '');
            $isBillable = !empty($_POST['is_billable']) ? 1 : 0;
            $isTaxDeductible = isset($_POST['is_tax_deductible']) ? (int)!empty($_POST['is_tax_deductible']) : 1;
            $notes = trim($_POST['notes'] ?? '');

            if ($amount <= 0) {
                throw new Exception('Amount must be greater than 0');
            }
            if (empty($expenseDate)) {
                throw new Exception('Expense date is required');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$expenseDate)) {
                throw new Exception('Expense date must be in YYYY-MM-DD format');
            }

            if ($isBillable !== 1) {
                $clientId = 0;
                $projectId = 0;
            }

            $totalAmount = $amount;

            // Auto-create vendor if name provided but no vendor_id
            if ($vendorId <= 0 && $vendorName !== '') {
                $vStmt = $pdo->prepare('SELECT id FROM vendors WHERE name=? LIMIT 1');
                $vStmt->execute([$vendorName]);
                $vendorId = (int)$vStmt->fetchColumn();
                if ($vendorId <= 0) {
                    $insV = $pdo->prepare('INSERT INTO vendors (organization_id, name) VALUES (?, ?)');
                    $insV->execute([$orgId > 0 ? $orgId : null, $vendorName]);
                    $vendorId = (int)$pdo->lastInsertId();
                }
            }

            expense_handler_assert_org_row($pdo, 'vendors', $vendorId, $orgId, 'Vendor');
            expense_handler_assert_org_row($pdo, 'expense_categories', $categoryId, $orgId, 'Category');
            expense_handler_assert_client($pdo, $clientId, $orgId);
            expense_handler_assert_org_row($pdo, 'projects', $projectId, $orgId, 'Project');
            expense_handler_assert_org_row($pdo, 'receipts', $receiptId, $orgId, 'Receipt', 'uploaded_by', (int)$userId);

            $stmt = $pdo->prepare('
                INSERT INTO expenses (organization_id, vendor_id, category_id, client_id, project_id, receipt_id,
                    amount, tax_amount, total_amount, expense_date, description, payment_method, reference_number,
                    is_billable, is_tax_deductible, notes, created_by, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "confirmed")
            ');
            $stmt->execute([
                $orgId > 0 ? $orgId : null, $vendorId ?: null, $categoryId ?: null, $clientId ?: null, $projectId ?: null,
                $receiptId ?: null, $amount, $taxAmount, $totalAmount, $expenseDate, $description,
                $paymentMethod, $referenceNumber ?: null, $isBillable, $isTaxDeductible, $notes ?: null,
                $userId
            ]);
            $expenseId = (int)$pdo->lastInsertId();
            audit_log($pdo, 'expense.create', 'expense', $expenseId, ['amount' => $amount, 'vendor_id' => $vendorId]);
            $response = ['success' => true, 'id' => $expenseId, 'redirect' => '/?page=financial/expense-detail&id=' . $expenseId . '&created=1', 'status_param' => 'created'];
            break;

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            $fallback = $id > 0 ? '/?page=financial/expense-create&id=' . $id : '/?page=financial/expense-create';
            if ($id <= 0) throw new Exception('Invalid expense ID');
            [$expenseScopeWhere, $expenseScopeParams] = finance_scope_clause($pdo, 'e', (int)$userId, $orgId, 'created_by');
            $exists = $pdo->prepare('SELECT 1 FROM expenses e WHERE e.id = ? AND ' . $expenseScopeWhere . ' LIMIT 1');
            $exists->execute(array_merge([$id], $expenseScopeParams));
            if (!$exists->fetchColumn()) {
                throw new Exception('Expense not found');
            }

            $vendorId = (int)($_POST['vendor_id'] ?? 0);
            $vendorName = trim($_POST['vendor_name'] ?? '');
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $clientId = (int)($_POST['client_id'] ?? 0);
            $projectId = (int)($_POST['project_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $taxAmount = null;
            $expenseDate = $_POST['expense_date'] ?? date('Y-m-d');
            $description = trim($_POST['description'] ?? '');
            $paymentMethod = null;
            $referenceNumber = trim($_POST['reference_number'] ?? '');
            $isBillable = !empty($_POST['is_billable']) ? 1 : 0;
            $isTaxDeductible = isset($_POST['is_tax_deductible']) ? (int)!empty($_POST['is_tax_deductible']) : 1;
            $notes = trim($_POST['notes'] ?? '');

            if ($amount <= 0) {
                throw new Exception('Amount must be greater than 0');
            }
            if (empty($expenseDate)) {
                throw new Exception('Expense date is required');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$expenseDate)) {
                throw new Exception('Expense date must be in YYYY-MM-DD format');
            }
            if ($isBillable !== 1) {
                $clientId = 0;
                $projectId = 0;
            }

            $totalAmount = $amount;

            // Auto-create vendor if name provided but no vendor_id
            if ($vendorId <= 0 && $vendorName !== '') {
                $vStmt = $pdo->prepare('SELECT id FROM vendors WHERE name=? LIMIT 1');
                $vStmt->execute([$vendorName]);
                $vendorId = (int)$vStmt->fetchColumn();
                if ($vendorId <= 0) {
                    $insV = $pdo->prepare('INSERT INTO vendors (organization_id, name) VALUES (?, ?)');
                    $insV->execute([$orgId > 0 ? $orgId : null, $vendorName]);
                    $vendorId = (int)$pdo->lastInsertId();
                }
            }

            expense_handler_assert_org_row($pdo, 'vendors', $vendorId, $orgId, 'Vendor');
            expense_handler_assert_org_row($pdo, 'expense_categories', $categoryId, $orgId, 'Category');
            expense_handler_assert_client($pdo, $clientId, $orgId);
            expense_handler_assert_org_row($pdo, 'projects', $projectId, $orgId, 'Project');

            $stmt = $pdo->prepare('
                UPDATE expenses SET vendor_id=?, category_id=?, client_id=?, project_id=?, amount=?,
                    tax_amount=?, total_amount=?, expense_date=?, description=?, payment_method=?,
                    reference_number=?, is_billable=?, is_tax_deductible=?, notes=?
                WHERE id=?
            ');
            $stmt->execute([
                $vendorId ?: null, $categoryId ?: null, $clientId ?: null, $projectId ?: null,
                $amount, $taxAmount, $totalAmount, $expenseDate, $description,
                $paymentMethod, $referenceNumber ?: null, $isBillable, $isTaxDeductible, $notes ?: null,
                $id
            ]);
            audit_log($pdo, 'expense.update', 'expense', $id);
            $response = ['success' => true, 'redirect' => '/?page=financial/expense-detail&id=' . $id . '&updated=1', 'status_param' => 'updated'];
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            $fallback = $id > 0 ? '/?page=financial/expense-detail&id=' . $id : '/?page=financial/expenses-list&tab=expenses';
            if ($id <= 0) throw new Exception('Invalid expense ID');
            [$expenseScopeWhere, $expenseScopeParams] = finance_scope_clause($pdo, 'e', (int)$userId, $orgId, 'created_by');
            $pdo->prepare('UPDATE expenses e SET status="void" WHERE e.id=? AND ' . $expenseScopeWhere)->execute(array_merge([$id], $expenseScopeParams));
            audit_log($pdo, 'expense.delete', 'expense', $id);
            $response = ['success' => true, 'redirect' => '/?page=financial/expenses-list&tab=expenses&deleted=1'];
            break;

        case 'mark_reimbursed':
            $id = (int)($_POST['id'] ?? 0);
            $fallback = $id > 0 ? '/?page=financial/expense-detail&id=' . $id : '/?page=financial/expenses-list&tab=expenses';
            if ($id <= 0) throw new Exception('Invalid expense ID');
            [$expenseScopeWhere, $expenseScopeParams] = finance_scope_clause($pdo, 'e', (int)$userId, $orgId, 'created_by');
            $pdo->prepare('UPDATE expenses e SET is_reimbursed=1, status="reimbursed" WHERE e.id=? AND ' . $expenseScopeWhere)->execute(array_merge([$id], $expenseScopeParams));
            audit_log($pdo, 'expense.mark_reimbursed', 'expense', $id);
            $response = ['success' => true, 'redirect' => '/?page=financial/expense-detail&id=' . $id . '&reimbursed=1'];
            break;

        case 'mark_reconciled':
            $id = (int)($_POST['id'] ?? 0);
            $fallback = $id > 0 ? '/?page=financial/expense-detail&id=' . $id : '/?page=financial/expenses-list&tab=expenses';
            if ($id <= 0) throw new Exception('Invalid expense ID');
            [$expenseScopeWhere, $expenseScopeParams] = finance_scope_clause($pdo, 'e', (int)$userId, $orgId, 'created_by');
            $pdo->prepare('UPDATE expenses e SET is_reconciled=1 WHERE e.id=? AND ' . $expenseScopeWhere)->execute(array_merge([$id], $expenseScopeParams));
            audit_log($pdo, 'expense.mark_reconciled', 'expense', $id);
            $response = ['success' => true, 'redirect' => '/?page=financial/expense-detail&id=' . $id . '&reconciled=1'];
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Throwable $e) {
    $response = ['success' => false, 'error' => $e->getMessage()];
}

expense_handler_finish($response, !empty($response['success']) ? 200 : 400, $fallback);
