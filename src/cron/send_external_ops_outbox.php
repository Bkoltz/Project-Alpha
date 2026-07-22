<?php

declare(strict_types=1);

use App\Services\ExternalOpsOutboxSender;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/external_ops.php';
require_once __DIR__ . '/../utils/cron_state.php';

$jobName = 'external_ops_outbox';
$config = pa_external_ops_delivery_config($pdo);

if (empty($config['enabled'])) {
    cron_state_mark_success($pdo, $jobName, 'External operations integration disabled');
    exit(0);
}

try {
    $summary = (new ExternalOpsOutboxSender())->deliverDue($pdo, $config);
    $message = sprintf(
        'Processed %d; delivered %d; failed %d',
        $summary['processed'],
        $summary['delivered'],
        $summary['failed']
    );
    if ($summary['failed'] > 0) {
        throw new RuntimeException($message);
    }
    cron_state_mark_success($pdo, $jobName, $message);
    error_log('[external_ops_outbox] ' . $message);
    exit(0);
} catch (Throwable $error) {
    error_log('[external_ops_outbox] Failed: ' . $error->getMessage());
    cron_state_mark_failure($pdo, $jobName, $error);
    exit(1);
}
