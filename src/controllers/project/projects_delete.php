<?php
// src/controllers/project/projects_delete.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/projects-delete');

$id = (int)($_POST['id'] ?? 0); if (!$id) { header('Location: /?page=project/projects-list&error=Invalid'); exit; }
$redirect = (string)($_POST['redirect'] ?? '/?page=project/projects-list');

// Delete mappings first
$pdo->prepare('DELETE FROM project_documents WHERE project_id=?')->execute([$id]);
// Delete project
$pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);

header('Location: ' . $redirect . '&deleted=1');
exit;
