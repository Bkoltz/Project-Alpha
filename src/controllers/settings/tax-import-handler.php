<?php
// src/controllers/settings/tax-import-handler.php
// Combined FIPS + Rates import using staging tables with JOIN

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

try {
    $stats = [
        'counties_loaded' => 0,
        'rates_loaded' => 0,
        'rates_matched' => 0,
        'skipped_inactive' => 0,
        'skipped_city' => 0,
        'skipped_no_match' => 0
    ];
    
    // Validate both file uploads
    if (!isset($_FILES['fips_file']) || $_FILES['fips_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Please upload a FIPS county file (.txt)');
    }
    if ($_FILES['fips_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('FIPS file upload error: ' . getUploadErrorMessage($_FILES['fips_file']['error']));
    }
    
    if (!isset($_FILES['rate_file']) || $_FILES['rate_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Please upload a tax rates file (.csv)');
    }
    if ($_FILES['rate_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Rate file upload error: ' . getUploadErrorMessage($_FILES['rate_file']['error']));
    }
    
    // Get state tax rate from form (default 5% for Wisconsin)
    $stateTaxRate = (float)($_POST['state_tax_rate'] ?? 5.0);
    $today = date('Ymd');
    
    // ============================================
    // Create staging tables (drop and recreate to ensure clean slate)
    // ============================================
    $pdo->exec("DROP TABLE IF EXISTS tax_import_fips");
    $pdo->exec("DROP TABLE IF EXISTS tax_import_rates");
    
    $pdo->exec("CREATE TABLE tax_import_fips (
        id INT AUTO_INCREMENT PRIMARY KEY,
        state_abbr VARCHAR(2) NOT NULL,
        state_fips VARCHAR(2) NOT NULL,
        county_fips VARCHAR(3) NOT NULL,
        county_name VARCHAR(100) NOT NULL,
        UNIQUE KEY uk_fips (state_fips, county_fips)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $pdo->exec("CREATE TABLE tax_import_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        state_fips VARCHAR(2) NOT NULL,
        county_fips VARCHAR(3) NOT NULL,
        local_rate DECIMAL(8,4) NOT NULL,
        UNIQUE KEY uk_rates (state_fips, county_fips)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // ============================================
    // STEP 1: Load FIPS file into staging table
    // ============================================
    // Format: STATE|STATEFP|COUNTYFP|COUNTYNS|COUNTYNAME|CLASSFP|FUNCSTAT
    // Example: WI|55|001|01581060|Adams County|H1|A
    
    $fipsInsert = $pdo->prepare('INSERT IGNORE INTO tax_import_fips (state_abbr, state_fips, county_fips, county_name) VALUES (?, ?, ?, ?)');
    
    $fipsFile = new SplFileObject($_FILES['fips_file']['tmp_name'], 'r');
    $fipsFile->setFlags(SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
    
    $detectedStateAbbr = '';
    $headerMap = null; // Will map column names to indices
    
    foreach ($fipsFile as $lineNum => $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Remove BOM if present
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        
        $parts = explode('|', $line);
        if (count($parts) < 5) continue;
        
        // First row with pipe delimiter = header row
        $col0Upper = strtoupper(trim($parts[0]));
        if ($col0Upper === 'STATE' || $col0Upper === 'USPS') {
            // Build header map: column name -> index
            $headerMap = [];
            foreach ($parts as $idx => $colName) {
                $headerMap[strtoupper(trim($colName))] = $idx;
            }
            continue;
        }
        
        // Use header map if available, otherwise use default indices
        $stateAbbrIdx = $headerMap['STATE'] ?? $headerMap['USPS'] ?? 0;
        $stateFipsIdx = $headerMap['STATEFP'] ?? 1;
        $countyFipsIdx = $headerMap['COUNTYFP'] ?? 2;
        $countyNameIdx = $headerMap['COUNTYNAME'] ?? 4;
        
        $stateAbbr = trim($parts[$stateAbbrIdx] ?? '');       // "WI"
        $stateFips = trim($parts[$stateFipsIdx] ?? '');       // "55"
        $countyFipsRaw = trim($parts[$countyFipsIdx] ?? '');  // "001"
        $countyNameRaw = trim($parts[$countyNameIdx] ?? '');  // "Adams County"
        
        // Validate we have actual data
        if (empty($stateAbbr) || empty($stateFips) || empty($countyFipsRaw) || empty($countyNameRaw)) {
            continue;
        }
        
        // Pad FIPS codes
        $stateFips = str_pad($stateFips, 2, '0', STR_PAD_LEFT);
        $countyFips = str_pad($countyFipsRaw, 3, '0', STR_PAD_LEFT);
        
        // Remove " County" suffix
        $countyName = preg_replace('/\s+County$/i', '', $countyNameRaw);
        
        $detectedStateAbbr = $stateAbbr;
        
        $fipsInsert->execute([$stateAbbr, $stateFips, $countyFips, $countyName]);
        $stats['counties_loaded']++;
    }
    
    if ($stats['counties_loaded'] === 0) {
        throw new Exception('No counties found in FIPS file. Check format (pipe-delimited with header: STATE|STATEFP|COUNTYFP|COUNTYNS|COUNTYNAME|...)');
    }
    
    // ============================================
    // STEP 2: Load Rates CSV into staging table
    // ============================================
    // County format: stateFips,00,countyFips,rate,rate,rate,rate,startDate,endDate
    // Example: 55,00,001,0.005,0.005,0.005,0.005,19940101,99991231
    // City format (col1 != "00"): SKIP
    
    $ratesInsert = $pdo->prepare('INSERT INTO tax_import_rates (state_fips, county_fips, local_rate) 
        VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE local_rate = VALUES(local_rate)');
    
    $rateFile = new SplFileObject($_FILES['rate_file']['tmp_name'], 'r');
    $rateFile->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
    
    foreach ($rateFile as $lineNum => $parts) {
        if (!is_array($parts) || count($parts) < 9) continue;
        
        $col0 = trim($parts[0]); // State FIPS (55)
        $col1 = trim($parts[1]); // "00" for county, or county code for city
        $col2 = trim($parts[2]); // County FIPS (001) when col1="00"
        $rate = (float)$parts[3]; // Local rate (0.005)
        $startDate = trim($parts[7]);
        $endDate = preg_replace('/[^0-9]/', '', trim($parts[8]));
        
        // ONLY process county-level rows (col1 = "00" or "0")
        if ($col1 !== '00' && $col1 !== '0') {
            $stats['skipped_city']++;
            continue;
        }
        
        // Skip inactive rates (outside date range)
        if ($startDate !== '' && $endDate !== '') {
            if ($today < $startDate || $today > $endDate) {
                $stats['skipped_inactive']++;
                continue;
            }
        }
        
        $stateFips = str_pad($col0, 2, '0', STR_PAD_LEFT);
        $countyFips = str_pad($col2, 3, '0', STR_PAD_LEFT);
        $localRate = $rate * 100; // Convert 0.005 to 0.5%
        
        $ratesInsert->execute([$stateFips, $countyFips, $localRate]);
        $stats['rates_loaded']++;
    }
    
    if ($stats['rates_loaded'] === 0) {
        throw new Exception('No active county rates found in rate file.');
    }
    
    // ============================================
    // STEP 3: JOIN and insert into tax_rates
    // ============================================
    
    // Check if is_default column exists
    $hasDefault = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tax_rates' AND COLUMN_NAME='is_default'")->fetchColumn();
    
    // Delete existing rates for this state
    $pdo->prepare('DELETE FROM tax_rates WHERE state = ?')->execute([$detectedStateAbbr]);
    
    // Join the two staging tables and insert matched records
    $joinSql = $hasDefault
        ? "INSERT INTO tax_rates (name, country, state, county, rate, is_active, is_default)
           SELECT 
               CONCAT(f.county_name, ', ', f.state_abbr) as name,
               'USA' as country,
               f.state_abbr as state,
               f.county_name as county,
               ROUND(r.local_rate + ?, 2) as rate,
               1 as is_active,
               0 as is_default
           FROM tax_import_fips f
           INNER JOIN tax_import_rates r 
               ON f.state_fips = r.state_fips AND f.county_fips = r.county_fips"
        : "INSERT INTO tax_rates (name, country, state, county, rate, is_active)
           SELECT 
               CONCAT(f.county_name, ', ', f.state_abbr) as name,
               'USA' as country,
               f.state_abbr as state,
               f.county_name as county,
               ROUND(r.local_rate + ?, 2) as rate,
               1 as is_active
           FROM tax_import_fips f
           INNER JOIN tax_import_rates r 
               ON f.state_fips = r.state_fips AND f.county_fips = r.county_fips";
    
    $joinStmt = $pdo->prepare($joinSql);
    $joinStmt->execute([$stateTaxRate]);
    $stats['rates_matched'] = $joinStmt->rowCount();
    
    // Count unmatched rates (in rates table but no matching FIPS)
    $unmatchedCount = $pdo->query("
        SELECT COUNT(*) FROM tax_import_rates r
        LEFT JOIN tax_import_fips f ON f.state_fips = r.state_fips AND f.county_fips = r.county_fips
        WHERE f.id IS NULL
    ")->fetchColumn();
    $stats['skipped_no_match'] = (int)$unmatchedCount;
    
    // ============================================
    // STEP 4: Cleanup staging tables
    // ============================================
    $pdo->exec("DROP TABLE IF EXISTS tax_import_fips");
    $pdo->exec("DROP TABLE IF EXISTS tax_import_rates");
    
    // ============================================
    // Build summary
    // ============================================
    $summary = "Tax Import Summary\n";
    $summary .= str_repeat("-", 40) . "\n\n";
    $summary .= "📍 Counties loaded from FIPS: " . $stats['counties_loaded'] . "\n";
    $summary .= "📊 County rates loaded: " . $stats['rates_loaded'] . "\n";
    $summary .= "✅ Rates matched & imported: " . $stats['rates_matched'] . "\n";
    $summary .= "\n⏭️ Skipped (inactive dates): " . $stats['skipped_inactive'] . "\n";
    $summary .= "🏙️ Skipped (city-level): " . $stats['skipped_city'] . "\n";
    if ($stats['skipped_no_match'] > 0) {
        $summary .= "⚠️ Skipped (no FIPS match): " . $stats['skipped_no_match'] . "\n";
    }
    $summary .= "\n📊 State tax applied: " . $stateTaxRate . "%\n";
    $summary .= "\n✅ Import completed!";
    
    $_SESSION['tax_import_summary'] = $summary;
    
    header('Location: /?page=settings&tab=taxes&import_success=1');
    exit;
    
} catch (Throwable $e) {
    // Cleanup on error
    try {
        $pdo->exec("DROP TABLE IF EXISTS tax_import_fips");
        $pdo->exec("DROP TABLE IF EXISTS tax_import_rates");
    } catch (Throwable $ignored) {}
    
    error_log('[tax-import] Error: ' . $e->getMessage());
    header('Location: /?page=settings&tab=taxes&import_error=' . rawurlencode($e->getMessage()));
    exit;
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
