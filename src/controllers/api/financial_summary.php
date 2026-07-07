<?php
// src/controllers/api/financial_summary.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/payment_accounting.php';
header('Content-Type: application/json');

$period = (int)($_GET['days'] ?? 30);
if ($period < 1 || $period > 365) $period = 30;
$start = gmdate('Y-m-d', strtotime("-$period days"));

$incomeExpr = payment_accounting_net_income_expr('p');
$stmt = $pdo->prepare("SELECT COALESCE(SUM({$incomeExpr}),0) FROM payments p WHERE p.status='succeeded' AND DATE(p.payment_date)>=?");
$stmt->execute([$start]);
$revenue = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND DATE(updated_at)>=?");
$stmt->execute([$start]);
$invoicedPaid = (float) $stmt->fetchColumn();

$userId = (int)($_SESSION['user']['id'] ?? 0);
$orgId = request_client_org_id();
[$expenseScopeWhere, $expenseScopeParams] = finance_scope_clause($pdo, 'e', $userId, $orgId, 'created_by');
$stmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(e.total_amount, e.amount, 0)),0) FROM expenses e WHERE {$expenseScopeWhere} AND e.status != 'void' AND DATE(e.expense_date)>=?");
$stmt->execute(array_merge($expenseScopeParams, [$start]));
$expenses = (float) $stmt->fetchColumn();

echo json_encode([
    'period_days'  => $period,
    'start_date'   => $start,
    'revenue'      => $revenue,
    'expenses'     => $expenses,
    'profit'       => round($revenue - $expenses, 2),
], JSON_NUMERIC_CHECK);
