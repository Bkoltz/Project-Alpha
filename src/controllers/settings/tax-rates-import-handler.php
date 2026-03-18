<?php
// src/controllers/settings/tax-rates-import-handler.php
// Handles importing tax rates from ZIP (FIPS + boundary files) + SSTGB CSV

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
    header('Location: /?page=settings&tab=taxes&import_error=' . rawurlencode('Invalid request (CSRF)'));
    exit;
}

// State FIPS code mapping (for state abbreviations)
$stateFipsToAbbr = [
    '01' => 'AL', '02' => 'AK', '04' => 'AZ', '05' => 'AR', '06' => 'CA',
    '08' => 'CO', '09' => 'CT', '10' => 'DE', '11' => 'DC', '12' => 'FL',
    '13' => 'GA', '15' => 'HI', '16' => 'ID', '17' => 'IL', '18' => 'IN',
    '19' => 'IA', '20' => 'KS', '21' => 'KY', '22' => 'LA', '23' => 'ME',
    '24' => 'MD', '25' => 'MA', '26' => 'MI', '27' => 'MN', '28' => 'MS',
    '29' => 'MO', '30' => 'MT', '31' => 'NE', '32' => 'NV', '33' => 'NH',
    '34' => 'NJ', '35' => 'NM', '36' => 'NY', '37' => 'NC', '38' => 'ND',
    '39' => 'OH', '40' => 'OK', '41' => 'OR', '42' => 'PA', '44' => 'RI',
    '45' => 'SC', '46' => 'SD', '47' => 'TN', '48' => 'TX', '49' => 'UT',
    '50' => 'VT', '51' => 'VA', '53' => 'WA', '54' => 'WV', '55' => 'WI',
    '56' => 'WY', '72' => 'PR', '78' => 'VI'
];

$tempDir = null;

try {
    // Initialize stats
    $stats = [
        'fips_imported' => 0,
        'boundaries_imported' => 0,
        'jurisdictions_inserted' => 0,
        'jurisdictions_updated' => 0,
        'jurisdictions_skipped_inactive' => 0,
        'complex_zips' => 0,
        'unknown_fips' => [],
        'errors' => []
    ];
    
    // Validate file uploads
    if (!isset($_FILES['zip_file']) || $_FILES['zip_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Please upload a ZIP file containing FIPS and boundary files');
    }
    if (!isset($_FILES['rate_file']) || $_FILES['rate_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Please upload the SSTGB rate file (.csv)');
    }
    
    if ($_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error uploading ZIP file: ' . getUploadErrorMessage($_FILES['zip_file']['error']));
    }
    if ($_FILES['rate_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error uploading rate file: ' . getUploadErrorMessage($_FILES['rate_file']['error']));
    }
    
    // Validate file extensions
    $zipExt = strtolower(pathinfo($_FILES['zip_file']['name'], PATHINFO_EXTENSION));
    $rateExt = strtolower(pathinfo($_FILES['rate_file']['name'], PATHINFO_EXTENSION));
    
    if ($zipExt !== 'zip') {
        throw new Exception('First file must be a .zip file');
    }
    if ($rateExt !== 'csv') {
        throw new Exception('Rate file must be a .csv file');
    }
    
    // Create temp directory for extraction
    $tempDir = sys_get_temp_dir() . '/tax_import_' . uniqid();
    if (!mkdir($tempDir, 0755, true)) {
        throw new Exception('Could not create temporary directory');
    }
    
    // Extract ZIP file
    $zip = new ZipArchive();
    if ($zip->open($_FILES['zip_file']['tmp_name']) !== true) {
        throw new Exception('Could not open ZIP file');
    }
    $zip->extractTo($tempDir);
    $zip->close();
    
    // Find FIPS file (pipe-delimited TXT with county data)
    // Find boundary file (CSV with ZIP+4 mappings)
    $fipsFile = null;
    $boundaryFile = null;
    
    $extractedFiles = glob($tempDir . '/*');
    foreach ($extractedFiles as $file) {
        if (is_file($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $basename = basename($file);
            
            // FIPS file: usually named like st55_wi_cou2020.txt (contains "cou" for county)
            if ($ext === 'txt' && (stripos($basename, 'cou') !== false || stripos($basename, 'county') !== false)) {
                $fipsFile = $file;
            }
            // Boundary file: CSV with "B" in name (like WIB032026.csv)
            elseif ($ext === 'csv' && preg_match('/[A-Z]{2}B\d+\.csv$/i', $basename)) {
                $boundaryFile = $file;
            }
            // Fallback: any TXT could be FIPS
            elseif ($ext === 'txt' && $fipsFile === null) {
                // Check if it looks like FIPS format (pipe-delimited)
                $firstLine = fgets(fopen($file, 'r'));
                if (strpos($firstLine, '|') !== false) {
                    $fipsFile = $file;
                }
            }
            // Fallback: any CSV in ZIP could be boundary
            elseif ($ext === 'csv' && $boundaryFile === null) {
                $boundaryFile = $file;
            }
        }
    }
    
    if (!$fipsFile) {
        throw new Exception('Could not find FIPS county file in ZIP. Expected a pipe-delimited .txt file with county data.');
    }
    
    // Boundary file is optional - some imports may only have FIPS + rates
    $hasBoundaryFile = ($boundaryFile !== null);
    
    // Ensure required tables exist
    ensureTablesExist($pdo);
    
    // Begin transaction
    $pdo->beginTransaction();
    
    // ========================================
    // STEP 1: Parse FIPS County File
    // ========================================
    $fipsLookup = [];
    $fipsContent = file_get_contents($fipsFile);
    if ($fipsContent === false) {
        throw new Exception('Could not read FIPS file');
    }
    
    $fipsLines = explode("\n", $fipsContent);
    foreach ($fipsLines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $parts = explode('|', $line);
        if (count($parts) < 5) continue;
        
        $stateAbbr = trim($parts[0]);
        
        // Skip header row
        if (strtoupper($stateAbbr) === 'STATE') continue;
        
        $stateFips = str_pad(trim($parts[1]), 2, '0', STR_PAD_LEFT);
        $countyFips = str_pad(trim($parts[2]), 3, '0', STR_PAD_LEFT);
        $countyName = trim($parts[4]);
        
        // Remove " County" suffix
        $countyName = preg_replace('/\s+County$/i', '', $countyName);
        
        $key = $stateFips . $countyFips;
        $fipsLookup[$key] = [
            'state_abbr' => $stateAbbr,
            'state_fips' => $stateFips,
            'county_fips' => $countyFips,
            'county_name' => $countyName
        ];
        
        // Insert/update fips_counties table
        $stmt = $pdo->prepare('INSERT INTO fips_counties (state_fips, county_fips, state_abbr, county_name) 
                               VALUES (?, ?, ?, ?) 
                               ON DUPLICATE KEY UPDATE state_abbr = VALUES(state_abbr), county_name = VALUES(county_name)');
        $stmt->execute([$stateFips, $countyFips, $stateAbbr, $countyName]);
        $stats['fips_imported']++;
    }
    
    if (empty($fipsLookup)) {
        throw new Exception('No valid FIPS records found in the file');
    }
    
    // Get the state FIPS from the first entry (assume all are same state)
    $importStateFips = reset($fipsLookup)['state_fips'];
    
    // ========================================
    // STEP 2: Parse Boundary File (if exists)
    // ========================================
    $complexZips = []; // Track ZIP5s that have city/special district codes
    
    if ($hasBoundaryFile) {
        // Clear existing boundaries for this state
        $pdo->prepare('DELETE FROM tax_boundaries WHERE state_fips = ?')->execute([$importStateFips]);
        
        $boundaryHandle = fopen($boundaryFile, 'r');
        if ($boundaryHandle) {
            while (($row = fgetcsv($boundaryHandle)) !== false) {
                // Skip rows that don't have enough columns
                if (count($row) < 26) continue;
                
                // Parse boundary row format:
                // Col 0: record type (4)
                // Col 1: start date (YYYYMMDD)
                // Col 2: end date (YYYYMMDD)
                // Cols 3-16: empty/unused
                // Col 17: ZIP5 start
                // Col 18: ZIP4 start
                // Col 19: ZIP5 end
                // Col 20: ZIP4 end
                // Col 21: empty
                // Col 22: state FIPS
                // Col 23: state FIPS (duplicate)
                // Col 24: county FIPS
                // Col 25: jurisdiction code (city/special district)
                
                $recordType = trim($row[0] ?? '');
                if ($recordType !== '4') continue; // Only process type 4 records
                
                $startDate = trim($row[1] ?? '');
                $endDate = trim($row[2] ?? '');
                $zip5Start = trim($row[17] ?? '');
                $zip4Start = trim($row[18] ?? '');
                $zip5End = trim($row[19] ?? '');
                $zip4End = trim($row[20] ?? '');
                $stateFips = str_pad(trim($row[22] ?? ''), 2, '0', STR_PAD_LEFT);
                $countyFips = str_pad(trim($row[24] ?? ''), 3, '0', STR_PAD_LEFT);
                $jurisdictionCode = trim($row[25] ?? '');
                
                // Skip invalid rows
                if (empty($zip5Start) || strlen($zip5Start) !== 5) continue;
                
                // Format dates
                $startDateFmt = !empty($startDate) ? substr($startDate, 0, 4) . '-' . substr($startDate, 4, 2) . '-' . substr($startDate, 6, 2) : null;
                $endDateFmt = !empty($endDate) && $endDate !== '99991231' ? substr($endDate, 0, 4) . '-' . substr($endDate, 4, 2) . '-' . substr($endDate, 6, 2) : null;
                
                // Insert boundary record
                $stmt = $pdo->prepare('INSERT INTO tax_boundaries 
                    (zip5_start, zip4_start, zip5_end, zip4_end, state_fips, county_fips, jurisdiction_code, start_date, end_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $zip5Start, 
                    $zip4Start ?: '0000', 
                    $zip5End ?: $zip5Start, 
                    $zip4End ?: '9999',
                    $stateFips, 
                    $countyFips, 
                    $jurisdictionCode ?: null,
                    $startDateFmt,
                    $endDateFmt
                ]);
                $stats['boundaries_imported']++;
                
                // Track complex ZIPs (those with jurisdiction codes = city/special district)
                if (!empty($jurisdictionCode)) {
                    $complexZips[$zip5Start] = 'city_or_special';
                    if ($zip5End && $zip5End !== $zip5Start) {
                        // Mark all ZIP5s in range as complex
                        for ($z = (int)$zip5Start; $z <= (int)$zip5End; $z++) {
                            $complexZips[str_pad($z, 5, '0', STR_PAD_LEFT)] = 'city_or_special';
                        }
                    }
                }
            }
            fclose($boundaryHandle);
        }
    }
    
    // ========================================
    // STEP 3: Build ZIP Complexity Table
    // ========================================
    if (!empty($complexZips)) {
        // Clear existing complexity data for this state
        $pdo->prepare('DELETE FROM tax_zip_complexity WHERE state_fips = ?')->execute([$importStateFips]);
        
        $stmt = $pdo->prepare('INSERT INTO tax_zip_complexity (zip5, is_complex, reason, state_fips) VALUES (?, 1, ?, ?)');
        foreach ($complexZips as $zip5 => $reason) {
            $stmt->execute([$zip5, $reason, $importStateFips]);
            $stats['complex_zips']++;
        }
    }
    
    // ========================================
    // STEP 4: Parse SSTGB Rate CSV
    // ========================================
    $rateContent = file_get_contents($_FILES['rate_file']['tmp_name']);
    if ($rateContent === false) {
        throw new Exception('Could not read rate file');
    }
    
    $today = date('Ymd');
    $rateLines = explode("\n", $rateContent);
    
    // Clear existing jurisdictions for this state (to avoid duplicates)
    $pdo->prepare('DELETE FROM tax_jurisdictions WHERE state_fips = ?')->execute([$importStateFips]);
    
    foreach ($rateLines as $lineNum => $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $parts = str_getcsv($line);
        if (count($parts) < 9) {
            $stats['errors'][] = "Rate line " . ($lineNum + 1) . ": Invalid format";
            continue;
        }
        
        // SSTGB CSV Format:
        // Col 0: state FIPS
        // Col 1: county FIPS (00 = state-level which we skip)
        // Col 2: jurisdiction code
        // Col 3-6: rate columns (state, county, city, special) - SUM ALL FOUR
        // Col 7: start date
        // Col 8: end date
        
        $stateFips = str_pad(trim($parts[0]), 2, '0', STR_PAD_LEFT);
        $countyFips = str_pad(trim($parts[1]), 2, '0', STR_PAD_LEFT); // Note: 2 digits in CSV
        $jurisdictionCode = trim($parts[2]);
        $rate1 = (float)$parts[3];
        $rate2 = (float)$parts[4];
        $rate3 = (float)$parts[5];
        $rate4 = (float)$parts[6];
        $startDate = trim($parts[7]);
        $endDate = trim($parts[8]);
        
        // Validate date range - only import currently active rates
        if ($startDate !== '' && $endDate !== '') {
            if ($today < $startDate || $today > $endDate) {
                $stats['jurisdictions_skipped_inactive']++;
                continue;
            }
        }
        
        // Sum all 4 rate columns and convert to percentage
        // SSTGB format: rates are decimals (0.005 = 0.5%)
        // Wisconsin spreads local rate across all 4 columns
        $totalLocalRate = ($rate1 + $rate2 + $rate3 + $rate4) * 100;
        $totalLocalRate = round($totalLocalRate, 4);
        
        // Determine jurisdiction type
        if ($countyFips === '00') {
            // State-level rate - we'll use this differently
            $jurisdictionType = 'state';
            $countyFipsNorm = '000';
        } else {
            // Normalize county FIPS to 3 digits
            $countyFipsNorm = str_pad($countyFips, 3, '0', STR_PAD_LEFT);
            
            // Check if this is a city/special based on jurisdiction code length
            if (strlen($jurisdictionCode) > 3) {
                $jurisdictionType = 'city';
            } else {
                $jurisdictionType = 'county';
            }
        }
        
        // Lookup county name
        $lookupKey = $stateFips . $countyFipsNorm;
        $countyName = $fipsLookup[$lookupKey]['county_name'] ?? null;
        $stateAbbr = $fipsLookup[$lookupKey]['state_abbr'] ?? ($stateFipsToAbbr[$stateFips] ?? 'XX');
        
        if (!$countyName && $jurisdictionType !== 'state') {
            $stats['unknown_fips'][] = "State {$stateFips}, County {$countyFipsNorm}, Code {$jurisdictionCode}";
            continue;
        }
        
        // Build jurisdiction name
        if ($jurisdictionType === 'state') {
            $name = $stateAbbr . ' State Tax';
        } elseif ($jurisdictionType === 'city') {
            $name = $countyName . ' - ' . $jurisdictionCode . ', ' . $stateAbbr;
        } else {
            $name = $countyName . ', ' . $stateAbbr;
        }
        
        // Format dates for DB
        $startDateFmt = !empty($startDate) ? substr($startDate, 0, 4) . '-' . substr($startDate, 4, 2) . '-' . substr($startDate, 6, 2) : null;
        $endDateFmt = !empty($endDate) && $endDate !== '99991231' ? substr($endDate, 0, 4) . '-' . substr($endDate, 4, 2) . '-' . substr($endDate, 6, 2) : null;
        
        // Check for existing jurisdiction
        $existingStmt = $pdo->prepare('SELECT id FROM tax_jurisdictions WHERE state_fips = ? AND county_fips = ? AND (jurisdiction_code = ? OR (jurisdiction_code IS NULL AND ? IS NULL)) LIMIT 1');
        $existingStmt->execute([$stateFips, $countyFipsNorm, $jurisdictionCode ?: null, $jurisdictionCode ?: null]);
        $existingId = $existingStmt->fetchColumn();
        
        if ($existingId) {
            // Update
            $stmt = $pdo->prepare('UPDATE tax_jurisdictions SET 
                name = ?, jurisdiction_type = ?, 
                state_rate = ?, county_rate = ?, city_rate = ?, special_rate = ?, total_rate = ?,
                start_date = ?, end_date = ?, is_active = 1, updated_at = NOW()
                WHERE id = ?');
            $stmt->execute([
                $name, $jurisdictionType,
                $rate1 * 100, $rate2 * 100, $rate3 * 100, $rate4 * 100, $totalLocalRate,
                $startDateFmt, $endDateFmt,
                $existingId
            ]);
            $stats['jurisdictions_updated']++;
        } else {
            // Insert
            $stmt = $pdo->prepare('INSERT INTO tax_jurisdictions 
                (name, state_fips, county_fips, jurisdiction_code, jurisdiction_type,
                 state_rate, county_rate, city_rate, special_rate, total_rate,
                 start_date, end_date, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
            $stmt->execute([
                $name, $stateFips, $countyFipsNorm, $jurisdictionCode ?: null, $jurisdictionType,
                $rate1 * 100, $rate2 * 100, $rate3 * 100, $rate4 * 100, $totalLocalRate,
                $startDateFmt, $endDateFmt
            ]);
            $stats['jurisdictions_inserted']++;
        }
        
        // Also update the legacy tax_rates table for backward compatibility
        if ($jurisdictionType === 'county' && $countyName) {
            $rateName = $countyName . ', ' . $stateAbbr;
            
            $existingRate = $pdo->prepare('SELECT id FROM tax_rates WHERE name = ? LIMIT 1');
            $existingRate->execute([$rateName]);
            $existingRateId = $existingRate->fetchColumn();
            
            // Check if is_default column exists
            $hasDefault = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tax_rates' AND COLUMN_NAME='is_default'")->fetchColumn();
            
            if ($existingRateId) {
                $pdo->prepare('UPDATE tax_rates SET rate = ?, is_active = 1 WHERE id = ?')
                    ->execute([$totalLocalRate, $existingRateId]);
            } else {
                if ($hasDefault) {
                    $pdo->prepare('INSERT INTO tax_rates (name, country, state, county, rate, is_active, is_default) VALUES (?, ?, ?, ?, ?, 1, 0)')
                        ->execute([$rateName, 'USA', $stateAbbr, $countyName, $totalLocalRate]);
                } else {
                    $pdo->prepare('INSERT INTO tax_rates (name, country, state, county, rate, is_active) VALUES (?, ?, ?, ?, ?, 1)')
                        ->execute([$rateName, 'USA', $stateAbbr, $countyName, $totalLocalRate]);
                }
            }
        }
    }
    
    $pdo->commit();
    
    // Cleanup temp directory
    cleanupTempDir($tempDir);
    
    // Build and store summary
    $summary = buildImportSummary($stats);
    $_SESSION['tax_import_summary'] = $summary;
    $_SESSION['tax_import_stats'] = $stats;
    
    header('Location: /?page=settings&tab=taxes&import_success=1');
    exit;
    
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Cleanup temp directory on error
    if ($tempDir) {
        cleanupTempDir($tempDir);
    }
    
    error_log('[tax-rates-import] Error: ' . $e->getMessage());
    header('Location: /?page=settings&tab=taxes&import_error=' . rawurlencode($e->getMessage()));
    exit;
}

/**
 * Ensure required tables exist
 */
function ensureTablesExist(PDO $pdo): void {
    // fips_counties
    $pdo->exec("CREATE TABLE IF NOT EXISTS fips_counties (
        id INT AUTO_INCREMENT PRIMARY KEY,
        state_fips VARCHAR(2) NOT NULL,
        county_fips VARCHAR(3) NOT NULL,
        state_abbr VARCHAR(2) NOT NULL,
        county_name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_fips (state_fips, county_fips)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // tax_jurisdictions
    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_jurisdictions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        state_fips VARCHAR(2) NOT NULL,
        county_fips VARCHAR(3) NOT NULL,
        jurisdiction_code VARCHAR(10) DEFAULT NULL,
        jurisdiction_type ENUM('state', 'county', 'city', 'special') NOT NULL DEFAULT 'county',
        state_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
        county_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
        city_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
        special_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
        total_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
        start_date DATE DEFAULT NULL,
        end_date DATE DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // tax_boundaries
    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_boundaries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        zip5_start VARCHAR(5) NOT NULL,
        zip4_start VARCHAR(4) NOT NULL,
        zip5_end VARCHAR(5) NOT NULL,
        zip4_end VARCHAR(4) NOT NULL,
        state_fips VARCHAR(2) NOT NULL,
        county_fips VARCHAR(3) NOT NULL,
        jurisdiction_code VARCHAR(10) DEFAULT NULL,
        start_date DATE DEFAULT NULL,
        end_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_zip5 (zip5_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // tax_zip_complexity
    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_zip_complexity (
        zip5 VARCHAR(5) PRIMARY KEY,
        is_complex TINYINT(1) NOT NULL DEFAULT 0,
        reason VARCHAR(50) DEFAULT NULL,
        state_fips VARCHAR(2) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Build human-readable import summary
 */
function buildImportSummary(array $stats): string {
    $lines = [
        "Tax Rate Import Summary",
        str_repeat("-", 50),
        "",
        "📍 FIPS Counties:",
        "   Imported: " . $stats['fips_imported'],
        "",
        "🗺️ Boundary Records:",
        "   Imported: " . $stats['boundaries_imported'],
        "",
        "💰 Tax Jurisdictions:",
        "   Inserted: " . $stats['jurisdictions_inserted'],
        "   Updated: " . $stats['jurisdictions_updated'],
        "   Skipped (inactive): " . $stats['jurisdictions_skipped_inactive'],
        "",
        "🔍 ZIP Complexity:",
        "   Complex ZIPs flagged: " . $stats['complex_zips'],
    ];
    
    if (!empty($stats['unknown_fips'])) {
        $lines[] = "";
        $lines[] = "⚠️ Unknown FIPS Codes (" . count($stats['unknown_fips']) . "):";
        foreach (array_slice($stats['unknown_fips'], 0, 10) as $fips) {
            $lines[] = "   - " . $fips;
        }
        if (count($stats['unknown_fips']) > 10) {
            $lines[] = "   ... and " . (count($stats['unknown_fips']) - 10) . " more";
        }
    }
    
    if (!empty($stats['errors'])) {
        $lines[] = "";
        $lines[] = "❌ Errors (" . count($stats['errors']) . "):";
        foreach (array_slice($stats['errors'], 0, 5) as $err) {
            $lines[] = "   - " . $err;
        }
        if (count($stats['errors']) > 5) {
            $lines[] = "   ... and " . (count($stats['errors']) - 5) . " more";
        }
    }
    
    $lines[] = "";
    $total = $stats['jurisdictions_inserted'] + $stats['jurisdictions_updated'];
    if ($total > 0) {
        $lines[] = "✅ Import completed successfully!";
    } else {
        $lines[] = "⚠️ No jurisdictions were imported. Please check your files.";
    }
    
    return implode("\n", $lines);
}

/**
 * Get human-readable upload error message
 */
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
        case UPLOAD_ERR_EXTENSION:
            return 'Upload blocked by extension';
        default:
            return 'Unknown upload error';
    }
}

/**
 * Recursively delete temp directory
 */
function cleanupTempDir(string $dir): void {
    if (!is_dir($dir)) return;
    
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? cleanupTempDir($path) : unlink($path);
    }
    rmdir($dir);
}
