<?php
// src/controllers/time-tracking/time_entry_delete.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('time-tracking/delete');

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId === 0) { http_response_code(401); exit; }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=time-tracking&error=Invalid%20entry');
    exit;
}

$stmt = $pdo->prepare('DELETE FROM time_entries WHERE id = ? AND user_id = ? AND billed = 0');
$stmt->execute([$id, $userId]);

header('Location: /?page=time-tracking&deleted=1');
exit;
