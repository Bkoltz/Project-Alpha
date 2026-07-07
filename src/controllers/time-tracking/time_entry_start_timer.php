<?php
// src/controllers/time-tracking/time_entry_start_timer.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/time_tracking_schema.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('time-tracking');
try {
    pa_time_tracking_ensure_schema($pdo);
} catch (Throwable $e) {
    @error_log('[TimeTrackingStart] Schema repair failed: ' . $e->getMessage());
    header('Location: /?page=time-tracking&error=' . urlencode('Time tracking storage is not ready. Run migrations and try again.'));
    exit;
}

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId === 0) { http_response_code(401); exit; }

$description = trim((string)($_POST['description'] ?? ''));
$clientId    = (int)($_POST['client_id'] ?? 0) ?: null;
$projectId   = (int)($_POST['project_id'] ?? 0) ?: null;

// Stop any existing active timer first for this user
$existing = $pdo->prepare('SELECT id, started_at FROM time_entries WHERE user_id = ? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1');
$existing->execute([$userId]);
$timer = $existing->fetch(PDO::FETCH_ASSOC);
if ($timer) {
    $started = strtotime($timer['started_at']);
    $now = time();
    $hours = max(0, round(($now - $started) / 3600, 2));
    $endedAt = date('Y-m-d H:i:s', $now);
    $upd = $pdo->prepare('UPDATE time_entries SET ended_at=?, hours=?, billable=1 WHERE id=?');
    $upd->execute([$endedAt, $hours, $timer['id']]);
}

$stmt = $pdo->prepare('INSERT INTO time_entries (user_id, client_id, project_id, description, started_at, hours, billable, rate) VALUES (?,?,?,?,NOW(),0,1,?)');
$rate = (float)($_POST['rate'] ?? 0);
$stmt->execute([$userId, $clientId, $projectId, $description, $rate]);

header('Location: /?page=time-tracking&created=1');
exit;
