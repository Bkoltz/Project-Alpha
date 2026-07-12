<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/cron_state.php';
require_once __DIR__ . '/../utils/recurring_expenses.php';

$jobName = 'generate_recurring_expenses';
$logPrefix = '[generate_recurring_expenses]';

if (empty($appConfig['cron_enabled'])) {
    @error_log($logPrefix . ' Cron is disabled.');
    cron_state_mark_success($pdo, $jobName, 'Cron disabled');
    exit(0);
}

try {
    $today = date('Y-m-d');
    $result = recurring_expense_process_due($pdo, $today, 1000);
    $summary = sprintf(
        'Generated %d expense(s); %d error(s); %d attempt(s)',
        $result['generated'],
        $result['errors'],
        $result['attempts']
    );
    @error_log($logPrefix . ' ' . $summary);
    if ($result['errors'] > 0) {
        throw new RuntimeException($summary);
    }
    cron_state_mark_success($pdo, $jobName, $summary);
} catch (Throwable $e) {
    @error_log($logPrefix . ' Failed: ' . $e->getMessage());
    cron_state_mark_failure($pdo, $jobName, $e);
    exit(1);
}
