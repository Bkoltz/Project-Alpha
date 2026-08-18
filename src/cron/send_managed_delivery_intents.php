<?php

declare(strict_types=1);

use App\Services\ManagedDeliveryIntentSender;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/cron_state.php';

$jobName = 'managed_delivery_intents';
try {
    $summary = (new ManagedDeliveryIntentSender())->deliverDue($pdo, 50, null, 50);
    $message = sprintf('Processed %d; accepted %d; retrying %d; dead-lettered %d', $summary['processed'], $summary['accepted'], $summary['retrying'], $summary['dead_lettered']);
    if ($summary['dead_lettered'] > 0) throw new RuntimeException($message);
    cron_state_mark_success($pdo, $jobName, $message);
    error_log('[managed_delivery_intents] ' . $message);
    exit(0);
} catch (Throwable $error) {
    $code = substr(hash('sha256', get_class($error) . ':' . $error->getMessage()), 0, 12);
    error_log('[managed_delivery_intents] failed code=' . $code);
    cron_state_mark_failure($pdo, $jobName, new RuntimeException('Managed delivery failed; diagnostic code ' . $code));
    exit(1);
}
