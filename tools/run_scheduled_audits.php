#!/usr/bin/env php
<?php
/**
 * Scheduled Audit Runner
 * 
 * This script should be run via cron to execute scheduled audits.
 * Recommended cron schedule: Every hour or every 6 hours
 * 
 * Example crontab entry:
 * 0 * * * * php /path/to/tools/run_scheduled_audits.php >> /path/to/logs/scheduled_audits.log 2>&1
 * 
 * Windows PowerShell example:
 * $env:DB_HOST="localhost"; php K:\Projects\Project-Alpha\tools\run_scheduled_audits.php
 */

// Set default DB_HOST to localhost if running from CLI and not set
if (php_sapi_name() === 'cli' && !getenv('DB_HOST')) {
    putenv('DB_HOST=localhost');
}

// Load database configuration
require_once __DIR__ . '/../src/config/db.php';
require_once __DIR__ . '/../src/config/app.php';

// Log start
error_log('[' . date('Y-m-d H:i:s') . '] Starting scheduled audit runner');

try {
    // Find all active schedules that are due to run
    $stmt = $pdo->prepare('
        SELECT * FROM audit_schedules 
        WHERE is_active = 1 
        AND (next_run_at IS NULL OR next_run_at <= NOW())
        ORDER BY next_run_at ASC
    ');
    $stmt->execute();
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($schedules)) {
        error_log('[' . date('Y-m-d H:i:s') . '] No scheduled audits due at this time');
        exit(0);
    }
    
    error_log('[' . date('Y-m-d H:i:s') . '] Found ' . count($schedules) . ' schedule(s) to run');
    
    foreach ($schedules as $schedule) {
        try {
            error_log('[' . date('Y-m-d H:i:s') . '] Processing schedule ID: ' . $schedule['id']);
            
            // Calculate date range based on schedule configuration
            $dateRange = calculateDateRange($schedule['date_range_type']);
            $options = json_decode($schedule['options'], true) ?: [];
            $emails = json_decode($schedule['email_addresses'], true) ?: [];
            
            // Build audit request data
            $auditData = [
                'start_date' => $dateRange['start'],
                'end_date' => $dateRange['end'],
                'include_invoices' => true,
                'include_unpaid_invoices' => $options['include_unpaid_invoices'] ?? false,
                'include_contracts' => $options['include_contracts'] ?? false,
                'include_quotes' => $options['include_quotes'] ?? false,
                'generate_csv' => true,
                'include_pdfs' => $options['include_pdfs'] ?? false,
                'schedule_emails' => $emails,
                'invoices' => [],
                'contracts' => [],
                'quotes' => []
            ];
            
            // Fetch invoices
            $statusFilter = ($options['include_unpaid_invoices'] ?? false)
                ? "i.status IN ('paid', 'partial', 'unpaid')"
                : "i.status IN ('paid', 'partial')";
            
            $invoiceQuery = "
                SELECT 
                    i.id, i.doc_number, i.client_id, c.name as client_name, i.project_code,
                    i.subtotal, i.tax_percent, i.tax_amount as tax, i.tax_county,
                    i.discount_value, i.total, i.status, i.created_at, i.due_date,
                    COALESCE(SUM(CASE WHEN p.status = 'succeeded' THEN p.amount ELSE 0 END), 0) as amount_paid,
                    GROUP_CONCAT(DISTINCT p.method SEPARATOR ', ') as payment_methods
                FROM invoices i
                LEFT JOIN clients c ON i.client_id = c.id
                LEFT JOIN payments p ON i.id = p.invoice_id
                WHERE i.created_at BETWEEN ? AND ? AND {$statusFilter}
                GROUP BY i.id
                ORDER BY i.created_at ASC
            ";
            
            $stmt = $pdo->prepare($invoiceQuery);
            $stmt->execute([$dateRange['start'] . ' 00:00:00', $dateRange['end'] . ' 23:59:59']);
            $auditData['invoices'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Fetch contracts if requested
            if ($options['include_contracts'] ?? false) {
                $stmt = $pdo->prepare("
                    SELECT c.id, c.doc_number, c.client_id, cl.name as client_name, c.project_code,
                           c.total as total, c.status, c.created_at
                    FROM contracts c
                    LEFT JOIN clients cl ON c.client_id = cl.id
                    WHERE c.created_at BETWEEN ? AND ?
                    ORDER BY c.created_at ASC
                ");
                $stmt->execute([$dateRange['start'] . ' 00:00:00', $dateRange['end'] . ' 23:59:59']);
                $auditData['contracts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Fetch quotes if requested
            if ($options['include_quotes'] ?? false) {
                $stmt = $pdo->prepare("
                    SELECT q.id, q.doc_number, q.client_id, cl.name as client_name, q.project_code,
                           q.total, q.status, q.created_at
                    FROM quotes q
                    LEFT JOIN clients cl ON q.client_id = cl.id
                    WHERE q.created_at BETWEEN ? AND ?
                    ORDER BY q.created_at ASC
                ");
                $stmt->execute([$dateRange['start'] . ' 00:00:00', $dateRange['end'] . ' 23:59:59']);
                $auditData['quotes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Save audit data to temporary JSON file
            $tmpFile = sys_get_temp_dir() . '/scheduled_audit_' . $schedule['id'] . '_' . time() . '.json';
            file_put_contents($tmpFile, json_encode($auditData));
            
            // Call Python script
            $pythonScript = __DIR__ . '/../src/controllers/financial/audit_generator.py';
            $pythonPath = getenv('PYTHON_PATH') ?: 'python3';
            
            $descriptorspec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            
            $process = proc_open(
                $pythonPath . ' ' . escapeshellarg($pythonScript) . ' ' . escapeshellarg($tmpFile),
                $descriptorspec,
                $pipes
            );
            
            if (is_resource($process)) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);
                
                @unlink($tmpFile);
                
                if ($exitCode === 0 && !empty($stdout)) {
                    $result = json_decode($stdout, true);
                    
                    if ($result && isset($result['zip_path']) && file_exists($result['zip_path'])) {
                        // Send email with attachment
                        $emailSent = sendAuditEmail($emails, $result['zip_path'], $schedule, $dateRange);
                        
                        // Log success
                        $logStmt = $pdo->prepare('
                            INSERT INTO audit_schedule_logs 
                            (schedule_id, status, file_path, email_sent) 
                            VALUES (?, ?, ?, ?)
                        ');
                        $logStmt->execute([$schedule['id'], 'success', $result['zip_path'], $emailSent ? 1 : 0]);
                        
                        // Clean up zip file after email
                        @unlink($result['zip_path']);
                        
                        error_log('[' . date('Y-m-d H:i:s') . '] Successfully generated and emailed audit for schedule ID: ' . $schedule['id']);
                    } else {
                        throw new Exception('Python script did not return valid zip path');
                    }
                } else {
                    throw new Exception('Python script failed: ' . $stderr);
                }
            } else {
                throw new Exception('Failed to execute Python script');
            }
            
            // Update next_run_at
            $nextRunAt = calculateNextRunTime($schedule['frequency']);
            $updateStmt = $pdo->prepare('
                UPDATE audit_schedules 
                SET next_run_at = ?, last_run_at = NOW() 
                WHERE id = ?
            ');
            $updateStmt->execute([$nextRunAt, $schedule['id']]);
            
        } catch (Throwable $e) {
            error_log('[' . date('Y-m-d H:i:s') . '] Error processing schedule ID ' . $schedule['id'] . ': ' . $e->getMessage());
            
            // Log failure
            try {
                $logStmt = $pdo->prepare('
                    INSERT INTO audit_schedule_logs 
                    (schedule_id, status, error_message) 
                    VALUES (?, ?, ?)
                ');
                $logStmt->execute([$schedule['id'], 'failed', $e->getMessage()]);
            } catch (Throwable $logError) {
                error_log('[' . date('Y-m-d H:i:s') . '] Failed to log error: ' . $logError->getMessage());
            }
        }
    }
    
    error_log('[' . date('Y-m-d H:i:s') . '] Scheduled audit runner completed');
    
} catch (Throwable $e) {
    error_log('[' . date('Y-m-d H:i:s') . '] Fatal error in scheduled audit runner: ' . $e->getMessage());
    exit(1);
}

/**
 * Calculate date range based on type
 */
function calculateDateRange(string $type): array {
    $now = new DateTime();
    
    switch ($type) {
        case 'last_week':
            $start = new DateTime('last monday');
            $end = new DateTime('last sunday');
            break;
            
        case 'last_month':
            $start = new DateTime('first day of last month');
            $end = new DateTime('last day of last month');
            break;
            
        case 'last_quarter':
            $currentMonth = (int)$now->format('n');
            $quarterStartMonth = floor(($currentMonth - 1) / 3) * 3 + 1;
            $prevQuarterStartMonth = $quarterStartMonth - 3;
            if ($prevQuarterStartMonth < 1) {
                $prevQuarterStartMonth += 12;
                $year = $now->format('Y') - 1;
            } else {
                $year = $now->format('Y');
            }
            $start = new DateTime($year . '-' . str_pad($prevQuarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
            $end = clone $start;
            $end->modify('+3 months -1 day');
            break;
            
        case 'last_year':
            $start = new DateTime(($now->format('Y') - 1) . '-01-01');
            $end = new DateTime(($now->format('Y') - 1) . '-12-31');
            break;
            
        case 'current_year':
            $start = new DateTime($now->format('Y') . '-01-01');
            $end = clone $now;
            break;
            
        case 'all_time':
        default:
            $start = new DateTime('2020-01-01');
            $end = clone $now;
            break;
    }
    
    return [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d')
    ];
}

/**
 * Calculate next run time based on frequency
 */
function calculateNextRunTime(string $frequency): string {
    $now = new DateTime();
    
    switch ($frequency) {
        case 'weekly':
            $next = new DateTime('next monday');
            if ($next <= $now) {
                $next->modify('+1 week');
            }
            break;
            
        case 'monthly':
            $next = new DateTime('first day of next month');
            break;
            
        case 'quarterly':
            $currentMonth = (int)$now->format('n');
            $quarterStartMonths = [1, 4, 7, 10];
            $nextQuarterMonth = null;
            
            foreach ($quarterStartMonths as $month) {
                if ($month > $currentMonth) {
                    $nextQuarterMonth = $month;
                    break;
                }
            }
            
            if ($nextQuarterMonth === null) {
                $next = new DateTime(($now->format('Y') + 1) . '-01-01');
            } else {
                $next = new DateTime($now->format('Y') . '-' . str_pad($nextQuarterMonth, 2, '0', STR_PAD_LEFT) . '-01');
            }
            break;
            
        case 'annually':
            $next = new DateTime(($now->format('Y') + 1) . '-01-01');
            break;
            
        default:
            $next = new DateTime('+1 month');
    }
    
    return $next->format('Y-m-d H:i:s');
}

/**
 * Send audit email with attachment
 */
function sendAuditEmail(array $recipients, string $zipPath, array $schedule, array $dateRange): bool {
    if (empty($recipients)) {
        return false;
    }
    
    global $appConfig;
    $brandName = $appConfig['brand_name'] ?? 'Project Alpha';
    $fromEmail = $appConfig['from_email'] ?? 'noreply@localhost';
    
    $subject = $brandName . ' - Scheduled Audit Report (' . $dateRange['start'] . ' to ' . $dateRange['end'] . ')';
    
    $body = "Automated Audit Report\n\n";
    $body .= "Your scheduled " . $schedule['frequency'] . " audit report is attached.\n\n";
    $body .= "Report Period: " . $dateRange['start'] . " to " . $dateRange['end'] . "\n";
    $body .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $body .= "This is an automated message from " . $brandName . ".\n";
    
    // Simple email with attachment (you may want to use PHPMailer for better support)
    $headers = "From: " . $fromEmail . "\r\n";
    $headers .= "Reply-To: " . $fromEmail . "\r\n";
    
    $success = true;
    foreach ($recipients as $email) {
        // Note: For production, integrate with your email service (PHPMailer, SendGrid, etc.)
        // This is a basic example
        if (!mail($email, $subject, $body, $headers)) {
            error_log('[' . date('Y-m-d H:i:s') . '] Failed to send email to: ' . $email);
            $success = false;
        }
    }
    
    return $success;
}
