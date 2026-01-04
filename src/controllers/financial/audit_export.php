<?php
// src/controllers/financial/audit_export.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';

// Verify CSRF token
csrf_verify_post_or_redirect('financial/audit-export');

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
$scheduleEmails = [];

if ($enableScheduling) {
    $scheduleEmails = array_filter($_POST['schedule_email'] ?? [], function($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    });
    $scheduleEmails = array_slice($scheduleEmails, 0, 5); // Limit to 5 emails
}

// Validate dates
if (!$startDate || !$endDate) {
    throw new Exception('Invalid date range selected.');
}

try {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
} catch (Exception $e) {
    throw new Exception('Invalid date format. Please use YYYY-MM-DD format.');
}

if ($start > $end) {
    $temp = $start;
    $start = $end;
    $end = $temp;
    $startDate = $start->format('Y-m-d');
    $endDate = $end->format('Y-m-d');
}

// Build invoice query - only include if checkbox is checked
$invoices = [];
if ($includeInvoices) {
    // Determine which invoice statuses to include
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
            i.tax_percent as tax_percent,
            i.tax_amount as tax,
            i.tax_county as tax_county,
            i.discount_value as discount_value,
            i.total,
            i.status,
            i.created_at,
            i.due_date,
            COALESCE(SUM(CASE WHEN p.status = 'succeeded' THEN p.amount ELSE 0 END), 0) as amount_paid,
            GROUP_CONCAT(DISTINCT p.method SEPARATOR ', ') as payment_methods
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

try {
    // Fetch contracts if requested
    $contracts = [];
    if ($includeContracts) {
        $contractQuery = "
            SELECT 
                c.id,
                c.doc_number,
                c.client_id,
                cl.name as client_name,
                c.project_code,
                c.total_contract_value as total,
                c.status,
                c.created_at,
                c.expiration_date
            FROM contracts c
            LEFT JOIN clients cl ON c.client_id = cl.id
            WHERE c.created_at BETWEEN ? AND ?
            ORDER BY c.created_at ASC, c.doc_number ASC
        ";
        $contractStmt = $pdo->prepare($contractQuery);
        $contractStmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $contracts = $contractStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch quotes if requested
    $quotes = [];
    if ($includeQuotes) {
        $quoteQuery = "
            SELECT 
                q.id,
                q.doc_number,
                q.client_id,
                cl.name as client_name,
                q.project_code,
                q.total,
                q.status,
                q.created_at,
                q.valid_until
            FROM quotes q
            LEFT JOIN clients cl ON q.client_id = cl.id
            WHERE q.created_at BETWEEN ? AND ?
            ORDER BY q.created_at ASC, q.doc_number ASC
        ";
        $quoteStmt = $pdo->prepare($quoteQuery);
        $quoteStmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $quotes = $quoteStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Prepare data for Python script
    $auditData = [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'include_invoices' => $includeInvoices,
        'include_unpaid_invoices' => $includeUnpaidInvoices,
        'include_contracts' => $includeContracts,
        'include_quotes' => $includeQuotes,
        'generate_csv' => $generateCsv,
        'include_pdfs' => $includePdfs,
        'schedule_emails' => $scheduleEmails,
        'invoices' => $invoices,
        'contracts' => $contracts,
        'quotes' => $quotes,
        'db_config' => [
            'host' => getenv('DB_HOST') ?: 'localhost',
            'user' => getenv('DB_USER') ?: 'root',
            'password' => getenv('DB_PASS') ?: '',
            'database' => getenv('DB_NAME') ?: 'project_alpha'
        ]
    ];

    // Save audit data to temporary JSON file
    $tmpFile = sys_get_temp_dir() . '/audit_request_' . uniqid() . '.json';
    file_put_contents($tmpFile, json_encode($auditData));

    // Call Python script
    $pythonScript = __DIR__ . '/audit_generator.py';
    $pythonPath = getenv('PYTHON_PATH') ?: 'python3';
    
    // Execute Python script and capture output
    $descriptorspec = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
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

        // Clean up temp file
        @unlink($tmpFile);

        if ($exitCode === 0 && !empty($stdout)) {
            // Python script returns JSON with zip file path
            $result = json_decode($stdout, true);
            if ($result && isset($result['zip_path']) && file_exists($result['zip_path'])) {
                // Send file for download
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . basename($result['zip_path']) . '"');
                header('Content-Length: ' . filesize($result['zip_path']));
                readfile($result['zip_path']);
                
                // Clean up zip file after download
                @unlink($result['zip_path']);
                exit;
            }
        }
    }

    // If we get here, something went wrong
    throw new Exception('Failed to generate audit report. Please try again.');

} catch (Throwable $e) {
    // Log error and redirect back with error message
    error_log('Audit export error: ' . $e->getMessage());
    header('Location: /?page=financial/audit&error=' . urlencode('Failed to generate audit: ' . $e->getMessage()));
    exit;
}
