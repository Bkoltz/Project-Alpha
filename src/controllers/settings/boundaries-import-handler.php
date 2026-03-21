<?php
// src/controllers/settings/boundaries-import-handler.php
// Importer C: Boundary Files (ZIP+4 ranges)

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /?page=settings&tab=taxes');
    exit;
}

// Verify CSRF
$token = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
    header('Location: /?page=settings&tab=taxes&boundaries_error=' . rawurlencode('Invalid request (CSRF)'));
    exit;
}

try {
    $stats = [
        'boundaries_imported' => 0,
        'complex_zips' => 0,
        'multi_county_zips' => 0,
        'skipped' => 0,
        'errors' => []
    ];
    
    // Validate file upload
    if (!isset($_FILES['boundary_file']) || $_FILES['boundary_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Please upload a boundary file (.csv)');
    }
    if ($_FILES['boundary_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Upload error: ' . getUploadErrorMessage($_FILES['boundary_file']['error']));
    }
    
    $ext = strtolower(pathinfo($_FILES['boundary_file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        throw new Exception('File must be a .csv file');
    }
    
    // Ensure tables exist (before transaction - DDL causes implicit commit)
    ensureBoundaryTables($pdo);
    ensureImportLogTable($pdo);
    
    // Stream file line-by-line using SplFileObject
    $file = new SplFileObject($_FILES['boundary_file']['tmp_name'], 'r');
    $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
    
    $pdo->beginTransaction();
    
    $detectedState = null;
    $batch = [];
    $batchSize = 1000;
    
    // Track ZIP5 complexity factors
    $zipCounties = [];      // ZIP5 => [county_fips => true]
    $zipHasCityCode = [];   // ZIP5 => true (if has city/special code)
    
    foreach ($file as $lineNum => $row) {
        if (!is_array($row) || count($row) < 26) {
            $stats['skipped']++;
            continue;
        }
        
        // Boundary file format:
        // Col 0: record type (4 = boundary record)
        // Col 1: start date (YYYYMMDD)
        // Col 2: end date (YYYYMMDD)
        // Cols 3-16: various codes (mostly unused)
        // Col 17: ZIP5 start
        // Col 18: ZIP4 start
        // Col 19: ZIP5 end
        // Col 20: ZIP4 end
        // Col 21: unused
        // Col 22: state FIPS (2 digits)
        // Col 23: state FIPS duplicate
        // Col 24: county FIPS (3 digits)
        // Col 25: jurisdiction code (city/special district, empty = county only)
        
        $recordType = trim($row[0] ?? '');
        if ($recordType !== '4') {
            $stats['skipped']++;
            continue;
        }
        
        $startDate = trim($row[1] ?? '');
        $endDate = trim($row[2] ?? '');
        $zip5Start = trim($row[17] ?? '');
        $zip4Start = trim($row[18] ?? '');
        $zip5End = trim($row[19] ?? '');
        $zip4End = trim($row[20] ?? '');
        $stateFips = str_pad(trim($row[22] ?? ''), 2, '0', STR_PAD_LEFT);
        $countyFips = str_pad(trim($row[24] ?? ''), 3, '0', STR_PAD_LEFT);
        $jurisdictionCode = trim($row[25] ?? '');
        
        // Get city FIPS if present (column 23 in some formats)
        $cityFips = trim($row[23] ?? '');
        if ($cityFips === $stateFips) {
            $cityFips = ''; // Duplicate of state, not city
        }
        
        // Skip invalid rows
        if (empty($zip5Start) || strlen($zip5Start) !== 5) {
            $stats['skipped']++;
            continue;
        }
        
        // First data row - detect state and clear existing data
        if ($detectedState === null) {
            $detectedState = $stateFips;
            $pdo->prepare('DELETE FROM tax_boundaries WHERE state_fips = ?')->execute([$stateFips]);
            $pdo->prepare('DELETE FROM tax_zip_complexity WHERE state_fips = ?')->execute([$stateFips]);
        }
        
        // Format dates
        $startDateFmt = !empty($startDate) ? substr($startDate, 0, 4) . '-' . substr($startDate, 4, 2) . '-' . substr($startDate, 6, 2) : null;
        $endDateFmt = !empty($endDate) && $endDate !== '99991231' ? substr($endDate, 0, 4) . '-' . substr($endDate, 4, 2) . '-' . substr($endDate, 6, 2) : null;
        
        // Add to batch
        $batch[] = [
            'zip5_start' => $zip5Start,
            'zip4_start' => $zip4Start ?: '0000',
            'zip5_end' => $zip5End ?: $zip5Start,
            'zip4_end' => $zip4End ?: '9999',
            'state_fips' => $stateFips,
            'county_fips' => $countyFips,
            'city_fips' => $cityFips ?: null,
            'jurisdiction_code' => $jurisdictionCode ?: null,
            'start_date' => $startDateFmt,
            'end_date' => $endDateFmt
        ];
        
        // Track complexity factors
        // 1. Track counties per ZIP5
        if (!isset($zipCounties[$zip5Start])) {
            $zipCounties[$zip5Start] = [];
        }
        $zipCounties[$zip5Start][$countyFips] = true;
        
        // Also track for ZIP5 range if different
        if ($zip5End && $zip5End !== $zip5Start) {
            for ($z = (int)$zip5Start; $z <= (int)$zip5End && $z <= (int)$zip5Start + 100; $z++) {
                $zPadded = str_pad($z, 5, '0', STR_PAD_LEFT);
                if (!isset($zipCounties[$zPadded])) {
                    $zipCounties[$zPadded] = [];
                }
                $zipCounties[$zPadded][$countyFips] = true;
            }
        }
        
        // 2. Track if ZIP has city/special district codes
        if (!empty($jurisdictionCode) || !empty($cityFips)) {
            $zipHasCityCode[$zip5Start] = true;
            if ($zip5End && $zip5End !== $zip5Start) {
                for ($z = (int)$zip5Start; $z <= (int)$zip5End && $z <= (int)$zip5Start + 100; $z++) {
                    $zipHasCityCode[str_pad($z, 5, '0', STR_PAD_LEFT)] = true;
                }
            }
        }
        
        // Flush batch
        if (count($batch) >= $batchSize) {
            $inserted = flushBoundaryBatch($pdo, $batch);
            $stats['boundaries_imported'] += $inserted;
            $batch = [];
        }
    }
    
    // Flush remaining boundaries
    if (!empty($batch)) {
        $inserted = flushBoundaryBatch($pdo, $batch);
        $stats['boundaries_imported'] += $inserted;
    }
    
    // Build ZIP complexity table
    $complexityBatch = [];
    
    foreach ($zipCounties as $zip5 => $counties) {
        $isMultiCounty = count($counties) > 1;
        $hasCityCode = isset($zipHasCityCode[$zip5]);
        
        if ($isMultiCounty || $hasCityCode) {
            $reasons = [];
            if ($isMultiCounty) {
                $reasons[] = 'multi_county';
                $stats['multi_county_zips']++;
            }
            if ($hasCityCode) {
                $reasons[] = 'city_or_special';
            }
            
            $complexityBatch[] = [
                'zip5' => $zip5,
                'is_complex' => 1,
                'reason' => implode(',', $reasons),
                'state_fips' => $detectedState
            ];
            $stats['complex_zips']++;
        }
        
        // Flush complexity batch
        if (count($complexityBatch) >= 500) {
            flushComplexityBatch($pdo, $complexityBatch);
            $complexityBatch = [];
        }
    }
    
    // Flush remaining complexity records
    if (!empty($complexityBatch)) {
        flushComplexityBatch($pdo, $complexityBatch);
    }
    
    // Log the import (table already created before transaction)
    $stmt = $pdo->prepare('INSERT INTO tax_import_log (import_type, state_fips, records_imported, filename) VALUES (?, ?, ?, ?)');
    $stmt->execute(['boundaries', $detectedState, $stats['boundaries_imported'], basename($_FILES['boundary_file']['name'])]);
    
    $pdo->commit();
    
    // Build summary
    $summary = "Boundary Import Summary\n";
    $summary .= str_repeat("-", 40) . "\n\n";
    $summary .= "🗺️ Boundary records imported: " . number_format($stats['boundaries_imported']) . "\n";
    $summary .= "🔍 Complex ZIPs flagged: " . number_format($stats['complex_zips']) . "\n";
    $summary .= "   - Multi-county ZIPs: " . number_format($stats['multi_county_zips']) . "\n";
    $summary .= "   - City/special district ZIPs: " . (count($zipHasCityCode)) . "\n";
    $summary .= "⏭️ Skipped rows: " . number_format($stats['skipped']) . "\n\n";
    $summary .= "✅ Boundary import completed!";
    
    $_SESSION['boundaries_import_summary'] = $summary;
    
    header('Location: /?page=settings&tab=taxes&boundaries_success=1');
    exit;
    
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[boundaries-import] Error: ' . $e->getMessage());
    header('Location: /?page=settings&tab=taxes&boundaries_error=' . rawurlencode($e->getMessage()));
    exit;
}

function flushBoundaryBatch(PDO $pdo, array $batch): int {
    if (empty($batch)) return 0;
    
    $placeholders = [];
    $values = [];
    foreach ($batch as $row) {
        $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $values = array_merge($values, [
            $row['zip5_start'],
            $row['zip4_start'],
            $row['zip5_end'],
            $row['zip4_end'],
            $row['state_fips'],
            $row['county_fips'],
            $row['city_fips'],
            $row['jurisdiction_code'],
            $row['start_date'],
            $row['end_date']
        ]);
    }
    
    $sql = 'INSERT INTO tax_boundaries 
        (zip5_start, zip4_start, zip5_end, zip4_end, state_fips, county_fips, city_fips, jurisdiction_code, start_date, end_date)
        VALUES ' . implode(', ', $placeholders);
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    
    return count($batch);
}

function flushComplexityBatch(PDO $pdo, array $batch): void {
    if (empty($batch)) return;
    
    $placeholders = [];
    $values = [];
    foreach ($batch as $row) {
        $placeholders[] = '(?, ?, ?, ?)';
        $values = array_merge($values, [
            $row['zip5'],
            $row['is_complex'],
            $row['reason'],
            $row['state_fips']
        ]);
    }
    
    $sql = 'INSERT INTO tax_zip_complexity (zip5, is_complex, reason, state_fips)
        VALUES ' . implode(', ', $placeholders) . '
        ON DUPLICATE KEY UPDATE is_complex = VALUES(is_complex), reason = VALUES(reason)';
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
}

function ensureImportLogTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_import_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        import_type ENUM('fips', 'rates', 'boundaries') NOT NULL,
        state_fips VARCHAR(2) DEFAULT NULL,
        records_imported INT NOT NULL DEFAULT 0,
        imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        filename VARCHAR(255) DEFAULT NULL,
        INDEX idx_type (import_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensureBoundaryTables(PDO $pdo): void {
    // tax_boundaries with city_fips column
    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_boundaries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        zip5_start VARCHAR(5) NOT NULL,
        zip4_start VARCHAR(4) NOT NULL,
        zip5_end VARCHAR(5) NOT NULL,
        zip4_end VARCHAR(4) NOT NULL,
        state_fips VARCHAR(2) NOT NULL,
        county_fips VARCHAR(3) NOT NULL,
        city_fips VARCHAR(10) DEFAULT NULL,
        jurisdiction_code VARCHAR(10) DEFAULT NULL,
        start_date DATE DEFAULT NULL,
        end_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_zip5 (zip5_start),
        INDEX idx_zip5_range (zip5_start, zip5_end),
        INDEX idx_state (state_fips),
        INDEX idx_county (state_fips, county_fips)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Add city_fips column if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE tax_boundaries ADD COLUMN city_fips VARCHAR(10) DEFAULT NULL AFTER county_fips");
    } catch (PDOException $e) {
        // Column already exists, ignore
    }
    
    // tax_zip_complexity
    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_zip_complexity (
        zip5 VARCHAR(5) PRIMARY KEY,
        is_complex TINYINT(1) NOT NULL DEFAULT 0,
        reason VARCHAR(50) DEFAULT NULL,
        state_fips VARCHAR(2) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_state (state_fips)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function getUploadErrorMessage(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'File is too large';
        case UPLOAD_ERR_PARTIAL:
            return 'File was only partially uploaded';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Server configuration error (missing temp directory)';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Failed to write file to disk';
        default:
            return 'Unknown upload error';
    }
}
