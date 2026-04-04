<?php
// src/controllers/settings/tax-import-handler.php
// Combined FIPS + Rates import - county-level only

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
        'counties_parsed' => 0,
        'rates_imported' => 0,
        'skipped_inactive' => 0,
        'skipped_city' => 0
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
    
    // ============================================
    // STEP 1: Parse FIPS file to build county lookup
    // ============================================
    // Format: STATE|STATEFP|COUNTYFP|COUNTYNS|COUNTYNAME|CLASSFP|FUNCSTAT
    // Example: WI|55|001|01581060|Adams County|H1|A
    
    $countyLookup = []; // Key: "55001" => "Adams"
    $stateAbbr = '';
    $stateFips = '';
    
    $fipsFile = new SplFileObject($_FILES['fips_file']['tmp_name'], 'r');
    $fipsFile->setFlags(SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
    
    foreach ($fipsFile as $lineNum => $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $parts = explode('|', $line);
        if (count($parts) < 5) continue;
        
        // Skip header row
        if (strtoupper(trim($parts[0])) === 'STATE' || strtoupper(trim($parts[0])) === 'USPS') {
            continue;
        }
        
        // Parse columns
        $stateAbbr = trim($parts[0]); // "WI"
        $stateFips = str_pad(trim($parts[1]), 2, '0', STR_PAD_LEFT); // "55"
        $countyFips = str_pad(trim($parts[2]), 3, '0', STR_PAD_LEFT); // "001"
        $countyNameRaw = trim($parts[4]); // "Adams County"
        
        // Remove " County" suffix for cleaner display
        $countyName = preg_replace('/\s+County$/i', '', $countyNameRaw);
        
        // Build lookup key
        $key = $stateFips . $countyFips; // "55001"
        $countyLookup[$key] = $countyName;
        
        $stats['counties_parsed']++;
    }
    
    if (empty($countyLookup)) {
        throw new Exception('No counties found in FIPS file. Check file format (should be pipe-delimited).');
    }
    
    // ============================================
    // STEP 2: Parse Rates CSV - county rows only
    // ============================================
    // County format: stateFips,00,countyFips,rate,rate,rate,rate,startDate,endDate
    // Example: 55,00,001,0.005,0.005,0.005,0.005,19940101,99991231
    // City format (SKIP): 55,01,00100,0.0,0.0,0.0,0.0,20230918,99991231
    
    $today = date('Ymd');
    $countyRates = []; // Key: countyFips => ['name' => 'Adams', 'rate' => 5.5]
    
    $rateFile = new SplFileObject($_FILES['rate_file']['tmp_name'], 'r');
    $rateFile->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
    
    foreach ($rateFile as $lineNum => $parts) {
        if (!is_array($parts) || count($parts) < 9) continue;
        
        $col0 = trim($parts[0]); // State FIPS
        $col1 = trim($parts[1]); // "00" for county, or county code for city
        $col2 = trim($parts[2]); // County FIPS (3 digits) or city code
        $rate = (float)$parts[3]; // Use first rate column (they're all the same)
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
        
        // Build lookup key
        $rateStateFips = str_pad($col0, 2, '0', STR_PAD_LEFT);
        $countyFips = str_pad($col2, 3, '0', STR_PAD_LEFT);
        $lookupKey = $rateStateFips . $countyFips;
        
        // Get county name from lookup
        $countyName = $countyLookup[$lookupKey] ?? null;
        if (!$countyName) {
            // Fallback: use generic name
            $countyName = "County " . $countyFips;
        }
        
        // Calculate total rate: local rate (as %) + state tax
        $localRate = $rate * 100; // Convert 0.005 to 0.5
        $totalRate = round($stateTaxRate + $localRate, 2);
        
        // Store (may override older rows for same county - we want the latest active)
        $countyRates[$countyFips] = [
            'name' => $countyName . ', ' . $stateAbbr,
            'county' => $countyName,
            'state' => $stateAbbr,
            'local_rate' => $localRate,
            'total_rate' => $totalRate
        ];
    }
    
    if (empty($countyRates)) {
        throw new Exception('No active county rates found in rate file.');
    }
    
    // ============================================
    // STEP 3: Insert into tax_rates table
    // ============================================
    
    // Check if is_default column exists
    $hasDefault = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tax_rates' AND COLUMN_NAME='is_default'")->fetchColumn();
    
    // Delete existing rates for this state
    $pdo->prepare('DELETE FROM tax_rates WHERE state = ?')->execute([$stateAbbr]);
    
    // Insert new rates
    $insertSql = $hasDefault 
        ? 'INSERT INTO tax_rates (name, country, state, county, rate, is_active, is_default) VALUES (?, ?, ?, ?, ?, 1, 0)'
        : 'INSERT INTO tax_rates (name, country, state, county, rate, is_active) VALUES (?, ?, ?, ?, ?, 1)';
    $insertStmt = $pdo->prepare($insertSql);
    
    foreach ($countyRates as $data) {
        $insertStmt->execute([
            $data['name'],
            'USA',
            $data['state'],
            $data['county'],
            $data['total_rate']
        ]);
        $stats['rates_imported']++;
    }
    
    // ============================================
    // Build summary
    // ============================================
    $summary = "Tax Import Summary\n";
    $summary .= str_repeat("-", 40) . "\n\n";
    $summary .= "📍 Counties parsed from FIPS: " . $stats['counties_parsed'] . "\n";
    $summary .= "💰 Tax rates imported: " . $stats['rates_imported'] . "\n";
    $summary .= "⏭️ Skipped (inactive dates): " . $stats['skipped_inactive'] . "\n";
    $summary .= "🏙️ Skipped (city-level): " . $stats['skipped_city'] . "\n";
    $summary .= "\n📊 State tax applied: " . $stateTaxRate . "%\n";
    $summary .= "\n✅ Import completed!";
    
    $_SESSION['tax_import_summary'] = $summary;
    
    header('Location: /?page=settings&tab=taxes&import_success=1');
    exit;
    
} catch (Throwable $e) {
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
