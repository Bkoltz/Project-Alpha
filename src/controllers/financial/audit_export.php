<?php
// src/controllers/financial/audit_export.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';

// Verify CSRF token
csrf_verify_post_or_redirect('financial/audit-export');

// Get form parameters
$startYear = (int)($_POST['start_year'] ?? date('Y'));
$endYear = (int)($_POST['end_year'] ?? date('Y'));
$invoiceStatus = (string)($_POST['invoice_status'] ?? 'paid_only');
$includeContracts = isset($_POST['include_contracts']) && $_POST['include_contracts'] === '1';
$includePdfs = isset($_POST['include_pdfs']) && $_POST['include_pdfs'] === '1';
$clientInfoOnly = isset($_POST['client_info_only']) && $_POST['client_info_only'] === '1';

// Validate year range
if ($startYear > $endYear) {
    $temp = $startYear;
    $startYear = $endYear;
    $endYear = $temp;
}

$startDate = $startYear . '-01-01';
$endDate = $endYear . '-12-31';

// Build invoice query based on status filter
$invoiceQuery = "
    SELECT 
        i.id,
        i.doc_number,
        i.client_id,
        c.name as client_name,
        i.project_code,
        i.total,
        i.status,
        i.created_at,
        i.due_date
    FROM invoices i
    LEFT JOIN clients c ON i.client_id = c.id
    WHERE i.created_at BETWEEN ? AND ?
";

$params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];

// Apply invoice status filter
switch ($invoiceStatus) {
    case 'paid_and_partial':
        $invoiceQuery .= " AND i.status IN ('paid', 'partial')";
        break;
    case 'unpaid_only':
        $invoiceQuery .= " AND i.status IN ('unpaid', 'overdue')";
        break;
    case 'all':
        // No status filter
        break;
    case 'paid_only':
    default:
        $invoiceQuery .= " AND i.status = 'paid'";
        break;
}

$invoiceQuery .= " ORDER BY i.created_at ASC, i.doc_number ASC";

try {
    // Fetch invoices
    $stmt = $pdo->prepare($invoiceQuery);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    // Prepare data for Python script
    $auditData = [
        'start_year' => $startYear,
        'end_year' => $endYear,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'invoice_status' => $invoiceStatus,
        'include_contracts' => $includeContracts,
        'include_pdfs' => $includePdfs,
        'client_info_only' => $clientInfoOnly,
        'invoices' => $invoices,
        'contracts' => $contracts,
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
