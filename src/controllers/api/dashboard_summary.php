<?php
// src/controllers/api/dashboard_summary.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/app_version.php';
require_once __DIR__ . '/../../utils/payment_accounting.php';
header('Content-Type: application/json');

// Lightweight read-only summary for the Command Center dashboard.
function _scalar($pdo, $sql) {
    return (float) $pdo->query($sql)->fetchColumn();
}
function _count($pdo, $sql) {
    return (int) $pdo->query($sql)->fetchColumn();
}

$incomeExpr = payment_accounting_net_income_expr('p');

$resp = [
    'generated_at' => gmdate('c'),
    'version'      => app_version(),
    'clients'      => _count($pdo, "SELECT COUNT(*) FROM clients"),
    'projects'     => _count($pdo, "SELECT COUNT(*) FROM projects"),
    'quotes'       => _count($pdo, "SELECT COUNT(*) FROM quotes"),
    'contracts'    => _count($pdo, "SELECT COUNT(*) FROM contracts"),
    'invoices'     => _count($pdo, "SELECT COUNT(*) FROM invoices"),
    'expenses'     => _count($pdo, "SELECT COUNT(*) FROM expenses"),
    'revenue'      => _scalar($pdo, "SELECT COALESCE(SUM({$incomeExpr}),0) FROM payments p WHERE p.status='succeeded'"),
    'outstanding'  => _scalar($pdo, "SELECT COALESCE(SUM(balance_due),0) FROM invoices WHERE status NOT IN ('paid','cancelled','void')"),
];

echo json_encode($resp, JSON_NUMERIC_CHECK);
