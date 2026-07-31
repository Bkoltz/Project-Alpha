<?php
// Schedule and deliver durable invoice reminders for every direct invoice type.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/cron_state.php';
require_once __DIR__ . '/../utils/invoice_notifications.php';
require_once __DIR__ . '/../utils/project_invoice_notifications.php';

$logPrefix = '[send_invoice_reminders]';
$jobName = 'send_invoice_reminders';

if (empty($appConfig['cron_enabled'])) {
    @error_log("$logPrefix Cron is disabled in settings. Skipping.");
    cron_state_mark_success($pdo, $jobName, 'Cron disabled');
    exit(0);
}
if (empty($appConfig['invoice_auto_send_due_7days'])
    && empty($appConfig['invoice_auto_send_overdue_weekly'])) {
    @error_log("$logPrefix Reminder settings are disabled. Skipping.");
    cron_state_mark_success($pdo, $jobName, 'Reminder settings disabled');
    exit(0);
}

try {
    $today = new DateTimeImmutable('today', invoice_notification_timezone($appConfig));
    @error_log("$logPrefix Starting reminder run for " . $today->format('Y-m-d'));
    $scheduled = invoice_notification_schedule_reminders($pdo, $appConfig, $today);
    $projectScheduled = project_invoice_notification_schedule_reminders($pdo, $appConfig, $today);
    $delivered = invoice_notification_process($pdo, $appConfig);
    $projectDelivered = project_invoice_notification_process($pdo, $appConfig);
    $result = sprintf(
        '%d queued; %d sent; %d retrying; %d suppressed (%d invalid-recipient candidates)',
        $scheduled['queued'] + $projectScheduled['queued'],
        $delivered['sent'] + $projectDelivered['sent'],
        $delivered['retry'] + $projectDelivered['retry'],
        $delivered['suppressed'] + $projectDelivered['suppressed'],
        $scheduled['suppressed'] + $projectScheduled['suppressed']
    );
    @error_log("$logPrefix Completed: $result");
    cron_state_mark_success($pdo, $jobName, $result);
} catch (Throwable $error) {
    @error_log("$logPrefix Fatal error: " . $error->getMessage());
    cron_state_mark_failure($pdo, $jobName, $error);
    exit(1);
}

exit(0);
