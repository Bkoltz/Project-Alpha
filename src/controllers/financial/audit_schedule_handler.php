<?php
// src/controllers/financial/audit_schedule_handler.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

csrf_verify_post_or_redirect('financial/audit');

$action = $_POST['action'] ?? 'create';

try {
    if ($action === 'create') {
        // Get schedule parameters
        $frequency = $_POST['schedule_frequency'] ?? 'monthly';
        $dateRangeType = $_POST['schedule_date_range'] ?? 'current_year';
        $emailAddresses = array_filter($_POST['schedule_email'] ?? [], function($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        });
        $emailAddresses = array_slice($emailAddresses, 0, 5); // Limit to 5
        
        if (empty($emailAddresses)) {
            throw new Exception('At least one valid email address is required for scheduling.');
        }
        
        // Validate frequency
        if (!in_array($frequency, ['weekly', 'monthly', 'quarterly', 'annually'])) {
            throw new Exception('Invalid schedule frequency.');
        }
        
        // Validate date range type
        $validDateRanges = ['last_week', 'last_month', 'last_quarter', 'last_year', 'current_year', 'all_time'];
        if (!in_array($dateRangeType, $validDateRanges)) {
            throw new Exception('Invalid date range type.');
        }
        
        // Build options JSON
        $options = [
            'include_contracts' => isset($_POST['include_contracts']) && $_POST['include_contracts'] === '1',
            'include_quotes' => isset($_POST['include_quotes']) && $_POST['include_quotes'] === '1',
            'include_pdfs' => isset($_POST['include_pdfs']) && $_POST['include_pdfs'] === '1',
            'include_unpaid_invoices' => isset($_POST['include_unpaid_invoices']) && $_POST['include_unpaid_invoices'] === '1'
        ];
        
        // Calculate next run time based on frequency
        $nextRunAt = calculateNextRunTime($frequency);
        
        // Insert schedule
        $stmt = $pdo->prepare('
            INSERT INTO audit_schedules 
            (frequency, date_range_type, email_addresses, options, next_run_at) 
            VALUES (?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $frequency,
            $dateRangeType,
            json_encode($emailAddresses),
            json_encode($options),
            $nextRunAt
        ]);
        
        header('Location: /?page=financial/audit&success=Schedule%20created%20successfully');
        exit;
        
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('Invalid schedule ID.');
        }
        
        $stmt = $pdo->prepare('DELETE FROM audit_schedules WHERE id = ?');
        $stmt->execute([$id]);
        
        header('Location: /?page=financial/audit&success=Schedule%20deleted%20successfully');
        exit;
        
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('Invalid schedule ID.');
        }
        
        // Toggle is_active status
        $stmt = $pdo->prepare('UPDATE audit_schedules SET is_active = NOT is_active WHERE id = ?');
        $stmt->execute([$id]);
        
        header('Location: /?page=financial/audit&success=Schedule%20status%20updated');
        exit;
    }
    
} catch (Throwable $e) {
    error_log('Audit schedule handler error: ' . $e->getMessage());
    header('Location: /?page=financial/audit&error=' . urlencode($e->getMessage()));
    exit;
}

/**
 * Calculate next run time based on frequency
 */
function calculateNextRunTime(string $frequency): string {
    $now = new DateTime();
    
    switch ($frequency) {
        case 'weekly':
            // Next Monday
            $next = new DateTime('next monday');
            if ($next <= $now) {
                $next->modify('+1 week');
            }
            break;
            
        case 'monthly':
            // First day of next month
            $next = new DateTime('first day of next month');
            break;
            
        case 'quarterly':
            // Next quarter start (Jan, Apr, Jul, Oct)
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
                // Next quarter is in next year
                $next = new DateTime(($now->format('Y') + 1) . '-01-01');
            } else {
                $next = new DateTime($now->format('Y') . '-' . str_pad($nextQuarterMonth, 2, '0', STR_PAD_LEFT) . '-01');
            }
            break;
            
        case 'annually':
            // Next January 1st
            $next = new DateTime(($now->format('Y') + 1) . '-01-01');
            break;
            
        default:
            $next = new DateTime('+1 month');
    }
    
    return $next->format('Y-m-d H:i:s');
}
