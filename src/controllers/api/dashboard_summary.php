<?php
// src/controllers/api/dashboard_summary.php
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json');

// Lightweight read-only summary for the Command Center dashboard.
function _scalar($pdo, $sql) {
    return (float) $pdo->query($sql)->fetchColumn();
}
function _count($pdo, $sql) {
    return (int) $pdo->query($sql)->fetchColumn();
}

$resp = [
    'generated_at' => gmdate('c'),
    'clients'      => _count($pdo, "SELECT COUNT(*) FROM clients"),
    'projects'     => _count($pdo, "SELECT COUNT(*) FROM projects"),
    'quotes'       => _count($pdo, "SELECT COUNT(*) FROM quotes"),
    'contracts'    => _count($pdo, "SELECT COUNT(*) FROM contracts"),
    'invoices'     => _count($pdo, "SELECT COUNT(*) FROM invoices"),
    'expenses'     => _count($pdo, "SELECT COUNT(*) FROM expenses"),
    'revenue'      => _scalar($pdo, "SELECT COALESCE(SUM(amount),0) FROM payments WHERE is_refunded=0"),
    'outstanding'  => _scalar($pdo, "SELECT COALESCE(SUM(balance),0) FROM invoices WHERE status!='paid' AND status!='cancelled'"),
];

echo json_encode($resp, JSON_NUMERIC_CHECK);
