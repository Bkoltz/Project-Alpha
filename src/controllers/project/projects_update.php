<?php
// src/controllers/project/projects_update.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/projects-update');

$id = (int)($_POST['id'] ?? 0); if (!$id) { header('Location: /?page=project/projects-list&error=Invalid'); exit; }
$name = trim($_POST['name'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$client_id = (int)($_POST['client_id'] ?? 0);
$parent_id = null; // Parent projects not supported any more
$organization_id = (int)($_POST['organization_id'] ?? 0);
$estimated_start = trim($_POST['estimated_start'] ?? '');
$estimated_end = trim($_POST['estimated_end'] ?? '');

// Validate dates
if ($estimated_start !== '' && $estimated_end !== '' && strtotime($estimated_start) > strtotime($estimated_end)) {
	header('Location: /?page=project/projects-list&id='.$id.'&error=Start%20must%20be%20before%20end');
	exit;
}

$stmt = $pdo->prepare('UPDATE projects SET name=?, client_id=?, organization_id=?, estimated_start=?, estimated_end=?, notes=?, updated_at=NOW() WHERE id=?');
$stmt->execute([
	$name,
	$client_id > 0 ? $client_id : null,
	$organization_id > 0 ? $organization_id : null,
	$estimated_start !== '' ? $estimated_start : null,
	$estimated_end !== '' ? $estimated_end : null,
	$notes ?: null,
	$id
]);
header('Location: /?page=project/projects-list&id='.$id.'&updated=1');
exit;
