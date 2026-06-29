<?php
// src/controllers/project/project_invoice_payment.php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/project_invoice_billing.php';
require_once __DIR__ . '/../../utils/invoice_lifecycle.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/project-invoice-payment');

$id = (int)($_POST['id'] ?? 0);
$amount = (float)($_POST['amount'] ?? 0);
$method = trim((string)($_POST['method'] ?? 'cash'));
$reference = trim((string)($_POST['reference_number'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($id <= 0 || $amount <= 0) {
    header('Location: /?page=project/project-invoice-details&id=' . $id . '&payment_err=' . urlencode('Invalid payment amount'));
    exit;
}

$stmt = $pdo->prepare('SELECT project_id,balance_due,status,finalized_at FROM project_invoices WHERE id=?');
$stmt->execute([$id]);
$projectInvoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$projectId = (int)($projectInvoice['project_id'] ?? 0);
require_record_ownership($pdo, 'projects', $projectId);

if (empty($projectInvoice['finalized_at']) || !in_array((string)($projectInvoice['status'] ?? ''), ['sent','unpaid','partial'], true)) {
    header('Location: /?page=project/project-invoice-details&id=' . $id . '&payment_err=' . urlencode('Finalize the project invoice before recording payment.'));
    exit;
}
if ($amount > (float)($projectInvoice['balance_due'] ?? 0) + 0.005) {
    header('Location: /?page=project/project-invoice-details&id=' . $id . '&payment_err=' . urlencode('Payment cannot exceed the outstanding balance.'));
    exit;
}
try {
    invoice_expire_active_checkout($pdo, 'project_invoices', $id, $appConfig);
} catch (Throwable $e) {
    header('Location: /?page=project/project-invoice-details&id=' . $id . '&payment_err=' . urlencode($e->getMessage()));
    exit;
}

$ok = project_invoice_allocate_payment($pdo, $id, $amount, $method, $reference, $notes);
header('Location: /?page=project/project-invoice-details&id=' . $id . '&' . ($ok ? 'paid=1' : 'payment_err=' . urlencode('Could not record payment')));
exit;
