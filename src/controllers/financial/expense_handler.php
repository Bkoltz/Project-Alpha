<?php
// src/controllers/financial/expense_handler.php
// POST controller for expense CRUD: create, update, delete, mark_reimbursed, mark_reconciled

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';

header('Content-Type: application/json');

$userId = $_SESSION['user']['id'] ?? null;
if (!$userId) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if (!csrf_validate()) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$orgId = 1;
$action = $_POST['action'] ?? '';
$response = ['success' => false, 'error' => ''];

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
            $taxAmount = $_POST['tax_amount'] !== '' ? (float)$_POST['tax_amount'] : null;
            $expenseDate = $_POST['expense_date'] ?? date('Y-m-d');
            $description = trim($_POST['description'] ?? '');
            $paymentMethod = $_POST['payment_method'] ?? null;
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

            $totalAmount = $taxAmount !== null ? $amount + $taxAmount : $amount;

            // Auto-create vendor if name provided but no vendor_id
            if ($vendorId <= 0 && $vendorName !== '') {
                $vStmt = $pdo->prepare('SELECT id FROM vendors WHERE organization_id=? AND name=? LIMIT 1');
                $vStmt->execute([$orgId, $vendorName]);
                $vendorId = (int)$vStmt->fetchColumn();
                if ($vendorId <= 0) {
                    $insV = $pdo->prepare('INSERT INTO vendors (organization_id, name) VALUES (?, ?)');
                    $insV->execute([$orgId, $vendorName]);
                    $vendorId = (int)$pdo->lastInsertId();
                }
            }

            $stmt = $pdo->prepare('
                INSERT INTO expenses (organization_id, vendor_id, category_id, client_id, project_id, receipt_id,
                    amount, tax_amount, total_amount, expense_date, description, payment_method, reference_number,
                    is_billable, is_tax_deductible, notes, created_by, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "confirmed")
            ');
            $stmt->execute([
                $orgId, $vendorId ?: null, $categoryId ?: null, $clientId ?: null, $projectId ?: null,
                $receiptId ?: null, $amount, $taxAmount, $totalAmount, $expenseDate, $description,
                $paymentMethod, $referenceNumber ?: null, $isBillable, $isTaxDeductible, $notes ?: null,
                $userId
            ]);
            $expenseId = (int)$pdo->lastInsertId();
            audit_log($pdo, 'expense.create', 'expense', $expenseId, ['amount' => $amount, 'vendor_id' => $vendorId]);
            $response = ['success' => true, 'id' => $expenseId, 'redirect' => '/?page=financial/expense-detail&id=' . $expenseId];
            break;

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid expense ID');

            $vendorId = (int)($_POST['vendor_id'] ?? 0);
            $vendorName = trim($_POST['vendor_name'] ?? '');
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $clientId = (int)($_POST['client_id'] ?? 0);
            $projectId = (int)($_POST['project_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $taxAmount = $_POST['tax_amount'] !== '' ? (float)$_POST['tax_amount'] : null;
            $expenseDate = $_POST['expense_date'] ?? date('Y-m-d');
            $description = trim($_POST['description'] ?? '');
            $paymentMethod = $_POST['payment_method'] ?? null;
            $referenceNumber = trim($_POST['reference_number'] ?? '');
            $isBillable = !empty($_POST['is_billable']) ? 1 : 0;
            $isTaxDeductible = isset($_POST['is_tax_deductible']) ? (int)!empty($_POST['is_tax_deductible']) : 1;
            $notes = trim($_POST['notes'] ?? '');

            $totalAmount = $taxAmount !== null ? $amount + $taxAmount : $amount;

            // Auto-create vendor if name provided but no vendor_id
            if ($vendorId <= 0 && $vendorName !== '') {
                $vStmt = $pdo->prepare('SELECT id FROM vendors WHERE organization_id=? AND name=? LIMIT 1');
                $vStmt->execute([$orgId, $vendorName]);
                $vendorId = (int)$vStmt->fetchColumn();
                if ($vendorId <= 0) {
                    $insV = $pdo->prepare('INSERT INTO vendors (organization_id, name) VALUES (?, ?)');
                    $insV->execute([$orgId, $vendorName]);
                    $vendorId = (int)$pdo->lastInsertId();
                }
            }

            $stmt = $pdo->prepare('
                UPDATE expenses SET vendor_id=?, category_id=?, client_id=?, project_id=?, amount=?,
                    tax_amount=?, total_amount=?, expense_date=?, description=?, payment_method=?,
                    reference_number=?, is_billable=?, is_tax_deductible=?, notes=?
                WHERE id=? AND organization_id=?
            ');
            $stmt->execute([
                $vendorId ?: null, $categoryId ?: null, $clientId ?: null, $projectId ?: null,
                $amount, $taxAmount, $totalAmount, $expenseDate, $description,
                $paymentMethod, $referenceNumber ?: null, $isBillable, $isTaxDeductible, $notes ?: null,
                $id, $orgId
            ]);
            audit_log($pdo, 'expense.update', 'expense', $id);
            $response = ['success' => true, 'redirect' => '/?page=financial/expense-detail&id=' . $id];
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid expense ID');
            $pdo->prepare('UPDATE expenses SET status="void" WHERE id=? AND organization_id=?')->execute([$id, $orgId]);
            audit_log($pdo, 'expense.delete', 'expense', $id);
            $response = ['success' => true, 'redirect' => '/?page=financial/expenses-list'];
            break;

        case 'mark_reimbursed':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid expense ID');
            $pdo->prepare('UPDATE expenses SET is_reimbursed=1, status="reimbursed" WHERE id=? AND organization_id=?')->execute([$id, $orgId]);
            audit_log($pdo, 'expense.mark_reimbursed', 'expense', $id);
            $response = ['success' => true];
            break;

        case 'mark_reconciled':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid expense ID');
            $pdo->prepare('UPDATE expenses SET is_reconciled=1 WHERE id=? AND organization_id=?')->execute([$id, $orgId]);
            audit_log($pdo, 'expense.mark_reconciled', 'expense', $id);
            $response = ['success' => true];
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Throwable $e) {
    $response = ['success' => false, 'error' => $e->getMessage()];
}

echo json_encode($response);