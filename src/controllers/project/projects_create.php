<?php
// src/controllers/project/projects_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/project_invoice_billing.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/ScheduleService.php';

$__orgId = request_client_org_id() ?: null;
$__creator = (int)($_SESSION['user']['id'] ?? 0) ?: null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/projects-create');

$name = trim($_POST['name'] ?? '');
$client_id = (int)($_POST['client_id'] ?? 0);
$projectClientIds = $_POST['project_client_ids'] ?? [];
if (!is_array($projectClientIds)) { $projectClientIds = []; }
$projectInvoiceRecipientIds = $_POST['project_invoice_email_client_ids'] ?? [];
if ($projectInvoiceRecipientIds !== null && !is_array($projectInvoiceRecipientIds)) { $projectInvoiceRecipientIds = []; }
$projectInvoiceLinkClientIds = $_POST['project_invoice_link_client_ids'] ?? null;
if ($projectInvoiceLinkClientIds !== null && !is_array($projectInvoiceLinkClientIds)) { $projectInvoiceLinkClientIds = []; }
$parent_id = null; // Parent projects are not supported any more
$organization_id = (int)($_POST['organization_id'] ?? 0);
$department_id = (int)($_POST['department_id'] ?? 0);
$estimated_start = trim($_POST['estimated_start'] ?? '');
$estimated_end = trim($_POST['estimated_end'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$invoiceBillingPeriod = ($_POST['invoice_billing_period'] ?? 'per_invoice') === 'monthly' ? 'monthly' : 'per_invoice';
$invoiceNetTermsDays = trim((string)($_POST['invoice_net_terms_days'] ?? ''));
$invoiceNetTermsDays = $invoiceNetTermsDays === '' ? null : max(0, (int)$invoiceNetTermsDays);
$projectInvoiceAutoEmail = !empty($_POST['project_invoice_auto_email']) ? 1 : 0;

if ($name === '') { header('Location: /?page=project/projects-list&error=Name%20required'); exit; }

if ($organization_id <= 0 && $__orgId !== null) {
	$organization_id = (int)$__orgId;
}
if ($organization_id > 0) {
	require_record_ownership($pdo, 'organizations', $organization_id);
}
if ($department_id > 0) {
	$departmentStmt = $pdo->prepare('SELECT organization_id FROM organization_departments WHERE id = ? LIMIT 1');
	$departmentStmt->execute([$department_id]);
	$departmentOrgId = (int)($departmentStmt->fetchColumn() ?: 0);
	if ($departmentOrgId <= 0 || ($organization_id > 0 && $departmentOrgId !== $organization_id)) {
		header('Location: /?page=project/projects-create&error=' . urlencode('Selected department does not belong to the selected organization.'));
		exit;
	}
	$organization_id = $departmentOrgId;
}
if ($organization_id > 0) {
	require_record_ownership($pdo, 'organizations', $organization_id);
}

$projectClientIds = array_values(array_unique(array_filter(array_map('intval', $projectClientIds), static fn($id) => $id > 0)));
$projectInvoiceRecipientIds = $projectInvoiceRecipientIds === null
	? null
	: array_values(array_unique(array_filter(array_map('intval', $projectInvoiceRecipientIds), static fn($id) => $id > 0)));
$projectInvoiceLinkClientIds = $projectInvoiceLinkClientIds === null
	? null
	: array_values(array_unique(array_filter(array_map('intval', $projectInvoiceLinkClientIds), static fn($id) => $id > 0)));
$projectClientIds = array_values(array_unique(array_merge(
	$projectClientIds,
	$projectInvoiceRecipientIds ?? [],
	$projectInvoiceLinkClientIds ?? []
)));
if ($client_id > 0 && !in_array($client_id, $projectClientIds, true)) {
	$projectClientIds[] = $client_id;
}
if ($organization_id > 0 && $projectClientIds) {
	$placeholders = implode(',', array_fill(0, count($projectClientIds), '?'));
	$clientScope = $pdo->prepare("SELECT id FROM clients WHERE organization_id = ? AND archived = 0 AND id IN ({$placeholders})");
	$clientScope->execute(array_merge([$organization_id], $projectClientIds));
	$validClientIds = array_map('intval', $clientScope->fetchAll(PDO::FETCH_COLUMN));
	sort($validClientIds);
	$expectedClientIds = $projectClientIds;
	sort($expectedClientIds);
	if ($validClientIds !== $expectedClientIds) {
		header('Location: /?page=project/projects-create&error=' . urlencode('Project contacts must belong to the selected organization.'));
		exit;
	}
} elseif ($projectClientIds) {
	foreach ($projectClientIds as $attachedClientId) {
		require_record_ownership($pdo, 'clients', $attachedClientId);
	}
}
$projectInvoiceRecipientIds = $projectInvoiceRecipientIds === null ? null : array_values(array_intersect($projectInvoiceRecipientIds, $projectClientIds));
$projectInvoiceLinkClientIds = $projectInvoiceLinkClientIds === null ? null : array_values(array_intersect($projectInvoiceLinkClientIds, $projectClientIds));

// Validate date window if set
if ($estimated_start !== '' && $estimated_end !== '') {
	if (strtotime($estimated_start) > strtotime($estimated_end)) {
		header('Location: /?page=project/projects-create&error=Start%20must%20be%20before%20end'); exit;
	}
}

$hasAutoEmailColumn = project_invoice_table_has_column($pdo, 'projects', 'project_invoice_auto_email');
$hasDepartmentColumn = project_invoice_table_has_column($pdo, 'projects', 'department_id');
if ($hasAutoEmailColumn) {
	$departmentColumn = $hasDepartmentColumn ? ', department_id' : '';
	$departmentValue = $hasDepartmentColumn ? ', ?' : '';
	$ins = $pdo->prepare("INSERT INTO projects (name, client_id, organization_id{$departmentColumn}, invoice_billing_period, invoice_net_terms_days, project_invoice_auto_email, estimated_start, estimated_end, notes, created_by, created_at) VALUES (?,?,?{$departmentValue},?,?,?,?,?,?,?,NOW())");
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
		$__creator
	]);
	$ins->execute($params);
} else {
	$departmentColumn = $hasDepartmentColumn ? ', department_id' : '';
	$departmentValue = $hasDepartmentColumn ? ', ?' : '';
	$ins = $pdo->prepare("INSERT INTO projects (name, client_id, organization_id{$departmentColumn}, invoice_billing_period, invoice_net_terms_days, estimated_start, estimated_end, notes, created_by, created_at) VALUES (?,?,?{$departmentValue},?,?,?,?,?,?,NOW())");
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
		$__creator
	]);
	$ins->execute($params);
}

$project_id = (int)$pdo->lastInsertId();
$serviceLocationIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['service_location_ids'] ?? [])))));
$defaultServiceLocationId = (int)($_POST['default_service_location_id'] ?? 0);
if ($defaultServiceLocationId > 0 && !in_array($defaultServiceLocationId, $serviceLocationIds, true)) $serviceLocationIds[] = $defaultServiceLocationId;
foreach ($serviceLocationIds as $locationId) {
	$validLocation=$pdo->prepare('SELECT id FROM service_locations WHERE id=? AND archived=0');$validLocation->execute([$locationId]);
	if ($validLocation->fetchColumn()) $pdo->prepare('INSERT INTO project_service_locations (project_id,service_location_id,is_default) VALUES (?,?,?)')->execute([$project_id,$locationId,$locationId===$defaultServiceLocationId?1:0]);
}
project_invoice_sync_clients(
	$pdo,
	$project_id,
	$client_id > 0 ? $client_id : null,
	$projectClientIds,
	$projectInvoiceRecipientIds,
	$projectInvoiceLinkClientIds
);
audit_log($pdo, 'project.create', 'project', $project_id, ['client_id' => $client_id > 0 ? $client_id : null, 'organization_id' => $organization_id > 0 ? $organization_id : null, 'department_id' => $department_id > 0 ? $department_id : null, 'created_by' => $__creator]);
ScheduleService::syncProject($pdo, $project_id, (string)($appConfig['timezone'] ?? 'UTC'), $__creator);
header('Location: /?page=project/projects-details&id=' . $project_id . '&created=1');
exit;
