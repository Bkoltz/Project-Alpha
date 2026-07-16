<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/worker_documents.php';

$actorId = (int)($_SESSION['user']['id'] ?? 0);
if ($actorId <= 0) {
    http_response_code(401);
    exit('Authentication required.');
}

$documentId = max(0, (int)($_GET['id'] ?? 0));
$statement = $pdo->prepare('SELECT * FROM worker_documents WHERE id=? LIMIT 1');
$statement->execute([$documentId]);
$document = $statement->fetch(PDO::FETCH_ASSOC);
$canManage = user_can($pdo, $actorId, 'users.manage');
$canViewOwn = $document && (int)($document['user_id'] ?? 0) === $actorId && !empty($document['worker_visible']);
if (!$document || (!$canManage && !$canViewOwn)) {
    http_response_code(404);
    exit('Worker document not found.');
}

$path = worker_document_absolute_path((string)$document['file_path']);
if ($path === null) {
    http_response_code(404);
    exit('Worker document not found.');
}

$download = (string)($_GET['download'] ?? '') === '1';
$fileName = basename((string)($document['original_name'] ?: $document['title']));
header('Content-Type: ' . (string)$document['mime_type']);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', $fileName) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
exit;
