<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/alphaledger_integration.php';
require_once __DIR__ . '/../utils/cron_state.php';

$jobName = 'sync_alphaledger';
try {
    if (!pa_al_policy_enabled($pdo)) {
        cron_state_mark_success($pdo, $jobName, 'AlphaLedger synchronization is disabled by PA policy.');
        exit(0);
    }
    $installations = $pdo->query("SELECT * FROM alphaledger_installations WHERE status IN ('active','degraded')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($installations as $installation) {
        pa_al_capture_owned_state($pdo, $installation);
    }
    $result = pa_al_deliver_pending($pdo, 100);
    $pdo->exec('DELETE FROM alphaledger_idempotency WHERE expires_at<UTC_TIMESTAMP()');
    cron_state_mark_success($pdo, $jobName, sprintf('Captured %d installation(s); delivered %d event(s), %d failed.', count($installations), $result['delivered'], $result['failed']));
} catch (Throwable $e) {
    cron_state_mark_failure($pdo, $jobName, $e);
    @error_log('[AlphaLedgerSync] ' . $e->getMessage());
    exit(1);
}
