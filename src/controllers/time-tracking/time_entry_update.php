<?php
// src/controllers/time-tracking/time_entry_update.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('time-tracking/update');

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId === 0) { http_response_code(401); exit; }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=time-tracking&error=Invalid%20entry');
    exit;
}

$clientId    = (int)($_POST['client_id'] ?? 0) ?: null;
$projectId   = (int)($_POST['project_id'] ?? 0) ?: null;
$description = trim((string)($_POST['description'] ?? ''));
$entryDate   = trim((string)($_POST['entry_date'] ?? ''));
$hours       = (float)($_POST['hours'] ?? 0);
$rate        = (float)($_POST['rate'] ?? 0);
$billable    = !empty($_POST['billable']) ? 1 : 0;

if ($description === '' || $hours <= 0 || !$entryDate) {
    header('Location: /?page=time-tracking&error=Invalid%20time%20entry');
    exit;
}

// Ensure user owns the entry and it is not already billed
$owner = $pdo->prepare('SELECT user_id, billed FROM time_entries WHERE id = ?');
$owner->execute([$id]);
$row = $owner->fetch(PDO::FETCH_ASSOC);
if (!$row || (int)$row['user_id'] !== $userId || (int)$row['billed'] === 1) {
    header('Location: /?page=time-tracking&error=Not%20allowed');
    exit;
}

$startedAt = $entryDate . ' 00:00:00';
$endedAt   = $entryDate . ' 23:59:59';

$stmt = $pdo->prepare('UPDATE time_entries SET client_id=?, project_id=?, description=?, started_at=?, ended_at=?, hours=?, billable=?, rate=? WHERE id=? AND user_id=? AND billed=0');
$stmt->execute([$clientId, $projectId, $description, $startedAt, $endedAt, $hours, $billable, $rate, $id, $userId]);

header('Location: /?page=time-tracking&created=1');
exit;
