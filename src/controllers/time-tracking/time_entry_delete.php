<?php
// src/controllers/time-tracking/time_entry_delete.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/time_tracking_schema.php';
require_once __DIR__ . '/../../utils/alphaledger_integration.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify_post_or_redirect('time-tracking');
pa_al_block_local_time_mutation_when_enabled($pdo);
try {
    pa_time_tracking_ensure_schema($pdo);
} catch (Throwable $e) {
    @error_log('[TimeTrackingDelete] Schema repair failed: ' . $e->getMessage());
    header('Location: /?page=time-tracking&error=' . urlencode('Time tracking storage is not ready. Run migrations and try again.'));
    exit;
}

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId === 0) { http_response_code(401); exit; }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=time-tracking&error=Invalid%20entry');
    exit;
}

$stmt = $pdo->prepare('DELETE FROM time_entries WHERE id = ? AND user_id = ? AND billed = 0 AND COALESCE(source_system,"")<>"alphaledger"');
$stmt->execute([$id, $userId]);

if ($stmt->rowCount() !== 1) {
    header('Location: /?page=time-tracking&error=' . urlencode('AlphaLedger-owned or billed time entries cannot be deleted in PA.'));
    exit;
}

header('Location: /?page=time-tracking&deleted=1');
exit;
