<?php
// src/controllers/financial/audit_export.php
// Pure PHP audit generation - no Python dependency
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';

// Get form parameters
$startDate = $_POST['start_date'] ?? date('Y-m-d', strtotime('January 1 ' . date('Y')));
$endDate = $_POST['end_date'] ?? date('Y-m-d');
$includeInvoices = isset($_POST['include_invoices']) && $_POST['include_invoices'] === '1';
$includeUnpaidInvoices = isset($_POST['include_unpaid_invoices']) && $_POST['include_unpaid_invoices'] === '1';
$includeContracts = isset($_POST['include_contracts']) && $_POST['include_contracts'] === '1';
$includeQuotes = isset($_POST['include_quotes']) && $_POST['include_quotes'] === '1';
$generateCsv = isset($_POST['generate_csv']) && $_POST['generate_csv'] === '1';
$includePdfs = isset($_POST['include_pdfs']) && $_POST['include_pdfs'] === '1';
$enableScheduling = isset($_POST['enable_scheduling']) && $_POST['enable_scheduling'] === '1';

// Handle scheduling separately
if ($enableScheduling) {
    $scheduleEmails = array_filter($_POST['schedule_email'] ?? [], function($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    });
    $scheduleEmails = array_slice($scheduleEmails, 0, 5);
    
    if (!empty($scheduleEmails)) {
        $frequency = $_POST['schedule_frequency'] ?? 'monthly';
        $dateRangeType = $_POST['schedule_date_range'] ?? 'current_year';
        
        // Calculate next run date based on frequency
        $nextRun = calculateNextRunDate($frequency);
        
        try {
            // Ensure audit_schedules table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS audit_schedules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                frequency VARCHAR(50) NOT NULL,
                date_range_type VARCHAR(50) NOT NULL,
                email_addresses TEXT NOT NULL,
                include_invoices TINYINT(1) DEFAULT 1,
                include_unpaid_invoices TINYINT(1) DEFAULT 0,
                include_contracts TINYINT(1) DEFAULT 0,
                include_quotes TINYINT(1) DEFAULT 0,
                generate_csv TINYINT(1) DEFAULT 1,
                include_pdfs TINYINT(1) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                next_run_at DATETIME,
                last_run_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            
            $stmt = $pdo->prepare("INSERT INTO audit_schedules 
                (frequency, date_range_type, email_addresses, include_invoices, include_unpaid_invoices, 
                 include_contracts, include_quotes, generate_csv, include_pdfs, next_run_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $frequency,
                $dateRangeType,
                json_encode($scheduleEmails),
                $includeInvoices ? 1 : 0,
                $includeUnpaidInvoices ? 1 : 0,
                $includeContracts ? 1 : 0,
                $includeQuotes ? 1 : 0,
                $generateCsv ? 1 : 0,
                $includePdfs ? 1 : 0,
                $nextRun
            ]);
            
            header('Location: /?page=financial/audit&scheduled=1');
            exit;
        } catch (Throwable $e) {
            error_log('Audit schedule error: ' . $e->getMessage());
            header('Location: /?page=financial/audit&error=' . urlencode('Failed to create schedule: ' . $e->getMessage()));
            exit;
        }
    }
}

// Validate dates
if (!$startDate || !$endDate) {
    header('Location: /?page=financial/audit&error=' . urlencode('Please select a valid date range.'));
    exit;
}

try {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
} catch (Exception $e) {
    header('Location: /?page=financial/audit&error=' . urlencode('Invalid date format. Please use the date picker.'));
    exit;
}

if ($start > $end) {
    $temp = $startDate;
    $startDate = $endDate;
    $endDate = $temp;
}

try {
    // Fetch invoices
    $invoices = [];
    if ($includeInvoices) {
        $statusFilter = $includeUnpaidInvoices 
            ? "i.status IN ('paid', 'partial', 'unpaid')" 
            : "i.status IN ('paid', 'partial')";
        
        $invoiceQuery = "
            SELECT 
                i.id,
                i.doc_number,
                i.client_id,
                c.name as client_name,
                i.project_code,
                i.subtotal,
                i.tax_percent,
                i.tax_amount as tax,
                i.tax_county,
                i.discount_value,
                i.total,
                i.status,
                i.created_at,
                i.due_date,
                COALESCE(SUM(CASE WHEN p.status = 'succeeded' THEN p.amount ELSE 0 END), 0) as amount_paid,
                GROUP_CONCAT(DISTINCT p.payment_method SEPARATOR ', ') as payment_methods
            FROM invoices i
            LEFT JOIN clients c ON i.client_id = c.id
            LEFT JOIN payments p ON i.id = p.invoice_id
            WHERE i.created_at BETWEEN ? AND ? AND {$statusFilter}
            GROUP BY i.id
            ORDER BY i.created_at ASC, i.doc_number ASC
        ";
        
        $stmt = $pdo->prepare($invoiceQuery);
        $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch contracts
    $contracts = [];
    if ($includeContracts) {
        $contractQuery = "
            SELECT 
                c.id,
                c.doc_number,
                c.client_id,
                cl.name as client_name,
                c.project_code,
                c.contract_type,
                c.total,
                c.status,
                c.created_at,
                c.start_date,
                c.end_date,
                c.discount_type,
                c.discount_value,
                c.tax_percent
            FROM contracts c
            LEFT JOIN clients cl ON c.client_id = cl.id
            WHERE c.created_at BETWEEN ? AND ?
            ORDER BY c.created_at ASC, c.doc_number ASC
        ";
        $contractStmt = $pdo->prepare($contractQuery);
        $contractStmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $contracts = $contractStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch quotes
    $quotes = [];
    if ($includeQuotes) {
        $quoteQuery = "
            SELECT 
                q.id,
                q.doc_number,
                q.client_id,
                cl.name as client_name,
                q.project_code,
                q.quote_type,
                q.total,
                q.status,
                q.created_at,
                q.discount_type,
                q.discount_value,
                q.tax_percent
            FROM quotes q
            LEFT JOIN clients cl ON q.client_id = cl.id
            WHERE q.created_at BETWEEN ? AND ?
            ORDER BY q.created_at ASC, q.doc_number ASC
        ";
        $quoteStmt = $pdo->prepare($quoteQuery);
        $quoteStmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $quotes = $quoteStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Check if we have any data
    if (empty($invoices) && empty($contracts) && empty($quotes)) {
        header('Location: /?page=financial/audit&error=' . urlencode('No records found for the selected date range and options.'));
        exit;
    }

    // Create temp directory for files
    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'audit_' . uniqid();
    if (!mkdir($tmpDir, 0755, true)) {
        throw new Exception('Could not create temporary directory');
    }

    $filesToZip = [];

    // Generate CSV
    if ($generateCsv) {
        $csvFile = $tmpDir . DIRECTORY_SEPARATOR . 'audit_report.csv';
        $fp = fopen($csvFile, 'w');
        if (!$fp) {
            throw new Exception('Could not create CSV file');
        }

        // Write BOM for Excel UTF-8 compatibility
        fwrite($fp, "\xEF\xBB\xBF");

        // Headers
        $headers = ['Date', 'Client', 'Doc Number', 'Document Type', 'Status', 'Tax %', 'Tax County', 'Amount Paid', 'Payment Method', 'Discount', 'Total', 'Running Total'];
        fputcsv($fp, $headers);

        $runningTotal = 0;

        // Write invoices
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

        // Write contracts
        foreach ($contracts as $contract) {
            $total = (float)($contract['total'] ?? 0);
            
            fputcsv($fp, [
                substr($contract['created_at'] ?? '', 0, 10),
                $contract['client_name'] ?? '',
                $contract['doc_number'] ?? $contract['id'],
                'Contract',
                ucfirst($contract['status'] ?? ''),
                '',
                '',
                '',
                '',
                '',
                '$' . number_format($total, 2),
                ''
            ]);
        }

        // Write quotes
        foreach ($quotes as $quote) {
            $total = (float)($quote['total'] ?? 0);
            
            fputcsv($fp, [
                substr($quote['created_at'] ?? '', 0, 10),
                $quote['client_name'] ?? '',
                $quote['doc_number'] ?? $quote['id'],
                'Quote',
                ucfirst($quote['status'] ?? ''),
                '',
                '',
                '',
                '',
                '',
                '$' . number_format($total, 2),
                ''
            ]);
        }

        // Summary row
        fputcsv($fp, ['', '', '', '', '', '', '', '', '', '', '', '']);
        fputcsv($fp, ['SUMMARY', '', '', '', '', '', '', '', '', '', '', '']);
        fputcsv($fp, ['Total Invoices:', count($invoices), '', '', '', '', '', 'Total Collected:', '$' . number_format($runningTotal, 2), '', '', '']);
        if ($includeContracts) {
            fputcsv($fp, ['Total Contracts:', count($contracts), '', '', '', '', '', '', '', '', '', '']);
        }
        if ($includeQuotes) {
            fputcsv($fp, ['Total Quotes:', count($quotes), '', '', '', '', '', '', '', '', '', '']);
        }

        fclose($fp);
        $filesToZip['audit_report.csv'] = $csvFile;
    }

    // Generate manifest
    $manifestFile = $tmpDir . DIRECTORY_SEPARATOR . 'MANIFEST.txt';
    $manifest = "=== FINANCIAL AUDIT REPORT ===\n\n";
    $manifest .= "Generated: " . date('Y-m-d H:i:s') . "\n";
    $manifest .= "Audit Period: {$startDate} to {$endDate}\n\n";
    $manifest .= "REPORT CONFIGURATION:\n";
    $manifest .= "- Include Invoices (Paid/Partial): " . ($includeInvoices ? 'Yes' : 'No') . "\n";
    $manifest .= "- Include Unpaid Invoices: " . ($includeUnpaidInvoices ? 'Yes' : 'No') . "\n";
    $manifest .= "- Include Contracts: " . ($includeContracts ? 'Yes' : 'No') . "\n";
    $manifest .= "- Include Quotes: " . ($includeQuotes ? 'Yes' : 'No') . "\n\n";
    $manifest .= "REPORT SUMMARY:\n";
    $manifest .= "- Total Invoices: " . count($invoices) . "\n";
    if ($includeContracts) {
        $manifest .= "- Total Contracts: " . count($contracts) . "\n";
    }
    if ($includeQuotes) {
        $manifest .= "- Total Quotes: " . count($quotes) . "\n";
    }
    $totalCollected = array_sum(array_column($invoices, 'amount_paid'));
    $manifest .= "- Total Collected: $" . number_format($totalCollected, 2) . "\n\n";
    $manifest .= "FILES INCLUDED:\n";
    if ($generateCsv) {
        $manifest .= "- audit_report.csv: Detailed audit data\n";
    }
    $manifest .= "- MANIFEST.txt: This file\n";
    
    file_put_contents($manifestFile, $manifest);
    $filesToZip['MANIFEST.txt'] = $manifestFile;

    // Create ZIP file
    $zipFilename = 'audit_' . str_replace('-', '', $startDate) . '-' . str_replace('-', '', $endDate) . '_' . date('Ymd_His') . '.zip';
    $zipPath = $tmpDir . DIRECTORY_SEPARATOR . $zipFilename;
    
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        throw new Exception('Could not create ZIP file. Ensure the ZipArchive extension is enabled.');
    }

    foreach ($filesToZip as $name => $path) {
        $zip->addFile($path, $name);
    }
    $zip->close();

    // Send file for download
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    readfile($zipPath);

    // Cleanup
    foreach ($filesToZip as $path) {
        @unlink($path);
    }
    @unlink($zipPath);
    @rmdir($tmpDir);
    
    exit;

} catch (Throwable $e) {
    error_log('Audit export error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    
    // Cleanup on error
    if (isset($tmpDir) && is_dir($tmpDir)) {
        array_map('unlink', glob("$tmpDir/*") ?: []);
        @rmdir($tmpDir);
    }
    
    header('Location: /?page=financial/audit&error=' . urlencode('Failed to generate audit: ' . $e->getMessage()));
    exit;
}

/**
 * Calculate next run date based on frequency
 */
function calculateNextRunDate(string $frequency): string {
    $now = new DateTime();
    
    switch ($frequency) {
        case 'weekly':
            // Next Monday
            $next = new DateTime('next monday');
            break;
        case 'monthly':
            // First day of next month
            $next = new DateTime('first day of next month');
            break;
        case 'quarterly':
            // First day of next quarter
            $currentMonth = (int)$now->format('n');
            $quarterStart = (int)ceil($currentMonth / 3) * 3 + 1;
            if ($quarterStart > 12) {
                $quarterStart = 1;
                $next = new DateTime(($now->format('Y') + 1) . '-01-01');
            } else {
                $next = new DateTime($now->format('Y') . '-' . str_pad((string)$quarterStart, 2, '0', STR_PAD_LEFT) . '-01');
            }
            break;
        case 'annually':
            // January 1st next year
            $next = new DateTime(($now->format('Y') + 1) . '-01-01');
            break;
        default:
            $next = new DateTime('first day of next month');
    }
    
    return $next->format('Y-m-d 06:00:00');
}
