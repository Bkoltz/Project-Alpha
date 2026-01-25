<?php
// src/controllers/project/projects_update_status.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (!csrf_validate()) {
    http_response_code(403);
    exit('CSRF token invalid');
}

$id = (int)($_POST['project_id'] ?? 0);
$status = $_POST['status'] ?? '';

if (!$id) {
    http_response_code(400);
    exit('Project ID is required');
}

$validStatuses = ['not_started', 'active', 'overdue', 'completed', 'cancelled'];
if (!in_array($status, $validStatuses)) {
    http_response_code(400);
    exit('Invalid status');
}

$st = $pdo->prepare('UPDATE projects SET status=?, updated_at=NOW() WHERE id=?');
$st->execute([$status, $id]);

$redirect = $_POST['redirect'] ?? '/?page=project/projects-details&id=' . $id;
header('Location: ' . $redirect);
exit;
?>