<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/NotificationRelayPolicy.php';

// Keep the default-off path side-effect free: no DB connection, state row, or log churn.
if (!NotificationRelayPolicy::isEnabled()) {
    exit(0);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/../services/NotificationRelayQueue.php';
require_once __DIR__ . '/../services/NotificationRelayWorker.php';
require_once __DIR__ . '/../utils/cron_logger.php';
require_once __DIR__ . '/../utils/cron_state.php';

$jobName = 'process_notification_relay';
$stats = ['claimed' => 0, 'sent' => 0, 'retry' => 0, 'failed' => 0, 'lost_lease' => 0];
cron_log_start($jobName);

try {
    $policy = NotificationRelayPolicy::load();
    $queue = new NotificationRelayQueue($pdo);
    $worker = new NotificationRelayWorker($queue);
    $batchSize = (int)$policy['limits']['worker_batch_size'];
    $leaseSeconds = (int)$policy['limits']['lease_seconds'];

    for ($index = 0; $index < $batchSize; $index++) {
        $job = $queue->claimNext($leaseSeconds, (int)$policy['limits']['max_attempts']);
        if ($job === null) {
            break;
        }
        $stats['claimed']++;
        $outcome = $worker->process($job, $policy, [EmailService::class, 'sendEmail']);
        if (isset($stats[$outcome])) {
            $stats[$outcome]++;
        }
    }
    $queue->cleanupRateBuckets();
    $queue->cleanupRetainedData(
        (int)$policy['limits']['payload_retention_days'],
        (int)$policy['limits']['event_retention_days']
    );
    cron_state_mark_success($pdo, $jobName, json_encode($stats, JSON_UNESCAPED_SLASHES));
    cron_log_end($jobName, $stats);
} catch (Throwable $error) {
    cron_state_mark_failure($pdo, $jobName, $error);
    cron_log_error($jobName, 'Worker failed', ['error_code' => 'internal_error']);
    exit(1);
}
