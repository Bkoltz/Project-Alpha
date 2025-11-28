<?php
// src/controllers/project/projects_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/projects-create');

$name = trim($_POST['name'] ?? '');
$client_id = (int)($_POST['client_id'] ?? 0);
$parent_id = null; // Parent projects are not supported any more
$organization_id = (int)($_POST['organization_id'] ?? 0);
$estimated_start = trim($_POST['estimated_start'] ?? '');
$estimated_end = trim($_POST['estimated_end'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if ($name === '') { header('Location: /?page=project/projects-list&error=Name%20required'); exit; }

// Validate date window if set
if ($estimated_start !== '' && $estimated_end !== '') {
	if (strtotime($estimated_start) > strtotime($estimated_end)) {
		header('Location: /?page=project/projects-create&error=Start%20must%20be%20before%20end'); exit;
	}
}

$ins = $pdo->prepare('INSERT INTO projects (name, client_id, organization_id, estimated_start, estimated_end, notes, created_at) VALUES (?,?,?,?,?,?,NOW())');
$ins->execute([
	$name,
	$client_id > 0 ? $client_id : null,
	$organization_id > 0 ? $organization_id : null,
	$estimated_start !== '' ? $estimated_start : null,
	$estimated_end !== '' ? $estimated_end : null,
	$notes ?: null
]);
header('Location: /?page=project/projects-list&created=1');
exit;
