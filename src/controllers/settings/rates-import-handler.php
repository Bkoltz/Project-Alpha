<?php
// src/controllers/settings/rates-import-handler.php
// Importer B: SSTGB Tax Rates CSV

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
    header('Location: /?page=settings&tab=taxes&rates_error=' . rawurlencode('Invalid request (CSRF)'));
    exit;
}

// State FIPS to abbreviation mapping
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

try {
    $stats = [
        'jurisdictions_inserted' => 0,
        'jurisdictions_updated' => 0,
        'skipped_inactive' => 0,
        'unknown_fips' => [],
        'errors' => []
    ];
    
    // Validate file upload
    if (!isset($_FILES['rate_file']) || $_FILES['rate_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Please upload a rate file (.csv)');
    }
    if ($_FILES['rate_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Upload error: ' . getUploadErrorMessage($_FILES['rate_file']['error']));
    }
    
    $ext = strtolower(pathinfo($_FILES['rate_file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        throw new Exception('File must be a .csv file');
    }
    
    // Ensure tables exist (before transaction - DDL causes implicit commit)
    ensureJurisdictionsTable($pdo);
    ensureImportLogTable($pdo);
    ensureFipsCountiesTable($pdo);
    
    // Load FIPS lookup - prefer fips_counties table, fallback to fips_places
    $fipsLookup = [];
    
    // First try fips_counties (proper county names)
    try {
        $fipsStmt = $pdo->query('SELECT state_fips, county_fips, state_abbr, county_name FROM fips_counties');
        while ($row = $fipsStmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['state_fips'] . $row['county_fips'];
            $fipsLookup[$key] = $row;
        }
    } catch (PDOException $e) {
        // Table may not exist
    }
    
    // If fips_counties is empty, try fips_places
    if (empty($fipsLookup)) {
        try {
            $fipsStmt = $pdo->query("
                SELECT state_fips, county_fips, MAX(state_abbr) as state_abbr,
                       MAX(CASE WHEN place_name LIKE '%County%' THEN place_name ELSE NULL END) as county_name_pref,
                       MAX(place_name) as place_name_fallback
                FROM fips_places
                GROUP BY state_fips, county_fips
            ");
            while ($row = $fipsStmt->fetch(PDO::FETCH_ASSOC)) {
                $key = $row['state_fips'] . $row['county_fips'];
                $rawName = $row['county_name_pref'] ?: $row['place_name_fallback'];
                $countyName = preg_replace('/\s+(County|town|city|village|township)$/i', '', $rawName);
                $fipsLookup[$key] = [
                    'state_fips' => $row['state_fips'],
                    'county_fips' => $row['county_fips'],
                    'state_abbr' => $row['state_abbr'],
                    'county_name' => $countyName
                ];
            }
        } catch (PDOException $e) {
            // Table may not exist
        }
    }
    
    // Stream file line-by-line
    $file = new SplFileObject($_FILES['rate_file']['tmp_name'], 'r');
    $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
    
    $pdo->beginTransaction();
    
    $today = date('Ymd');
    $detectedState = null;
    $batch = [];
    $batchSize = 500;
    
    foreach ($file as $lineNum => $parts) {
        if (!is_array($parts) || count($parts) < 9) {
            if (!empty(array_filter($parts))) {
                $stats['errors'][] = "Line " . ($lineNum + 1) . ": Invalid format";
            }
            continue;
        }
        
        // SSTGB CSV Format:
        // Col 0: state FIPS
        // Col 1: county FIPS (00 = state-level)
        // Col 2: jurisdiction code
        // Col 3-6: rate columns (sum all four for total local rate)
        // Col 7: start date (YYYYMMDD)
        // Col 8: end date (YYYYMMDD)
        
        $stateFips = str_pad(trim($parts[0]), 2, '0', STR_PAD_LEFT);
        $countyFips = str_pad(trim($parts[1]), 2, '0', STR_PAD_LEFT); // 2 digits in CSV
        $jurisdictionCode = trim($parts[2]);
        $rate1 = (float)$parts[3];
        $rate2 = (float)$parts[4];
        $rate3 = (float)$parts[5];
        $rate4 = (float)$parts[6];
        $startDate = trim($parts[7]);
        $endDate = trim($parts[8]);
        
        // First data row - detect state and clear existing
        if ($detectedState === null) {
            $detectedState = $stateFips;
            $pdo->prepare('DELETE FROM tax_jurisdictions WHERE state_fips = ?')->execute([$stateFips]);
        }
        
        // Skip inactive rates (check date range)
        if ($startDate !== '' && $endDate !== '') {
            if ($today < $startDate || $today > $endDate) {
                $stats['skipped_inactive']++;
                continue;
            }
        }
        
        // Sum all 4 rate columns and convert to percentage
        // Wisconsin format: rates are decimals (0.005 = 0.5%)
        $totalLocalRate = ($rate1 + $rate2 + $rate3 + $rate4) * 100;
        $totalLocalRate = round($totalLocalRate, 4);
        
        // Normalize county FIPS to 3 digits
        $countyFipsNorm = ($countyFips === '00') ? '000' : str_pad($countyFips, 3, '0', STR_PAD_LEFT);
        
        // Determine jurisdiction type
        if ($countyFips === '00') {
            $jurisdictionType = 'state';
        } elseif (strlen($jurisdictionCode) > 3) {
            $jurisdictionType = 'city';
        } else {
            $jurisdictionType = 'county';
        }
        
        // Lookup county name
        $lookupKey = $stateFips . $countyFipsNorm;
        $countyName = $fipsLookup[$lookupKey]['county_name'] ?? null;
        $stateAbbr = $fipsLookup[$lookupKey]['state_abbr'] ?? ($stateFipsToAbbr[$stateFips] ?? 'XX');
        
        if (!$countyName && $jurisdictionType !== 'state') {
            $stats['unknown_fips'][] = "State {$stateFips}, County {$countyFipsNorm}, Code {$jurisdictionCode}";
            // Don't skip - still import with jurisdiction code as name
            $countyName = "County " . $countyFipsNorm;
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
        
        // Add to batch
        $batch[] = [
            'name' => $name,
            'state_fips' => $stateFips,
            'county_fips' => $countyFipsNorm,
            'jurisdiction_code' => $jurisdictionCode ?: null,
            'jurisdiction_type' => $jurisdictionType,
            'state_rate' => $rate1 * 100,
            'county_rate' => $rate2 * 100,
            'city_rate' => $rate3 * 100,
            'special_rate' => $rate4 * 100,
            'total_rate' => $totalLocalRate,
            'start_date' => $startDateFmt,
            'end_date' => $endDateFmt
        ];
        
        // Flush batch
        if (count($batch) >= $batchSize) {
            $inserted = flushBatch($pdo, $batch);
            $stats['jurisdictions_inserted'] += $inserted;
            $batch = [];
        }
    }
    
    // Flush remaining
    if (!empty($batch)) {
        $inserted = flushBatch($pdo, $batch);
        $stats['jurisdictions_inserted'] += $inserted;
    }
    
    // Also update legacy tax_rates table for backward compatibility
    updateLegacyTaxRates($pdo, $detectedState);
    
    // Log the import (table already created before transaction)
    $stmt = $pdo->prepare('INSERT INTO tax_import_log (import_type, state_fips, records_imported, filename) VALUES (?, ?, ?, ?)');
    $stmt->execute(['rates', $detectedState, $stats['jurisdictions_inserted'], basename($_FILES['rate_file']['name'])]);
    
    $pdo->commit();
    
    // Build summary
    $summary = "Tax Rate Import Summary\n";
    $summary .= str_repeat("-", 40) . "\n\n";
    $summary .= "💰 Jurisdictions imported: " . $stats['jurisdictions_inserted'] . "\n";
    $summary .= "⏭️ Skipped (inactive): " . $stats['skipped_inactive'] . "\n";
    
    if (!empty($stats['unknown_fips'])) {
        $summary .= "\n⚠️ Unknown FIPS (" . count($stats['unknown_fips']) . " records)\n";
        $summary .= "   Run FIPS import first for better county names\n";
    }
    
    $summary .= "\n✅ Rate import completed!";
    
    $_SESSION['rates_import_summary'] = $summary;
    
    header('Location: /?page=settings&tab=taxes&rates_success=1');
    exit;
    
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[rates-import] Error: ' . $e->getMessage());
    header('Location: /?page=settings&tab=taxes&rates_error=' . rawurlencode($e->getMessage()));
    exit;
}

function flushBatch(PDO $pdo, array $batch): int {
    if (empty($batch)) return 0;
    
    $placeholders = [];
    $values = [];
    foreach ($batch as $row) {
        $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)';
        $values = array_merge($values, [
            $row['name'],
            $row['state_fips'],
            $row['county_fips'],
            $row['jurisdiction_code'],
            $row['jurisdiction_type'],
            $row['state_rate'],
            $row['county_rate'],
            $row['city_rate'],
            $row['special_rate'],
            $row['total_rate'],
            $row['start_date'],
            $row['end_date']
        ]);
    }
    
    $sql = 'INSERT INTO tax_jurisdictions 
        (name, state_fips, county_fips, jurisdiction_code, jurisdiction_type,
         state_rate, county_rate, city_rate, special_rate, total_rate,
         start_date, end_date, is_active)
        VALUES ' . implode(', ', $placeholders);
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    
    return count($batch);
}

function updateLegacyTaxRates(PDO $pdo, ?string $stateFips): void {
    if (!$stateFips) return;
    
    // Check if is_default column exists
    $hasDefault = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tax_rates' AND COLUMN_NAME='is_default'")->fetchColumn();
    
    // Get county-level jurisdictions
    $stmt = $pdo->prepare('SELECT name, total_rate, state_fips FROM tax_jurisdictions 
        WHERE state_fips = ? AND jurisdiction_type = "county"');
    $stmt->execute([$stateFips]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing = $pdo->prepare('SELECT id FROM tax_rates WHERE name = ? LIMIT 1');
        $existing->execute([$row['name']]);
        $existingId = $existing->fetchColumn();
        
        if ($existingId) {
            $pdo->prepare('UPDATE tax_rates SET rate = ?, is_active = 1 WHERE id = ?')
                ->execute([$row['total_rate'], $existingId]);
        } else {
            // Parse state/county from name
            $parts = explode(', ', $row['name']);
            $county = $parts[0] ?? '';
            $state = $parts[1] ?? '';
            
            if ($hasDefault) {
                $pdo->prepare('INSERT INTO tax_rates (name, country, state, county, rate, is_active, is_default) VALUES (?, ?, ?, ?, ?, 1, 0)')
                    ->execute([$row['name'], 'USA', $state, $county, $row['total_rate']]);
            } else {
                $pdo->prepare('INSERT INTO tax_rates (name, country, state, county, rate, is_active) VALUES (?, ?, ?, ?, ?, 1)')
                    ->execute([$row['name'], 'USA', $state, $county, $row['total_rate']]);
            }
        }
    }
}

function ensureFipsCountiesTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fips_counties (
        id INT AUTO_INCREMENT PRIMARY KEY,
        state_fips VARCHAR(2) NOT NULL,
        county_fips VARCHAR(3) NOT NULL,
        state_abbr VARCHAR(2) NOT NULL,
        county_name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_fips (state_fips, county_fips)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Check if Wisconsin counties exist
    $check = $pdo->query("SELECT COUNT(*) FROM fips_counties WHERE state_fips = '55'");
    if ((int)$check->fetchColumn() === 0) {
        // Insert Wisconsin counties
        $wiCounties = [
            ['55', '001', 'WI', 'Adams'], ['55', '003', 'WI', 'Ashland'], ['55', '005', 'WI', 'Barron'],
            ['55', '007', 'WI', 'Bayfield'], ['55', '009', 'WI', 'Brown'], ['55', '011', 'WI', 'Buffalo'],
            ['55', '013', 'WI', 'Burnett'], ['55', '015', 'WI', 'Calumet'], ['55', '017', 'WI', 'Chippewa'],
            ['55', '019', 'WI', 'Clark'], ['55', '021', 'WI', 'Columbia'], ['55', '023', 'WI', 'Crawford'],
            ['55', '025', 'WI', 'Dane'], ['55', '027', 'WI', 'Dodge'], ['55', '029', 'WI', 'Door'],
            ['55', '031', 'WI', 'Douglas'], ['55', '033', 'WI', 'Dunn'], ['55', '035', 'WI', 'Eau Claire'],
            ['55', '037', 'WI', 'Florence'], ['55', '039', 'WI', 'Fond du Lac'], ['55', '041', 'WI', 'Forest'],
            ['55', '043', 'WI', 'Grant'], ['55', '045', 'WI', 'Green'], ['55', '047', 'WI', 'Green Lake'],
            ['55', '049', 'WI', 'Iowa'], ['55', '051', 'WI', 'Iron'], ['55', '053', 'WI', 'Jackson'],
            ['55', '055', 'WI', 'Jefferson'], ['55', '057', 'WI', 'Juneau'], ['55', '059', 'WI', 'Kenosha'],
            ['55', '061', 'WI', 'Kewaunee'], ['55', '063', 'WI', 'La Crosse'], ['55', '065', 'WI', 'Lafayette'],
            ['55', '067', 'WI', 'Langlade'], ['55', '069', 'WI', 'Lincoln'], ['55', '071', 'WI', 'Manitowoc'],
            ['55', '073', 'WI', 'Marathon'], ['55', '075', 'WI', 'Marinette'], ['55', '077', 'WI', 'Marquette'],
            ['55', '078', 'WI', 'Menominee'], ['55', '079', 'WI', 'Milwaukee'], ['55', '081', 'WI', 'Monroe'],
            ['55', '083', 'WI', 'Oconto'], ['55', '085', 'WI', 'Oneida'], ['55', '087', 'WI', 'Outagamie'],
            ['55', '089', 'WI', 'Ozaukee'], ['55', '091', 'WI', 'Pepin'], ['55', '093', 'WI', 'Pierce'],
            ['55', '095', 'WI', 'Polk'], ['55', '097', 'WI', 'Portage'], ['55', '099', 'WI', 'Price'],
            ['55', '101', 'WI', 'Racine'], ['55', '103', 'WI', 'Richland'], ['55', '105', 'WI', 'Rock'],
            ['55', '107', 'WI', 'Rusk'], ['55', '109', 'WI', 'St. Croix'], ['55', '111', 'WI', 'Sauk'],
            ['55', '113', 'WI', 'Sawyer'], ['55', '115', 'WI', 'Shawano'], ['55', '117', 'WI', 'Sheboygan'],
            ['55', '119', 'WI', 'Taylor'], ['55', '121', 'WI', 'Trempealeau'], ['55', '123', 'WI', 'Vernon'],
            ['55', '125', 'WI', 'Vilas'], ['55', '127', 'WI', 'Walworth'], ['55', '129', 'WI', 'Washburn'],
            ['55', '131', 'WI', 'Washington'], ['55', '133', 'WI', 'Waukesha'], ['55', '135', 'WI', 'Waupaca'],
            ['55', '137', 'WI', 'Waushara'], ['55', '139', 'WI', 'Winnebago'], ['55', '141', 'WI', 'Wood']
        ];
        
        $stmt = $pdo->prepare('INSERT IGNORE INTO fips_counties (state_fips, county_fips, state_abbr, county_name) VALUES (?, ?, ?, ?)');
        foreach ($wiCounties as $c) {
            $stmt->execute($c);
        }
    }
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

function ensureJurisdictionsTable(PDO $pdo): void {
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
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_state (state_fips),
        INDEX idx_county (state_fips, county_fips),
        INDEX idx_code (jurisdiction_code)
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
