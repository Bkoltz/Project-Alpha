<?php
// src/controllers/project/project_invoice_payment.php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/project_invoice_billing.php';

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

$stmt = $pdo->prepare('SELECT project_id FROM project_invoices WHERE id=?');
$stmt->execute([$id]);
$projectId = (int)($stmt->fetchColumn() ?: 0);
require_record_ownership($pdo, 'projects', $projectId);

$ok = project_invoice_allocate_payment($pdo, $id, $amount, $method, $reference, $notes);
header('Location: /?page=project/project-invoice-details&id=' . $id . '&' . ($ok ? 'paid=1' : 'payment_err=' . urlencode('Could not record payment')));
exit;
