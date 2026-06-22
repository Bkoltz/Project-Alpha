<?php
// src/controllers/time-tracking/time_entry_create.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('time-tracking/create');

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId === 0) { http_response_code(401); exit; }

$clientId   = (int)($_POST['client_id'] ?? 0) ?: null;
$projectId  = (int)($_POST['project_id'] ?? 0) ?: null;
$description = trim((string)($_POST['description'] ?? ''));
$entryDate   = trim((string)($_POST['entry_date'] ?? ''));
$hours       = (float)($_POST['hours'] ?? 0);
$rate        = (float)($_POST['rate'] ?? 0);
$billable    = !empty($_POST['billable']) ? 1 : 0;

if ($description === '' || $hours <= 0 || !$entryDate) {
    header('Location: /?page=time-tracking&error=Invalid%20time%20entry');
    exit;
}

$startedAt = $entryDate . ' 00:00:00';
$endedAt   = $entryDate . ' 23:59:59';

$stmt = $pdo->prepare('INSERT INTO time_entries (user_id, client_id, project_id, description, started_at, ended_at, hours, billable, rate) VALUES (?,?,?,?,?,?,?,?,?)');
$stmt->execute([$userId, $clientId, $projectId, $description, $startedAt, $endedAt, $hours, $billable, $rate]);

header('Location: /?page=time-tracking&created=1');
exit;
