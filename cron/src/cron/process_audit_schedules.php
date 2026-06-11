<?php
// src/cron/process_audit_schedules.php
// Run daily via cron to check for due audit schedules, generate reports, and email them
// Usage: php /var/www/src/cron/process_audit_schedules.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/mailer.php';
require_once __DIR__ . '/../utils/crypto.php';

$logPrefix = '[process_audit_schedules]';

@error_log("$logPrefix Starting audit schedule check at " . date('Y-m-d H:i:s'));

// Build mailer config
$smtpPass = '';
if (!empty($appConfig['smtp_password_enc']) && is_string($appConfig['smtp_password_enc'])) {
    $encVal = $appConfig['smtp_password_enc'];
    if (strpos($encVal, 'plain::') === 0) { $smtpPass = substr($encVal, 7); }
    else { $pt = crypto_decrypt($encVal); if (is_string($pt)) { $smtpPass = $pt; } }
}
$mailCfg = [
    'host' => (string)($appConfig['smtp_host'] ?? ''),
    'port' => (int)($appConfig['smtp_port'] ?? 587),
    'secure' => strtolower((string)($appConfig['smtp_secure'] ?? 'tls')),
    'username' => (string)($appConfig['smtp_username'] ?? ''),
    'password' => $smtpPass,
];
$fromEmail = (string)($appConfig['from_email'] ?? 'no-reply@localhost');
$fromName = (string)($appConfig['from_name'] ?? ($appConfig['brand_name'] ?? 'Project Alpha'));

$processed = 0;
$errors = 0;

try {
    // Find all active schedules that are due
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare('
        SELECT * FROM audit_schedules 
        WHERE is_active = 1 AND next_run_at IS NOT NULL AND next_run_at <= ?
        ORDER BY next_run_at ASC
    ');
    $stmt->execute([$now]);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($schedules)) {
        @error_log("$logPrefix No schedules due. Exiting.");
        exit(0);
    }

    @error_log("$logPrefix Found " . count($schedules) . " schedule(s) to process.");

    foreach ($schedules as $schedule) {
        try {
            $schedId = (int)$schedule['id'];

            // Calculate date range based on schedule settings
            [$startDate, $endDate] = calculateDateRange($schedule['date_range_type']);

            $includeInvoices = (bool)$schedule['include_invoices'];
            $includeUnpaidInvoices = (bool)$schedule['include_unpaid_invoices'];
            $includeContracts = (bool)$schedule['include_contracts'];
            $includeQuotes = (bool)$schedule['include_quotes'];

            // Fetch invoices
            $invoices = [];
            if ($includeInvoices) {
                $statusFilter = $includeUnpaidInvoices
                    ? "i.status IN ('paid', 'partial', 'unpaid')"
                    : "i.status IN ('paid', 'partial')";

                $stmt = $pdo->prepare("
                    SELECT 
                        i.id, i.doc_number, c.name as client_name, i.project_code,
                        i.subtotal, i.tax_percent, i.tax_amount as tax, i.tax_county,
                        i.discount_value, i.total, i.status, i.created_at, i.due_date,
                        COALESCE(SUM(CASE WHEN p.status = 'succeeded' THEN p.amount ELSE 0 END), 0) as amount_paid,
                        GROUP_CONCAT(DISTINCT p.payment_method SEPARATOR ', ') as payment_methods
                    FROM invoices i
                    LEFT JOIN clients c ON i.client_id = c.id
                    LEFT JOIN payments p ON i.id = p.invoice_id
                    WHERE i.created_at BETWEEN ? AND ? AND {$statusFilter}
                    GROUP BY i.id
                    ORDER BY i.created_at ASC
                ");
                $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Fetch contracts
            $contracts = [];
            if ($includeContracts) {
                $stmt = $pdo->prepare("
                    SELECT c.id, c.doc_number, cl.name as client_name, c.project_code,
                           c.contract_type, c.total, c.status, c.created_at, c.start_date, c.end_date
                    FROM contracts c LEFT JOIN clients cl ON c.client_id = cl.id
                    WHERE c.created_at BETWEEN ? AND ?
                    ORDER BY c.created_at ASC
                ");
                $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Fetch quotes
            $quotes = [];
            if ($includeQuotes) {
                $stmt = $pdo->prepare("
                    SELECT q.id, q.doc_number, cl.name as client_name, q.project_code,
                           q.quote_type, q.total, q.status, q.created_at
                    FROM quotes q LEFT JOIN clients cl ON q.client_id = cl.id
                    WHERE q.created_at BETWEEN ? AND ?
                    ORDER BY q.created_at ASC
                ");
                $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                $quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Skip if no data
            if (empty($invoices) && empty($contracts) && empty($quotes)) {
                @error_log("$logPrefix Schedule #{$schedId}: No data for period {$startDate} to {$endDate}. Advancing.");
                advanceSchedule($pdo, $schedule);
                continue;
            }

            // Generate CSV
            $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'audit_sched_' . $schedId . '_' . uniqid();
            @mkdir($tmpDir, 0755, true);

            $csvFile = $tmpDir . DIRECTORY_SEPARATOR . 'audit_report.csv';
            $fp = fopen($csvFile, 'w');
            fwrite($fp, "\xEF\xBB\xBF"); // BOM
            fputcsv($fp, ['Date', 'Client', 'Doc Number', 'Document Type', 'Status', 'Tax %', 'Tax County', 'Amount Paid', 'Payment Method', 'Discount', 'Total', 'Running Total']);

            $runningTotal = 0;
            foreach ($invoices as $inv) {
                $amountPaid = (float)($inv['amount_paid'] ?? 0);
                $runningTotal += $amountPaid;
                fputcsv($fp, [
                    substr($inv['created_at'] ?? '', 0, 10),
                    $inv['client_name'] ?? '',
                    $inv['doc_number'] ?? $inv['id'],
                    'Invoice',
                    ucfirst($inv['status'] ?? ''),
                    number_format((float)($inv['tax_percent'] ?? 0), 2) . '%',
                    $inv['tax_county'] ?? '',
                    '$' . number_format($amountPaid, 2),
                    $inv['payment_methods'] ?? '',
                    '$' . number_format((float)($inv['discount_value'] ?? 0), 2),
                    '$' . number_format((float)($inv['total'] ?? 0), 2),
                    '$' . number_format($runningTotal, 2)
                ]);
            }
            foreach ($contracts as $c) {
                fputcsv($fp, [
                    substr($c['created_at'] ?? '', 0, 10), $c['client_name'] ?? '',
                    $c['doc_number'] ?? $c['id'], 'Contract (' . ($c['contract_type'] ?? 'regular') . ')',
                    ucfirst($c['status'] ?? ''), '', '', '', '', '',
                    '$' . number_format((float)($c['total'] ?? 0), 2), ''
                ]);
            }
            foreach ($quotes as $q) {
                fputcsv($fp, [
                    substr($q['created_at'] ?? '', 0, 10), $q['client_name'] ?? '',
                    $q['doc_number'] ?? $q['id'], 'Quote (' . ($q['quote_type'] ?? 'regular') . ')',
                    ucfirst($q['status'] ?? ''), '', '', '', '', '',
                    '$' . number_format((float)($q['total'] ?? 0), 2), ''
                ]);
            }

            // Summary
            fputcsv($fp, []);
            fputcsv($fp, ['SUMMARY']);
            fputcsv($fp, ['Period:', $startDate . ' to ' . $endDate]);
            fputcsv($fp, ['Total Invoices:', count($invoices), '', '', '', '', '', 'Total Collected:', '$' . number_format($runningTotal, 2)]);
            if ($includeContracts) fputcsv($fp, ['Total Contracts:', count($contracts)]);
            if ($includeQuotes) fputcsv($fp, ['Total Quotes:', count($quotes)]);
            fclose($fp);

            // Email the CSV to all recipients
            $emails = json_decode($schedule['email_addresses'], true);
            if (!is_array($emails)) $emails = [];

            $subject = ucfirst($schedule['frequency']) . ' Audit Report — ' . $startDate . ' to ' . $endDate;
            $body = '<h2>Financial Audit Report</h2>';
            $body .= '<p><strong>Period:</strong> ' . $startDate . ' to ' . $endDate . '</p>';
            $body .= '<p><strong>Invoices:</strong> ' . count($invoices) . ' | <strong>Total Collected:</strong> $' . number_format($runningTotal, 2) . '</p>';
            if ($includeContracts) $body .= '<p><strong>Contracts:</strong> ' . count($contracts) . '</p>';
            if ($includeQuotes) $body .= '<p><strong>Quotes:</strong> ' . count($quotes) . '</p>';
            $body .= '<p>The full CSV report is attached to this email.</p>';

            $csvContent = file_get_contents($csvFile);
            $attachmentName = 'audit_' . str_replace('-', '', $startDate) . '-' . str_replace('-', '', $endDate) . '.csv';
            $attachments = [['filename' => $attachmentName, 'content' => $csvContent, 'mime' => 'text/csv']];

            $sentCount = 0;
            foreach ($emails as $email) {
                $email = trim($email);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                try {
                    [$ok, $err] = mailer_send($mailCfg, $email, $subject, $body, $fromEmail, $fromName, ($mailCfg['username'] ?: $fromEmail), $attachments);
                    if ($ok) { $sentCount++; }
                    else { @error_log("$logPrefix Failed to email {$email}: {$err}"); }
                } catch (Throwable $e) {
                    @error_log("$logPrefix Email error for {$email}: " . $e->getMessage());
                }
            }

            // Cleanup temp
            @unlink($csvFile);
            @rmdir($tmpDir);

            // Log the run
            try {
                $pdo->prepare('INSERT INTO audit_schedule_logs (schedule_id, status, started_at, completed_at, result_summary) VALUES (?, ?, ?, NOW(), ?)')
                    ->execute([$schedId, 'completed', $now, "Sent to {$sentCount}/" . count($emails) . " recipients. Invoices: " . count($invoices) . ", Collected: \${$runningTotal}"]);
            } catch (Throwable $e) { /* ignore logging failures */ }

            // Advance to next run
            advanceSchedule($pdo, $schedule);
            $processed++;

            @error_log("$logPrefix Schedule #{$schedId}: Processed. Emailed {$sentCount} recipients.");

        } catch (Throwable $e) {
            $errors++;
            @error_log("$logPrefix Error processing schedule #{$schedule['id']}: " . $e->getMessage());
            try {
                $pdo->prepare('INSERT INTO audit_schedule_logs (schedule_id, status, started_at, completed_at, error_message) VALUES (?, ?, ?, NOW(), ?)')
                    ->execute([$schedule['id'], 'failed', $now, substr($e->getMessage(), 0, 500)]);
            } catch (Throwable $e2) { /* ignore */ }
            // Still advance so we don't get stuck
            advanceSchedule($pdo, $schedule);
        }
    }

    @error_log("$logPrefix Completed: {$processed} processed, {$errors} errors");

} catch (Throwable $e) {
    @error_log("$logPrefix Fatal error: " . $e->getMessage());
    exit(1);
}

exit(0);

/**
 * Calculate date range based on schedule type
 */
function calculateDateRange(string $type): array {
    $now = new DateTime();
    switch ($type) {
        case 'last_week':
            $end = (clone $now)->modify('last sunday');
            $start = (clone $end)->modify('-6 days');
            break;
        case 'last_month':
            $start = (clone $now)->modify('first day of last month')->setTime(0, 0);
            $end = (clone $now)->modify('last day of last month')->setTime(23, 59, 59);
            break;
        case 'last_quarter':
            $currentMonth = (int)$now->format('n');
            $currentQuarter = (int)ceil($currentMonth / 3);
            $prevQuarter = $currentQuarter - 1;
            $prevYear = (int)$now->format('Y');
            if ($prevQuarter <= 0) { $prevQuarter = 4; $prevYear--; }
            $startMonth = ($prevQuarter - 1) * 3 + 1;
            $endMonth = $prevQuarter * 3;
            $start = new DateTime("{$prevYear}-" . str_pad($startMonth, 2, '0', STR_PAD_LEFT) . "-01");
            $end = new DateTime("{$prevYear}-" . str_pad($endMonth, 2, '0', STR_PAD_LEFT) . "-01");
            $end->modify('last day of this month');
            break;
        case 'last_year':
            $lastYear = (int)$now->format('Y') - 1;
            $start = new DateTime("{$lastYear}-01-01");
            $end = new DateTime("{$lastYear}-12-31");
            break;
        case 'current_year':
            $start = new DateTime($now->format('Y') . '-01-01');
            $end = clone $now;
            break;
        case 'all_time':
            $start = new DateTime('2020-01-01');
            $end = clone $now;
            break;
        default:
            $start = new DateTime($now->format('Y') . '-01-01');
            $end = clone $now;
    }
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

/**
 * Advance a schedule to its next run date
 */
function advanceSchedule(PDO $pdo, array $schedule): void {
    $frequency = $schedule['frequency'];
    $now = new DateTime();

    switch ($frequency) {
        case 'weekly':
            $next = new DateTime('next monday');
            if ($next <= $now) $next->modify('+1 week');
            break;
        case 'monthly':
            $next = new DateTime('first day of next month');
            break;
        case 'quarterly':
            $currentMonth = (int)$now->format('n');
            $qStarts = [1, 4, 7, 10];
            $nextQ = null;
            foreach ($qStarts as $m) {
                if ($m > $currentMonth) { $nextQ = $m; break; }
            }
            if ($nextQ === null) {
                $next = new DateTime(($now->format('Y') + 1) . '-01-01');
            } else {
                $next = new DateTime($now->format('Y') . '-' . str_pad($nextQ, 2, '0', STR_PAD_LEFT) . '-01');
            }
            break;
        case 'annually':
            $next = new DateTime(($now->format('Y') + 1) . '-01-01');
            break;
        default:
            $next = new DateTime('first day of next month');
    }

    $pdo->prepare('UPDATE audit_schedules SET last_run_at = NOW(), next_run_at = ? WHERE id = ?')
        ->execute([$next->format('Y-m-d 06:00:00'), $schedule['id']]);
}
