<?php

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/recurring_expenses.php';

function recurring_expense_handler_finish(array $response, string $fallback): void
{
    if (!empty($response['success'])) {
        header('Location: ' . (string)($response['redirect'] ?? '/?page=financial/expenses-list&tab=recurring'));
        exit;
    }
    header('Location: ' . $fallback . (str_contains($fallback, '?') ? '&' : '?') . 'error=' . urlencode((string)($response['error'] ?? 'Recurring expense request failed.')));
    exit;
}

function recurring_expense_handler_owned(PDO $pdo, int $id, int $userId, int $orgId): array
{
    [$scopeWhere, $scopeParams] = finance_scope_clause($pdo, 'r', $userId, $orgId, 'created_by');
    $stmt = $pdo->prepare('SELECT r.* FROM recurring_expenses r WHERE r.id=? AND ' . $scopeWhere . ' LIMIT 1');
    $stmt->execute(array_merge([$id], $scopeParams));
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$row) {
        throw new RuntimeException('Recurring expense was not found.');
    }
    return $row;
}

function recurring_expense_handler_reference(PDO $pdo, string $type, int $id, int $orgId): void
{
    if ($id <= 0) {
        return;
    }
    $definitions = [
        'vendor' => ['vendors', false],
        'category' => ['expense_categories', true],
        'client' => ['clients', false],
        'project' => ['projects', false],
    ];
    if (!isset($definitions[$type])) {
        throw new RuntimeException('Invalid recurring expense reference.');
    }
    [$table, $allowGlobal] = $definitions[$type];
    $where = 'id=?';
    $params = [$id];
    if ($orgId > 0) {
        $where .= $allowGlobal ? ' AND (organization_id=? OR organization_id IS NULL)' : ' AND organization_id=?';
        $params[] = $orgId;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$where} LIMIT 1");
    $stmt->execute($params);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException(ucfirst($type) . ' was not found.');
    }
}

/** @return array{0:int,1:string,2:string} */
function recurring_expense_handler_cadence(string $frequency): array
{
    return match ($frequency) {
        'weekly' => [1, 'week', 'Weekly'],
        'monthly' => [1, 'month', 'Monthly'],
        'quarterly' => [3, 'month', 'Quarterly'],
        'yearly' => [1, 'year', 'Yearly'],
        default => throw new RuntimeException('Choose a valid recurring frequency.'),
    };
}

function recurring_expense_handler_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId <= 0) {
    recurring_expense_handler_finish(['success' => false, 'error' => 'Not authenticated.'], '/?page=login');
}
$submitted = (string)($_POST['_token'] ?? '');
if (!(($submitted !== '' && csrf_sf_is_valid('expense', $submitted)) || csrf_validate())) {
    recurring_expense_handler_finish(['success' => false, 'error' => 'Invalid CSRF token.'], '/?page=financial/expenses-list&tab=recurring');
}
$orgId = request_client_org_id();
if (!user_can($pdo, $userId, 'financial.manage', 0)) {
    recurring_expense_handler_finish(['success' => false, 'error' => 'Permission denied.'], '/?page=financial/expenses-list&tab=recurring');
}

$action = (string)($_POST['action'] ?? '');
$id = (int)($_POST['id'] ?? 0);
$fallback = $id > 0
    ? '/?page=financial/recurring-expense-form&id=' . $id
    : '/?page=financial/recurring-expense-form';

try {
    if (in_array($action, ['create', 'update'], true)) {
        $existing = $action === 'update' ? recurring_expense_handler_owned($pdo, $id, $userId, $orgId) : [];
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        $vendorName = trim((string)($_POST['vendor_name'] ?? ''));
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);
        $projectId = (int)($_POST['project_id'] ?? 0);
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $description = trim((string)($_POST['description'] ?? ''));
        $frequency = strtolower(trim((string)($_POST['frequency'] ?? '')));
        [$intervalCount, $intervalUnit, $frequencyLabel] = recurring_expense_handler_cadence($frequency);
        $nextDate = trim((string)($_POST['next_expense_date'] ?? ''));
        $endDate = trim((string)($_POST['end_date'] ?? ''));
        $isBillable = !empty($_POST['is_billable']) ? 1 : 0;
        $isTaxDeductible = !empty($_POST['is_tax_deductible']) ? 1 : 0;
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($amount <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }
        if ($description === '' || mb_strlen($description) > 500) {
            throw new RuntimeException('Description is required and must be 500 characters or fewer.');
        }
        if ($vendorName !== '' && mb_strlen($vendorName) > 190) {
            throw new RuntimeException('Vendor name must be 190 characters or fewer.');
        }
        if (!recurring_expense_handler_valid_date($nextDate)) {
            throw new RuntimeException('Next expense date is required.');
        }
        if ($nextDate < date('Y-m-d')) {
            throw new RuntimeException('Next expense date cannot be in the past. Use today to record the first occurrence immediately.');
        }
        if ($endDate !== '' && (!recurring_expense_handler_valid_date($endDate) || $endDate < $nextDate)) {
            throw new RuntimeException('End date must be on or after the next expense date.');
        }

        if ($vendorId <= 0 && $vendorName !== '') {
            $vendor = $pdo->prepare('SELECT id FROM vendors WHERE name=? AND (?=0 OR organization_id=?) LIMIT 1');
            $vendor->execute([$vendorName, $orgId, $orgId]);
            $vendorId = (int)($vendor->fetchColumn() ?: 0);
            if ($vendorId <= 0) {
                $pdo->prepare('INSERT INTO vendors (organization_id,name) VALUES (?,?)')
                    ->execute([$orgId > 0 ? $orgId : null, $vendorName]);
                $vendorId = (int)$pdo->lastInsertId();
            }
        }

        recurring_expense_handler_reference($pdo, 'vendor', $vendorId, $orgId);
        recurring_expense_handler_reference($pdo, 'category', $categoryId, $orgId);
        recurring_expense_handler_reference($pdo, 'project', $projectId, $orgId);
        if ($projectId > 0) {
            $projectClient = $pdo->prepare('SELECT client_id FROM projects WHERE id=? LIMIT 1');
            $projectClient->execute([$projectId]);
            $projectClientId = (int)($projectClient->fetchColumn() ?: 0);
            if ($clientId <= 0 && $projectClientId > 0) {
                $clientId = $projectClientId;
            } elseif ($clientId > 0 && $projectClientId > 0 && $clientId !== $projectClientId) {
                throw new RuntimeException('The selected project belongs to a different client.');
            }
        }
        recurring_expense_handler_reference($pdo, 'client', $clientId, $orgId);
        if ($isBillable && $clientId <= 0) {
            throw new RuntimeException('Choose the client responsible for this billable recurring expense.');
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare('
                INSERT INTO recurring_expenses
                    (organization_id,vendor_id,category_id,client_id,project_id,amount,description,
                     interval_count,interval_unit,start_date,next_expense_date,end_date,is_billable,
                     is_tax_deductible,status,notes,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,"active",?,?)
            ');
            $stmt->execute([
                $orgId > 0 ? $orgId : null,
                $vendorId ?: null,
                $categoryId ?: null,
                $clientId ?: null,
                $projectId ?: null,
                $amount,
                $description,
                $intervalCount,
                $intervalUnit,
                $nextDate,
                $nextDate,
                $endDate !== '' ? $endDate : null,
                $isBillable,
                $isTaxDeductible,
                $notes !== '' ? $notes : null,
                $userId,
            ]);
            $id = (int)$pdo->lastInsertId();
            audit_log($pdo, 'recurring_expense.create', 'recurring_expense', $id, [
                'amount' => $amount,
                'frequency' => $frequencyLabel,
                'next_expense_date' => $nextDate,
            ], $userId);
        } else {
            $pdo->prepare('
                UPDATE recurring_expenses
                SET vendor_id=?,category_id=?,client_id=?,project_id=?,amount=?,description=?,
                    interval_count=?,interval_unit=?,start_date=?,next_expense_date=?,end_date=?,
                    is_billable=?,is_tax_deductible=?,notes=?
                WHERE id=?
            ')->execute([
                $vendorId ?: null,
                $categoryId ?: null,
                $clientId ?: null,
                $projectId ?: null,
                $amount,
                $description,
                $intervalCount,
                $intervalUnit,
                $nextDate,
                $nextDate,
                $endDate !== '' ? $endDate : null,
                $isBillable,
                $isTaxDeductible,
                $notes !== '' ? $notes : null,
                $id,
            ]);
            audit_log($pdo, 'recurring_expense.update', 'recurring_expense', $id, [
                'amount' => $amount,
                'frequency' => $frequencyLabel,
                'next_expense_date' => $nextDate,
                'previous_next_expense_date' => $existing['next_expense_date'] ?? null,
            ], $userId);
        }

        $generatedExpenseId = null;
        if (!empty($_POST['generate_now']) && $nextDate <= date('Y-m-d')) {
            $generated = recurring_expense_generate_one($pdo, $id, date('Y-m-d'));
            $generatedExpenseId = $generated['expense_id'] ?? null;
        }
        $redirect = '/?page=financial/expenses-list&tab=recurring&saved=1';
        if ($generatedExpenseId) {
            $redirect .= '&generated_expense_id=' . (int)$generatedExpenseId;
        }
        recurring_expense_handler_finish(['success' => true, 'redirect' => $redirect], $fallback);
    }

    $schedule = recurring_expense_handler_owned($pdo, $id, $userId, $orgId);
    if ($action === 'pause') {
        if ((string)$schedule['status'] !== 'active') {
            throw new RuntimeException('Only active recurring expenses can be paused.');
        }
        $pdo->prepare('UPDATE recurring_expenses SET status="paused" WHERE id=?')->execute([$id]);
        audit_log($pdo, 'recurring_expense.pause', 'recurring_expense', $id, [], $userId);
    } elseif ($action === 'resume') {
        if ((string)$schedule['status'] !== 'paused' || empty($schedule['next_expense_date'])) {
            throw new RuntimeException('This recurring expense cannot be resumed.');
        }
        $resumeDate = (string)$schedule['next_expense_date'];
        if ($resumeDate < date('Y-m-d')) {
            $resumeDate = date('Y-m-d');
        }
        $pdo->prepare('UPDATE recurring_expenses SET status="active",start_date=?,next_expense_date=? WHERE id=?')
            ->execute([$resumeDate, $resumeDate, $id]);
        audit_log($pdo, 'recurring_expense.resume', 'recurring_expense', $id, [], $userId);
    } elseif ($action === 'end') {
        $pdo->prepare('UPDATE recurring_expenses SET status="ended",next_expense_date=NULL WHERE id=?')->execute([$id]);
        audit_log($pdo, 'recurring_expense.end', 'recurring_expense', $id, [], $userId);
    } elseif ($action === 'generate_due') {
        $generated = recurring_expense_generate_one($pdo, $id, date('Y-m-d'));
        if (!$generated) {
            throw new RuntimeException('This recurring expense is not due yet.');
        }
        audit_log($pdo, 'recurring_expense.generate_due', 'recurring_expense', $id, [
            'expense_id' => $generated['expense_id'],
            'scheduled_date' => $generated['scheduled_date'],
        ], $userId);
        recurring_expense_handler_finish([
            'success' => true,
            'redirect' => '/?page=financial/expense-detail&id=' . (int)$generated['expense_id'] . '&created=1',
        ], $fallback);
    } else {
        throw new RuntimeException('Invalid recurring expense action.');
    }

    recurring_expense_handler_finish(['success' => true, 'redirect' => '/?page=financial/expenses-list&tab=recurring&saved=1'], $fallback);
} catch (Throwable $e) {
    recurring_expense_handler_finish(['success' => false, 'error' => $e->getMessage()], $fallback);
}
