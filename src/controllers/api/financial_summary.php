<?php
// src/controllers/api/financial_summary.php
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json');

$period = (int)($_GET['days'] ?? 30);
if ($period < 1 || $period > 365) $period = 30;
$start = gmdate('Y-m-d', strtotime("-$period days"));

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='succeeded' AND DATE(paid_at)>=?");
$stmt->execute([$start]);
$revenue = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND DATE(updated_at)>=?");
$stmt->execute([$start]);
$invoicedPaid = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE(date)>=?");
$stmt->execute([$start]);
$expenses = (float) $stmt->fetchColumn();

echo json_encode([
    'period_days'  => $period,
    'start_date'   => $start,
    'revenue'      => $revenue,
    'expenses'     => $expenses,
    'profit'       => round($revenue - $expenses, 2),
], JSON_NUMERIC_CHECK);
