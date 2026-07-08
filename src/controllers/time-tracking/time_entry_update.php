<?php
// src/controllers/time-tracking/time_entry_update.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/time_tracking_schema.php';

function time_tracking_update_error(string $message): void
{
    header('Location: /?page=time-tracking&error=' . urlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('time-tracking');
try {
    pa_time_tracking_ensure_schema($pdo);
} catch (Throwable $e) {
    @error_log('[TimeTrackingUpdate] Schema repair failed: ' . $e->getMessage());
    time_tracking_update_error('Time tracking storage is not ready. Run migrations and try again.');
}

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId === 0) { http_response_code(401); exit; }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    time_tracking_update_error('Invalid entry');
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
    time_tracking_update_error('Invalid time entry');
}

$hasStart = $startTime !== '';
$hasEnd = $endTime !== '';
$hasStartEnd = $hasStart && $hasEnd;
$hasManualHours = $hours > 0;
if ($hasStart !== $hasEnd) {
    time_tracking_update_error('Enter both start and end time, or use manual hours.');
}
if ($hasStartEnd && $hasManualHours) {
    time_tracking_update_error('Use either start/end times or manual hours, not both.');
}
if (!$hasStartEnd && !$hasManualHours) {
    time_tracking_update_error('Enter start/end times or manual hours greater than 0.');
}

// Ensure user owns the entry and it is not already billed
$owner = $pdo->prepare('SELECT user_id, billed FROM time_entries WHERE id = ?');
$owner->execute([$id]);
$row = $owner->fetch(PDO::FETCH_ASSOC);
if (!$row || (int)$row['user_id'] !== $userId || (int)$row['billed'] === 1) {
    time_tracking_update_error('Not allowed');
}

if ($invoiceId) {
    $doc = $pdo->prepare('SELECT client_id, contract_id, project_id, project_code FROM invoices WHERE id = ? AND billing_mode = "hourly" AND status NOT IN ("paid", "cancelled", "void")');
    $doc->execute([$invoiceId]);
    $row = $doc->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        time_tracking_update_error('Selected invoice is not an open hourly invoice.');
    }
    $invoiceClientId = (int)$row['client_id'];
    if ($clientId && $invoiceClientId !== $clientId) {
        time_tracking_update_error('Selected invoice does not belong to the selected client.');
    }
    if ($contractId && (int)($row['contract_id'] ?? 0) > 0 && (int)$row['contract_id'] !== $contractId) {
        time_tracking_update_error('Selected invoice does not belong to the selected contract.');
    }
    $clientId = $clientId ?: $invoiceClientId;
    $projectId = $projectId ?: ((int)($row['project_id'] ?? 0) ?: null);
    $projectCode = $projectCode ?: ($row['project_code'] ?? null);
}

if ($contractId) {
    $doc = $pdo->prepare('SELECT client_id, project_id, project_code FROM contracts WHERE id = ? AND billing_mode = "hourly" AND status = "active"');
    $doc->execute([$contractId]);
    $row = $doc->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        time_tracking_update_error('Selected contract is not an active hourly contract.');
    }
    $contractClientId = (int)$row['client_id'];
    if ($clientId && $contractClientId !== $clientId) {
        time_tracking_update_error('Selected contract does not belong to the selected client.');
    }
    $clientId = $clientId ?: $contractClientId;
    $projectId = $projectId ?: ((int)($row['project_id'] ?? 0) ?: null);
    $projectCode = $projectCode ?: ($row['project_code'] ?? null);
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
        time_tracking_update_error('End time must be after start time');
    }
    $hours = round(($end - $start) / 3600, 2);
    $startedAt = date('Y-m-d H:i:s', $start);
    $endedAt = date('Y-m-d H:i:s', $end);
}
if ($hours <= 0) {
    time_tracking_update_error('Hours must be greater than 0');
}

try {
    $stmt = $pdo->prepare('UPDATE time_entries SET client_id=?, project_id=?, project_code=?, contract_id=?, invoice_id=?, invoice_item_id=NULL, service_item_id=?, description=?, started_at=?, ended_at=?, hours=?, billable=?, rate=? WHERE id=? AND user_id=? AND billed=0');
    $stmt->execute([$clientId, $projectId, $projectCode, $contractId, $invoiceId, $serviceItemId, $description, $startedAt, $endedAt, $hours, $billable, $rate, $id, $userId]);
} catch (Throwable $e) {
    @error_log('[TimeTrackingUpdate] Failed to save time entry: ' . $e->getMessage());
    time_tracking_update_error('Failed to save time entry.');
}

header('Location: /?page=time-tracking&created=1');
exit;
