<?php
// src/controllers/time-tracking/time_entry_stop_timer.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/time_tracking_schema.php';
require_once __DIR__ . '/../../utils/alphaledger_integration.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('time-tracking');
try {
    pa_time_tracking_ensure_schema($pdo);
} catch (Throwable $e) {
    @error_log('[TimeTrackingStop] Schema repair failed: ' . $e->getMessage());
    header('Location: /?page=time-tracking&error=' . urlencode('Time tracking storage is not ready. Run migrations and try again.'));
    exit;
}

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId === 0) { http_response_code(401); exit; }

if (pa_al_policy_enabled($pdo)) {
    require_once __DIR__ . '/../../utils/alphaledger_time_bridge.php';
    $context=pa_al_time_admin_context($pdo,$userId);
    if(!$context){ header('Location: /?page=time-tracking&error='.rawurlencode('Your PA administrator account is not mapped to an AlphaLedger employee or time commands are unavailable.')); exit; }
    try {
        $endedAt=gmdate('Y-m-d H:i:s'); $pending=pa_al_time_pending_start($pdo,$userId);
        if($pending&&pa_al_time_coalesce_stop($pdo,$pending,$endedAt)){
            $delivery=pa_al_time_deliver_commands($pdo,1); header('Location: /?page=time-tracking&'.($delivery['delivered']?'created=1':'pending=1')); exit;
        }
        $running=$pdo->prepare("SELECT external_id,start_time FROM alphaledger_ledger_time_entries WHERE installation_id=? AND employee_external_id=? AND status='running' AND deleted_at IS NULL ORDER BY start_time DESC LIMIT 1");
        $running->execute([(int)$context['installation']['id'],(string)$context['al_employee_id']]); $entry=$running->fetch(PDO::FETCH_ASSOC);
        if(!$entry&&$pending&&!empty($pending['al_entry_id']))$entry=['external_id'=>(string)$pending['al_entry_id'],'start_time'=>$pending['started_at']];
        if(!$entry) throw new DomainException('No active AlphaLedger timer was found.');
        pa_al_time_queue_command($pdo,$context,'stop',['entry_id'=>(string)$entry['external_id'],'end_time'=>gmdate('c',strtotime($endedAt))],null,$endedAt,(string)$entry['external_id']);
        $delivery=pa_al_time_deliver_commands($pdo,1); header('Location: /?page=time-tracking&'.($delivery['delivered']?'created=1':'pending=1')); exit;
    } catch(Throwable $e){ header('Location: /?page=time-tracking&error='.rawurlencode($e->getMessage())); exit; }
}

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
