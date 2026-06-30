<?php
// src/controllers/financial/csv_import.php
// CSV import for expenses — supports Amazon order history, bank statements, and generic CSV
// Two-phase: phase 1 = upload + detect format + show mapping UI, phase 2 = import with mapping

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';

header('Content-Type: application/json');

$userId = $_SESSION['user']['id'] ?? null;
if (!$userId) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$csrfOk = false;
$submitted = $_POST['_token'] ?? '';
if (is_string($submitted) && $submitted !== '') {
    $csrfOk = csrf_sf_is_valid('csv_import', $submitted);
} else {
    $csrfOk = csrf_validate();
}
if (!$csrfOk) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$orgId = get_active_org_id();
if ($orgId <= 0 || !user_can($pdo, (int)$userId, 'financial.manage', $orgId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}
$phase = $_POST['phase'] ?? 'upload';

try {
    if ($phase === 'upload') {
        // Phase 1: Upload CSV, detect format, return preview
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('CSV file is required');
        }

        $file = $_FILES['csv_file'];
        if ($file['size'] > 10 * 1024 * 1024) {
            throw new Exception('File too large (max 10MB)');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        // Accept text/csv, text/plain, application/vnd.ms-excel
        $allowed = ['text/csv', 'text/plain', 'text/x-csv', 'application/csv', 'application/vnd.ms-excel'];
        if (!in_array($mime, $allowed, true) && !preg_match('/\.csv$/i', $file['name'])) {
            throw new Exception('Invalid file type. Please upload a CSV file');
        }

        // Parse CSV
        $rows = [];
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            throw new Exception('Failed to open CSV file');
        }

        // Detect delimiter
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = ',';
        if (strpos($firstLine, "\t") !== false && strpos($firstLine, ',') === false) {
            $delimiter = "\t"; // Tab-separated (some bank exports)
        } elseif (strpos($firstLine, ';') !== false && strpos($firstLine, ',') === false) {
            $delimiter = ';';
        }

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        if (count($rows) < 2) {
            throw new Exception('CSV file has no data rows');
        }

        $headers = $rows[0];
        $dataRows = array_slice($rows, 1, 10); // Preview first 10 rows
        $totalRows = count($rows) - 1;

        // Detect format
        $format = 'generic';
        $headerStr = implode(' ', array_map('strtolower', $headers));
        if (strpos($headerStr, 'order id') !== false && strpos($headerStr, 'purchase price') !== false) {
            $format = 'amazon';
        } elseif (strpos($headerStr, 'transaction') !== false && strpos($headerStr, 'amount') !== false) {
            $format = 'bank';
        }

        // Suggest mapping based on format
        $suggestedMapping = [];
        $expenseFields = ['expense_date', 'vendor_name', 'description', 'amount', 'tax_amount', 'total_amount', 'reference_number', 'payment_method'];

        if ($format === 'amazon') {
            foreach ($headers as $i => $h) {
                $hl = strtolower($h);
                if ($hl === 'order date') $suggestedMapping['expense_date'] = $i;
                elseif ($hl === 'seller' || $hl === 'seller name') $suggestedMapping['vendor_name'] = $i;
                elseif ($hl === 'title') $suggestedMapping['description'] = $i;
                elseif ($hl === 'purchase price per unit' || $hl === 'purchase price') $suggestedMapping['amount'] = $i;
                elseif ($hl === 'order tax') $suggestedMapping['tax_amount'] = $i;
                elseif ($hl === 'order total') $suggestedMapping['total_amount'] = $i;
                elseif ($hl === 'order id') $suggestedMapping['reference_number'] = $i;
                elseif ($hl === 'payment instrument type') $suggestedMapping['payment_method'] = $i;
            }
        } else {
            // Generic: try to match common column names
            foreach ($headers as $i => $h) {
                $hl = strtolower(trim($h));
                if (in_array($hl, ['date', 'transaction date', 'posted date', 'post date']) && !isset($suggestedMapping['expense_date'])) {
                    $suggestedMapping['expense_date'] = $i;
                }
                if (in_array($hl, ['description', 'memo', 'details', 'transaction', 'name', 'title', 'item']) && !isset($suggestedMapping['description'])) {
                    $suggestedMapping['description'] = $i;
                }
                if (in_array($hl, ['amount', 'debit', 'withdrawal', 'purchase price', 'total']) && !isset($suggestedMapping['amount'])) {
                    $suggestedMapping['amount'] = $i;
                }
                if (in_array($hl, ['vendor', 'merchant', 'payee', 'store', 'seller', 'seller name']) && !isset($suggestedMapping['vendor_name'])) {
                    $suggestedMapping['vendor_name'] = $i;
                }
                if (in_array($hl, ['reference', 'ref', 'order id', 'transaction id', 'check number']) && !isset($suggestedMapping['reference_number'])) {
                    $suggestedMapping['reference_number'] = $i;
                }
            }
        }

        // Store CSV data in session for phase 2
        $_SESSION['csv_import_data'] = [
            'rows' => array_slice($rows, 1), // All data rows (skip header)
            'headers' => $headers,
            'format' => $format,
        ];

        echo json_encode([
            'success' => true,
            'format' => $format,
            'headers' => $headers,
            'preview_rows' => $dataRows,
            'total_rows' => $totalRows,
            'suggested_mapping' => $suggestedMapping,
            'expense_fields' => $expenseFields,
        ]);

    } elseif ($phase === 'import') {
        // Phase 2: Import with user-confirmed mapping
        if (empty($_SESSION['csv_import_data'])) {
            throw new Exception('No CSV data in session. Please upload again.');
        }

        $mapping = json_decode($_POST['mapping'] ?? '{}', true);
        if (!is_array($mapping)) {
            throw new Exception('Invalid mapping data');
        }

        $defaultCategoryId = (int)($_POST['default_category_id'] ?? 0);
        if ($defaultCategoryId > 0) {
            $catCheck = $pdo->prepare('SELECT 1 FROM expense_categories WHERE id = ? AND organization_id = ? LIMIT 1');
            $catCheck->execute([$defaultCategoryId, $orgId]);
            if (!$catCheck->fetchColumn()) {
                throw new Exception('Invalid expense category');
            }
        }
        $dryRun = !empty($_POST['dry_run']);

        $csvData = $_SESSION['csv_import_data'];
        $rows = $csvData['rows'];
        $headers = $csvData['headers'];

        $imported = 0;
        $skipped = 0;
        $errors = [];

        // Cache vendors to avoid repeated lookups
        $vendorCache = [];

        foreach ($rows as $rowIdx => $row) {
            try {
                // Extract fields using mapping
                $expenseDate = $mapping['expense_date'] !== null && isset($row[$mapping['expense_date']])
                    ? trim($row[$mapping['expense_date']]) : '';
                $vendorName = $mapping['vendor_name'] !== null && isset($row[$mapping['vendor_name']])
                    ? trim($row[$mapping['vendor_name']]) : '';
                $description = $mapping['description'] !== null && isset($row[$mapping['description']])
                    ? trim($row[$mapping['description']]) : '';
                $amountStr = $mapping['amount'] !== null && isset($row[$mapping['amount']])
                    ? trim($row[$mapping['amount']]) : '0';
                $taxStr = $mapping['tax_amount'] !== null && isset($row[$mapping['tax_amount']])
                    ? trim($row[$mapping['tax_amount']]) : '';
                $totalStr = $mapping['total_amount'] !== null && isset($row[$mapping['total_amount']])
                    ? trim($row[$mapping['total_amount']]) : '';
                $reference = $mapping['reference_number'] !== null && isset($row[$mapping['reference_number']])
                    ? trim($row[$mapping['reference_number']]) : '';
                $paymentMethod = $mapping['payment_method'] !== null && isset($row[$mapping['payment_method']])
                    ? trim($row[$mapping['payment_method']]) : '';

                // Parse date (try multiple formats)
                $parsedDate = null;
                foreach (['Y-m-d', 'm/d/Y', 'n/j/Y', 'm/d/y', 'Y-m-d H:i:s', 'n/j/y'] as $fmt) {
                    $dt = DateTime::createFromFormat($fmt, $expenseDate);
                    if ($dt !== false) {
                        $parsedDate = $dt->format('Y-m-d');
                        break;
                    }
                }
                if ($parsedDate === null) {
                    $skipped++;
                    $errors[] = "Row " . ($rowIdx + 2) . ": Could not parse date '$expenseDate'";
                    continue;
                }

                // Parse amount (remove $ and commas)
                $amount = (float)preg_replace('/[^0-9.\-]/', '', $amountStr);
                if ($amount === 0.0) {
                    $skipped++;
                    $errors[] = "Row " . ($rowIdx + 2) . ": Invalid amount '$amountStr'";
                    continue;
                }
                $amount = abs($amount);

                $taxAmount = $taxStr !== '' ? abs((float)preg_replace('/[^0-9.\-]/', '', $taxStr)) : null;
                $totalAmount = $totalStr !== '' ? abs((float)preg_replace('/[^0-9.\-]/', '', $totalStr)) : ($taxAmount !== null ? $amount + $taxAmount : $amount);

                // Map payment method
                $pmLower = strtolower($paymentMethod);
                $pm = 'other';
                if (strpos($pmLower, 'visa') !== false || strpos($pmLower, 'master') !== false || strpos($pmLower, 'card') !== false || strpos($pmLower, 'credit') !== false) {
                    $pm = 'card';
                } elseif (strpos($pmLower, 'bank') !== false || strpos($pmLower, 'ach') !== false || strpos($pmLower, 'transfer') !== false) {
                    $pm = 'bank_transfer';
                } elseif (strpos($pmLower, 'check') !== false) {
                    $pm = 'check';
                } elseif (strpos($pmLower, 'cash') !== false) {
                    $pm = 'cash';
                } elseif (strpos($pmLower, 'paypal') !== false) {
                    $pm = 'paypal';
                } elseif (strpos($pmLower, 'venmo') !== false) {
                    $pm = 'venmo';
                }

                // Find or create vendor
                $vendorId = null;
                if ($vendorName !== '') {
                    if (isset($vendorCache[$vendorName])) {
                        $vendorId = $vendorCache[$vendorName];
                    } else {
                        $vStmt = $pdo->prepare('SELECT id FROM vendors WHERE organization_id=? AND name=? LIMIT 1');
                        $vStmt->execute([$orgId, $vendorName]);
                        $vendorId = $vStmt->fetchColumn();
                        if (!$vendorId) {
                            // Auto-create vendor
                            $insV = $pdo->prepare('INSERT INTO vendors (organization_id, name) VALUES (?, ?)');
                            $insV->execute([$orgId, $vendorName]);
                            $vendorId = (int)$pdo->lastInsertId();
                        }
                        $vendorCache[$vendorName] = (int)$vendorId;
                    }
                }

                // Check for duplicate (same date + amount + vendor)
                $dupStmt = $pdo->prepare('SELECT id FROM expenses WHERE organization_id=? AND expense_date=? AND amount=? AND vendor_id <=> ? LIMIT 1');
                $dupStmt->execute([$orgId, $parsedDate, $amount, $vendorId]);
                if ($dupStmt->fetchColumn()) {
                    $skipped++;
                    $errors[] = "Row " . ($rowIdx + 2) . ": Duplicate (same date, amount, vendor)";
                    continue;
                }

                if (!$dryRun) {
                    $stmt = $pdo->prepare('
                        INSERT INTO expenses (organization_id, vendor_id, category_id, amount, tax_amount, total_amount, expense_date, description, payment_method, reference_number, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([
                        $orgId,
                        $vendorId,
                        $defaultCategoryId ?: null,
                        $amount,
                        $taxAmount,
                        $totalAmount,
                        $parsedDate,
                        $description,
                        $pm,
                        $reference,
                        $userId
                    ]);
                }

                $imported++;
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = "Row " . ($rowIdx + 2) . ": " . $e->getMessage();
            }
        }

        if (!$dryRun && $imported > 0) {
            audit_log($pdo, 'expense.csv_import', 'expense', null, ['imported' => $imported, 'skipped' => $skipped]);
            unset($_SESSION['csv_import_data']);
        }

        echo json_encode([
            'success' => true,
            'dry_run' => $dryRun,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 20), // First 20 errors
        ]);
    } else {
        throw new Exception('Invalid phase. Use "upload" or "import".');
    }

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
