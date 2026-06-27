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
$projectCode = trim((string)($_POST['project_code'] ?? '')) ?: null;
$contractId = (int)($_POST['contract_id'] ?? 0) ?: null;
$invoiceId = (int)($_POST['invoice_id'] ?? 0) ?: null;
$serviceItemId = (int)($_POST['service_item_id'] ?? 0) ?: null;
$description = trim((string)($_POST['description'] ?? ''));
$entryDate   = trim((string)($_POST['entry_date'] ?? ''));
$hours       = (float)($_POST['hours'] ?? 0);
$rate        = (float)($_POST['rate'] ?? 0);
$billable    = !empty($_POST['billable']) ? 1 : 0;
$startTime = trim((string)($_POST['start_time'] ?? ''));
$endTime = trim((string)($_POST['end_time'] ?? ''));

if ($description === '' || !$entryDate) {
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

if ($serviceItemId && $rate <= 0) {
    $svc = $pdo->prepare('SELECT unit_price FROM item_library WHERE id = ? AND is_active = 1');
    $svc->execute([$serviceItemId]);
    $rate = (float)($svc->fetchColumn() ?: $rate);
}

$startedAt = $entryDate . ' 00:00:00';
$endedAt   = $entryDate . ' 23:59:59';
if ($startTime !== '' && $endTime !== '') {
    $start = strtotime($entryDate . ' ' . $startTime);
    $end = strtotime($entryDate . ' ' . $endTime);
    if (!$start || !$end || $end <= $start) {
        header('Location: /?page=time-tracking&error=End%20time%20must%20be%20after%20start%20time');
        exit;
    }
    $hours = round(($end - $start) / 3600, 2);
    $startedAt = date('Y-m-d H:i:s', $start);
    $endedAt = date('Y-m-d H:i:s', $end);
}
if ($hours <= 0) {
    header('Location: /?page=time-tracking&error=Hours%20must%20be%20greater%20than%200');
    exit;
}

$stmt = $pdo->prepare('UPDATE time_entries SET client_id=?, project_id=?, project_code=?, contract_id=?, invoice_id=?, service_item_id=?, description=?, started_at=?, ended_at=?, hours=?, billable=?, rate=? WHERE id=? AND user_id=? AND billed=0');
$stmt->execute([$clientId, $projectId, $projectCode, $contractId, $invoiceId, $serviceItemId, $description, $startedAt, $endedAt, $hours, $billable, $rate, $id, $userId]);

header('Location: /?page=time-tracking&created=1');
exit;
