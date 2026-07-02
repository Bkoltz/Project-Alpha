<?php
// src/controllers/project/projects_delete.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/project_files.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('project/projects-delete');

$id = (int)($_POST['id'] ?? 0); if (!$id) { header('Location: /?page=project/projects-list&error=Invalid'); exit; }
$redirect = (string)($_POST['redirect'] ?? '/?page=project/projects-list');

function project_delete_upload_tree(string $dir, string $root): void
{
    $realDir = realpath($dir);
    $realRoot = realpath($root);
    if (!$realDir || !$realRoot || strpos($realDir, $realRoot) !== 0 || !is_dir($realDir)) {
        return;
    }
    $items = scandir($realDir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $realDir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            project_delete_upload_tree($path, $realRoot);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }
    @rmdir($realDir);
}

// Delete mappings first
$pdo->prepare('DELETE FROM project_documents WHERE project_id=?')->execute([$id]);
// Delete project
$pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);
project_delete_upload_tree(project_files_project_dir($id), project_files_storage_root());

header('Location: ' . $redirect . '&deleted=1');
exit;
