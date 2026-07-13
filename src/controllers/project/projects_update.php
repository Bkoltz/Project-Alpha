<?php
// src/controllers/project/projects_update.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/project_invoice_billing.php';
require_once __DIR__ . '/../../utils/public_project_links.php';
require_once __DIR__ . '/../../utils/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/projects-update');

$id = (int)($_POST['id'] ?? 0); if (!$id) { header('Location: /?page=project/projects-list&error=Invalid'); exit; }
$detailsRedirect = '/?page=project/projects-details&id=' . $id;
$editRedirect = '/?page=project/projects-edit&id=' . $id;
$name = trim($_POST['name'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$client_id = (int)($_POST['client_id'] ?? 0);
$projectClientIds = $_POST['project_client_ids'] ?? [];
if (!is_array($projectClientIds)) { $projectClientIds = []; }
$projectInvoiceRecipientIds = $_POST['project_invoice_email_client_ids'] ?? [];
if (!is_array($projectInvoiceRecipientIds)) { $projectInvoiceRecipientIds = []; }
$projectInvoiceLinkClientIds = $_POST['project_invoice_link_client_ids'] ?? [];
if (!is_array($projectInvoiceLinkClientIds)) { $projectInvoiceLinkClientIds = []; }
$parent_id = null; // Parent projects not supported any more
$organization_id = (int)($_POST['organization_id'] ?? 0);
$department_id = (int)($_POST['department_id'] ?? 0);
$estimated_start = trim($_POST['estimated_start'] ?? '');
$estimated_end = trim($_POST['estimated_end'] ?? '');
$invoiceBillingPeriod = ($_POST['invoice_billing_period'] ?? 'per_invoice') === 'monthly' ? 'monthly' : 'per_invoice';
$invoiceNetTermsDays = trim((string)($_POST['invoice_net_terms_days'] ?? ''));
$invoiceNetTermsDays = $invoiceNetTermsDays === '' ? null : max(0, (int)$invoiceNetTermsDays);
$projectInvoiceAutoEmail = !empty($_POST['project_invoice_auto_email']) ? 1 : 0;
$publicProjectEnabled = !empty($_POST['public_project_enabled']) ? 1 : 0;
$publicProjectRequirePassword = !empty($_POST['public_project_require_password']) ? 1 : 0;
$publicProjectPassword = trim((string)($_POST['public_project_password'] ?? ''));
$publicProjectCanViewDocuments = !empty($_POST['public_project_can_view_documents']) ? 1 : 0;
$publicProjectCanViewInvoices = !empty($_POST['public_project_can_view_invoices']) ? 1 : 0;
$publicProjectCanUpload = !empty($_POST['public_project_can_upload']) ? 1 : 0;
$publicProjectCanRequestChanges = !empty($_POST['public_project_can_request_changes']) ? 1 : 0;

require_record_ownership($pdo, 'projects', $id);
pa_project_public_link_ensure_schema($pdo);

$projectStmt = $pdo->prepare('SELECT organization_id, public_project_token, public_project_password_hash FROM projects WHERE id = ? LIMIT 1');
$projectStmt->execute([$id]);
$storedProject = $projectStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$storedOrganizationId = (int)($storedProject['organization_id'] ?? 0);
if ($storedOrganizationId > 0) {
	$organization_id = $storedOrganizationId;
}
if ($department_id > 0) {
	$departmentStmt = $pdo->prepare('SELECT organization_id FROM organization_departments WHERE id = ? LIMIT 1');
	$departmentStmt->execute([$department_id]);
	$departmentOrgId = (int)($departmentStmt->fetchColumn() ?: 0);
	if ($departmentOrgId <= 0 || ($organization_id > 0 && $departmentOrgId !== $organization_id)) {
		header('Location: '.$editRedirect.'&error=' . urlencode('Selected department does not belong to the project organization.'));
		exit;
	}
	$organization_id = $departmentOrgId;
}

$projectClientIds = array_values(array_unique(array_filter(array_map('intval', $projectClientIds), static fn($clientId) => $clientId > 0)));
$projectInvoiceRecipientIds = array_values(array_unique(array_filter(array_map('intval', $projectInvoiceRecipientIds), static fn($clientId) => $clientId > 0)));
$projectInvoiceLinkClientIds = array_values(array_unique(array_filter(array_map('intval', $projectInvoiceLinkClientIds), static fn($clientId) => $clientId > 0)));
if ($client_id > 0 && !in_array($client_id, $projectClientIds, true)) {
	header('Location: '.$editRedirect.'&error=' . urlencode('Primary invoice receiver must remain attached to the project.'));
	exit;
}
$projectInvoiceRecipientIds = array_values(array_intersect($projectInvoiceRecipientIds, $projectClientIds));
$projectInvoiceLinkClientIds = array_values(array_intersect($projectInvoiceLinkClientIds, $projectClientIds));

if ($organization_id > 0) {
	require_record_ownership($pdo, 'organizations', $organization_id);
	if ($projectClientIds) {
		$placeholders = implode(',', array_fill(0, count($projectClientIds), '?'));
		$clientScope = $pdo->prepare("SELECT id FROM clients WHERE organization_id = ? AND archived = 0 AND id IN ({$placeholders})");
		$clientScope->execute(array_merge([$organization_id], $projectClientIds));
		$validClientIds = array_map('intval', $clientScope->fetchAll(PDO::FETCH_COLUMN));
		sort($validClientIds);
		$expectedClientIds = $projectClientIds;
		sort($expectedClientIds);
		if ($validClientIds !== $expectedClientIds) {
			header('Location: '.$editRedirect.'&error=' . urlencode('Project contacts must belong to the project organization.'));
			exit;
		}
	}
} else {
	foreach ($projectClientIds as $attachedClientId) {
		require_record_ownership($pdo, 'clients', $attachedClientId);
	}
}

// Validate dates
if ($estimated_start !== '' && $estimated_end !== '' && strtotime($estimated_start) > strtotime($estimated_end)) {
	header('Location: '.$editRedirect.'&error=Start%20must%20be%20before%20end');
	exit;
}
if ($publicProjectRequirePassword && $publicProjectPassword === '' && trim((string)($storedProject['public_project_password_hash'] ?? '')) === '') {
	header('Location: '.$editRedirect.'&error=' . urlencode('Enter an access code before requiring one for the public project link.'));
	exit;
}

$hasAutoEmailColumn = project_invoice_table_has_column($pdo, 'projects', 'project_invoice_auto_email');
$hasDepartmentColumn = project_invoice_table_has_column($pdo, 'projects', 'department_id');
if ($hasAutoEmailColumn) {
	$departmentSet = $hasDepartmentColumn ? 'department_id=?,' : '';
	$stmt = $pdo->prepare("UPDATE projects SET name=?, client_id=?, organization_id=?, {$departmentSet} invoice_billing_period=?, invoice_net_terms_days=?, project_invoice_auto_email=?, estimated_start=?, estimated_end=?, notes=?, updated_at=NOW() WHERE id=?");
	$params = [
		$name,
		$client_id > 0 ? $client_id : null,
		$organization_id > 0 ? $organization_id : null,
	];
	if ($hasDepartmentColumn) {
		$params[] = $department_id > 0 ? $department_id : null;
	}
	$params = array_merge($params, [
		$invoiceBillingPeriod,
		$invoiceNetTermsDays,
		$projectInvoiceAutoEmail,
		$estimated_start !== '' ? $estimated_start : null,
		$estimated_end !== '' ? $estimated_end : null,
		$notes ?: null,
		$id
	]);
	$stmt->execute($params);
} else {
	$departmentSet = $hasDepartmentColumn ? 'department_id=?,' : '';
	$stmt = $pdo->prepare("UPDATE projects SET name=?, client_id=?, organization_id=?, {$departmentSet} invoice_billing_period=?, invoice_net_terms_days=?, estimated_start=?, estimated_end=?, notes=?, updated_at=NOW() WHERE id=?");
	$params = [
		$name,
		$client_id > 0 ? $client_id : null,
		$organization_id > 0 ? $organization_id : null,
	];
	if ($hasDepartmentColumn) {
		$params[] = $department_id > 0 ? $department_id : null;
	}
	$params = array_merge($params, [
		$invoiceBillingPeriod,
		$invoiceNetTermsDays,
		$estimated_start !== '' ? $estimated_start : null,
		$estimated_end !== '' ? $estimated_end : null,
		$notes ?: null,
		$id
	]);
	$stmt->execute($params);
}
project_invoice_sync_clients($pdo, $id, $client_id > 0 ? $client_id : null, $projectClientIds, $projectInvoiceRecipientIds, $projectInvoiceLinkClientIds);

$publicProjectToken = trim((string)($storedProject['public_project_token'] ?? ''));
if ($publicProjectEnabled && $publicProjectToken === '') {
	$publicProjectToken = pa_project_public_token();
}
$publicProjectPasswordHash = trim((string)($storedProject['public_project_password_hash'] ?? ''));
if ($publicProjectPassword !== '') {
	$publicProjectPasswordHash = password_hash($publicProjectPassword, PASSWORD_DEFAULT);
}
$publicStmt = $pdo->prepare('
	UPDATE projects
	SET public_project_enabled = ?,
	    public_project_token = ?,
	    public_project_require_password = ?,
	    public_project_password_hash = ?,
	    public_project_can_view_documents = ?,
	    public_project_can_view_invoices = ?,
	    public_project_can_upload = ?,
	    public_project_can_request_changes = ?
	WHERE id = ?
');
$publicStmt->execute([
	$publicProjectEnabled,
	$publicProjectToken !== '' ? $publicProjectToken : null,
	$publicProjectRequirePassword,
	$publicProjectPasswordHash !== '' ? $publicProjectPasswordHash : null,
	$publicProjectCanViewDocuments,
	$publicProjectCanViewInvoices,
	$publicProjectCanUpload,
	$publicProjectCanRequestChanges,
	$id,
]);
header('Location: '.$detailsRedirect.'&updated=1');
exit;
