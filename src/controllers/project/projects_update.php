<?php
// src/controllers/project/projects_update.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/project_invoice_billing.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/projects-update');

$id = (int)($_POST['id'] ?? 0); if (!$id) { header('Location: /?page=project/projects-list&error=Invalid'); exit; }
$name = trim($_POST['name'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$client_id = (int)($_POST['client_id'] ?? 0);
$projectClientIds = $_POST['project_client_ids'] ?? [];
if (!is_array($projectClientIds)) { $projectClientIds = []; }
$projectInvoiceRecipientIds = $_POST['project_invoice_email_client_ids'] ?? [];
if (!is_array($projectInvoiceRecipientIds)) { $projectInvoiceRecipientIds = []; }
$parent_id = null; // Parent projects not supported any more
$organization_id = (int)($_POST['organization_id'] ?? 0);
$estimated_start = trim($_POST['estimated_start'] ?? '');
$estimated_end = trim($_POST['estimated_end'] ?? '');
$invoiceBillingPeriod = ($_POST['invoice_billing_period'] ?? 'per_invoice') === 'monthly' ? 'monthly' : 'per_invoice';
$invoiceNetTermsDays = trim((string)($_POST['invoice_net_terms_days'] ?? ''));
$invoiceNetTermsDays = $invoiceNetTermsDays === '' ? null : max(0, (int)$invoiceNetTermsDays);
$projectInvoiceAutoEmail = !empty($_POST['project_invoice_auto_email']) ? 1 : 0;

require_record_ownership($pdo, 'projects', $id);

// Validate dates
if ($estimated_start !== '' && $estimated_end !== '' && strtotime($estimated_start) > strtotime($estimated_end)) {
	header('Location: /?page=project/projects-list&id='.$id.'&error=Start%20must%20be%20before%20end');
	exit;
}

$hasAutoEmailColumn = project_invoice_table_has_column($pdo, 'projects', 'project_invoice_auto_email');
if ($hasAutoEmailColumn) {
	$stmt = $pdo->prepare('UPDATE projects SET name=?, client_id=?, organization_id=?, invoice_billing_period=?, invoice_net_terms_days=?, project_invoice_auto_email=?, estimated_start=?, estimated_end=?, notes=?, updated_at=NOW() WHERE id=?');
	$stmt->execute([
		$name,
		$client_id > 0 ? $client_id : null,
		$organization_id > 0 ? $organization_id : null,
		$invoiceBillingPeriod,
		$invoiceNetTermsDays,
		$projectInvoiceAutoEmail,
		$estimated_start !== '' ? $estimated_start : null,
		$estimated_end !== '' ? $estimated_end : null,
		$notes ?: null,
		$id
	]);
} else {
	$stmt = $pdo->prepare('UPDATE projects SET name=?, client_id=?, organization_id=?, invoice_billing_period=?, invoice_net_terms_days=?, estimated_start=?, estimated_end=?, notes=?, updated_at=NOW() WHERE id=?');
	$stmt->execute([
		$name,
		$client_id > 0 ? $client_id : null,
		$organization_id > 0 ? $organization_id : null,
		$invoiceBillingPeriod,
		$invoiceNetTermsDays,
		$estimated_start !== '' ? $estimated_start : null,
		$estimated_end !== '' ? $estimated_end : null,
		$notes ?: null,
		$id
	]);
}
project_invoice_sync_clients($pdo, $id, $client_id > 0 ? $client_id : null, array_map('intval', $projectClientIds), array_map('intval', $projectInvoiceRecipientIds));
header('Location: /?page=project/projects-details&id='.$id.'&updated=1');
exit;
