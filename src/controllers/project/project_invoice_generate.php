<?php
// src/controllers/project/project_invoice_generate.php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/project_invoice_billing.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/project-invoice-generate');

$projectId = (int)($_POST['project_id'] ?? 0);
if ($projectId <= 0) {
    header('Location: /?page=project/projects-list&error=' . urlencode('Invalid project'));
    exit;
}
require_record_ownership($pdo, 'projects', $projectId);

$period = (string)($_POST['period'] ?? 'current');
$sendEmail = !empty($_POST['send_email']);
if ($period === 'previous') {
    [$start, $end] = project_invoice_period_for_date(date('Y-m-d'), true);
} else {
    $start = date('Y-m-01');
    $end = date('Y-m-d');
}

$projectInvoiceId = project_invoice_create_for_period($pdo, $projectId, $start, $end, $appConfig, $sendEmail, $sendEmail);
if ($projectInvoiceId) {
    header('Location: /?page=project/project-invoice-details&id=' . $projectInvoiceId . '&generated=1' . ($sendEmail ? '&emailed=1' : ''));
    exit;
}

header('Location: /?page=project/projects-details&id=' . $projectId . '&billing_msg=' . urlencode('No unbilled invoices found for that billing period.'));
exit;
