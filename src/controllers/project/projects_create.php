<?php
// src/controllers/project/projects_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';

$__orgId = get_active_org_id() ?: null;
$__creator = (int)($_SESSION['user']['id'] ?? 0) ?: null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/projects-create');

$name = trim($_POST['name'] ?? '');
$client_id = (int)($_POST['client_id'] ?? 0);
$parent_id = null; // Parent projects are not supported any more
$organization_id = (int)($_POST['organization_id'] ?? 0);
$estimated_start = trim($_POST['estimated_start'] ?? '');
$estimated_end = trim($_POST['estimated_end'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$invoiceBillingPeriod = ($_POST['invoice_billing_period'] ?? 'per_invoice') === 'monthly' ? 'monthly' : 'per_invoice';
$invoiceNetTermsDays = trim((string)($_POST['invoice_net_terms_days'] ?? ''));
$invoiceNetTermsDays = $invoiceNetTermsDays === '' ? null : max(0, (int)$invoiceNetTermsDays);

if ($name === '') { header('Location: /?page=project/projects-list&error=Name%20required'); exit; }

// Validate date window if set
if ($estimated_start !== '' && $estimated_end !== '') {
	if (strtotime($estimated_start) > strtotime($estimated_end)) {
		header('Location: /?page=project/projects-create&error=Start%20must%20be%20before%20end'); exit;
	}
}

$ins = $pdo->prepare('INSERT INTO projects (name, client_id, organization_id, invoice_billing_period, invoice_net_terms_days, estimated_start, estimated_end, notes, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
$ins->execute([
	$name,
	$client_id > 0 ? $client_id : null,
	$organization_id > 0 ? $organization_id : null,
	$invoiceBillingPeriod,
	$invoiceNetTermsDays,
	$estimated_start !== '' ? $estimated_start : null,
	$estimated_end !== '' ? $estimated_end : null,
	$notes ?: null,
	$__creator
]);

$project_id = (int)$pdo->lastInsertId();
audit_log($pdo, 'project.create', 'project', $project_id, ['client_id' => $client_id > 0 ? $client_id : null, 'organization_id' => $organization_id > 0 ? $organization_id : null, 'created_by' => $__creator]);
header('Location: /?page=project/projects-list&created=1');
exit;
