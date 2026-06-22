<?php
// src/controllers/time-tracking/time_entry_stop_timer.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('time-tracking/stop-timer');

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId === 0) { http_response_code(401); exit; }

$stmt = $pdo->prepare('SELECT id, started_at FROM time_entries WHERE user_id = ? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1');
$stmt->execute([$userId]);
$timer = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$timer) {
    header('Location: /?page=time-tracking&error=No%20active%20timer');
    exit;
}

$started = strtotime($timer['started_at']);
$now = time();
$hours = max(0, round(($now - $started) / 3600, 2));
$endedAt = date('Y-m-d H:i:s', $now);

$rate = (float)($_POST['rate'] ?? 0);
$description = trim((string)($_POST['description'] ?? ''));
$clientId    = (int)($_POST['client_id'] ?? 0) ?: null;
$projectId   = (int)($_POST['project_id'] ?? 0) ?: null;

if ($description !== '') {
    $upd = $pdo->prepare('UPDATE time_entries SET ended_at=?, hours=?, billable=1, rate=?, description=?, client_id=?, project_id=? WHERE id=?');
    $upd->execute([$endedAt, $hours, $rate, $description, $clientId, $projectId, $timer['id']]);
} else {
    $upd = $pdo->prepare('UPDATE time_entries SET ended_at=?, hours=?, billable=1, rate=? WHERE id=?');
    $upd->execute([$endedAt, $hours, $rate, $timer['id']]);
}

header('Location: /?page=time-tracking&created=1');
exit;
