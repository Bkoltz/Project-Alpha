<?php
// src/controllers/project/project_invoice_email.php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/project_invoice_billing.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/project-invoice-email');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=project/projects-list&error=' . urlencode('Invalid project invoice'));
    exit;
}

$stmt = $pdo->prepare('SELECT project_id FROM project_invoices WHERE id=?');
$stmt->execute([$id]);
$projectId = (int)($stmt->fetchColumn() ?: 0);
require_record_ownership($pdo, 'projects', $projectId);

$recipientClientIds = $_POST['recipient_client_ids'] ?? null;
if ($recipientClientIds !== null && !is_array($recipientClientIds)) {
    $recipientClientIds = [];
}
$recipientClientIds = $recipientClientIds === null ? null : array_map('intval', $recipientClientIds);
$sent = project_invoice_send_email($pdo, $id, $appConfig, $recipientClientIds, true);
$param = $sent > 0 ? 'emailed=1' : 'email_err=' . urlencode('No new project invoice emails were sent.');
header('Location: /?page=project/project-invoice-details&id=' . $id . '&' . $param);
exit;
