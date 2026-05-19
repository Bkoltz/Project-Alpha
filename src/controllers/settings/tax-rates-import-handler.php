<?php
// src/controllers/settings/tax-rates-import-handler.php
// Handles importing tax rates from ZIP (boundary CSV + rate CSV) + FIPS TXT file

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
        'fips_imported'              => 0,
        'boundaries_imported'        => 0,
        'jurisdictions_inserted'     => 0,
        'jurisdictions_updated'      => 0,
        'jurisdictions_skipped_inactive' => 0,
        'complex_zips'               => 0,
        'unknown_fips'               => [],
        'errors'                     => []
    ];

    // ----------------------------------------
    // Validate uploads
    // ----------------------------------------
    if (!isset($_FILES['fips_file']) || $_FILES['fips_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Please upload the FIPS county reference file (.txt)');
    }
    if (!isset($_FILES['rate_file']) || $_FILES['rate_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Please upload the tax rate CSV file');
    }
    if (!isset($_FILES['boundary_file']) || $_FILES['boundary_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Please upload the boundary CSV file');
    }
    if ($_FILES['fips_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error uploading FIPS file: ' . getUploadErrorMessage($_FILES['fips_file']['error']));
    }
    if ($_FILES['rate_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error uploading rate file: ' . getUploadErrorMessage($_FILES['rate_file']['error']));
    }
    if ($_FILES['boundary_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error uploading boundary file: ' . getUploadErrorMessage($_FILES['boundary_file']['error']));
    }

    $fipsExt = strtolower(pathinfo($_FILES['fips_file']['name'], PATHINFO_EXTENSION));
    $rateExt = strtolower(pathinfo($_FILES['rate_file']['name'], PATHINFO_EXTENSION));
    $boundaryExt = strtolower(pathinfo($_FILES['boundary_file']['name'], PATHINFO_EXTENSION));
    if ($fipsExt !== 'txt') {
        throw new Exception('FIPS file must be a .txt file');
    }
    if ($rateExt !== 'csv') {
        throw new Exception('Rate file must be a .csv file');
    }
    if ($boundaryExt !== 'csv') {
        throw new Exception('Boundary file must be a .csv file');
    }

    // ----------------------------------------
    // Set file paths
    // ----------------------------------------
    $fipsFile = $_FILES['fips_file']['tmp_name'];
    $rateFile = $_FILES['rate_file']['tmp_name'];
    $boundaryFile = $_FILES['boundary_file']['tmp_name'];
    $hasBoundaryFile = true;

    // ----------------------------------------
    // Ensure DB tables exist
    // ----------------------------------------
    ensureTablesExist($pdo);

    $pdo->beginTransaction();

    // ========================================
    // STEP 1: Parse FIPS County Reference File
    // ========================================
    //
    // Wisconsin uses the Census county subdivision file (st55_wi_cousub2020.txt).
    // Pipe-delimited, with a header row. Columns:
    //   [0] STATE      - state abbreviation (e.g. "WI")
    //   [1] STATEFP    - state FIPS         (e.g. "55")
    //   [2] COUNTYFP   - county FIPS        (e.g. "001")
    //   [3] COUNTYNAME - county name        (e.g. "Adams County")
    //   [4] COUSUBFP   - subdivision FIPS   (not used for the county lookup key)
    //   [5] COUSUBNS   - subdivision GNIS   (unused)
    //   [6] COUSUBNAME - subdivision name   (unused for county lookup)
    //   [7] CLASSFP    - class code
    //   [8] FUNCSTAT   - functional status
    //
    // We build a lookup keyed on state_fips + county_fips (both zero-padded) so it
    // matches the keys we derive from the rate and boundary CSVs.
    // ========================================

    $fipsLookup = [];
    $fipsFile   = $_FILES['fips_file']['tmp_name'];

    $fipsHandle = fopen($fipsFile, 'r');
    if (!$fipsHandle) {
        throw new Exception('Could not open FIPS file');
    }

    $fipsLineNum = 0;
    while (($line = fgets($fipsHandle)) !== false) {
        $fipsLineNum++;
        $line = trim($line);
        if (empty($line)) continue;

        $parts = explode('|', $line);
        if (count($parts) < 4) continue;

        $stateAbbr = trim($parts[0]);

        // Skip header row
        if (strtoupper($stateAbbr) === 'STATE') continue;

        $stateFips  = str_pad(trim($parts[1]), 2, '0', STR_PAD_LEFT);
        $countyFips = str_pad(trim($parts[2]), 3, '0', STR_PAD_LEFT); // 3-digit county FIPS

        // Col 4 is COUNTYNAME — strip " County" suffix for cleaner display
        $countyName = preg_replace('/\s+County$/i', '', trim($parts[4]));

        $key = $stateFips . $countyFips; // e.g. "55001"

        // Only insert once per unique county (the file has one row per subdivision)
        if (!isset($fipsLookup[$key])) {
            $fipsLookup[$key] = [
                'state_abbr'  => $stateAbbr,
                'state_fips'  => $stateFips,
                'county_fips' => $countyFips,
                'county_name' => $countyName
            ];

            $stmt = $pdo->prepare('INSERT INTO fips_counties (state_fips, county_fips, state_abbr, county_name)
                                   VALUES (?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE state_abbr = VALUES(state_abbr), county_name = VALUES(county_name)');
            $stmt->execute([$stateFips, $countyFips, $stateAbbr, $countyName]);
            $stats['fips_imported']++;
        }
    }
    fclose($fipsHandle);

    if (empty($fipsLookup)) {
        throw new Exception('No valid FIPS records found in the file');
    }

    // Derive the state FIPS we are importing (first entry in the lookup)
    $importStateFips = reset($fipsLookup)['state_fips'];

    // ========================================
    // STEP 2: Parse Boundary File (streaming)
    // ========================================
    //
    // Boundary CSV (e.g. WIB032026.csv) — fixed column layout, type-4 records only.
    // Relevant columns (0-indexed):
    //   [0]  record type  — must be "4"
    //   [1]  start date   — YYYYMMDD
    //   [2]  end date     — YYYYMMDD  ("99991231" = no end)
    //   [17] ZIP5 start
    //   [18] ZIP+4 start
    //   [19] ZIP5 end
    //   [20] ZIP+4 end
    //   [22] state FIPS
    //   [24] county FIPS  — already 3 digits
    //   [25] jurisdiction code (non-empty = city or special district)
    // ========================================

    $complexZips = [];

    if ($hasBoundaryFile) {
        $pdo->prepare('DELETE FROM tax_boundaries WHERE state_fips = ?')->execute([$importStateFips]);

        $boundaryHandle = fopen($boundaryFile, 'r');
        if ($boundaryHandle) {
            $insertBoundary = $pdo->prepare(
                'INSERT INTO tax_boundaries
                    (zip5_start, zip4_start, zip5_end, zip4_end, state_fips, county_fips, jurisdiction_code, start_date, end_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            while (($row = fgetcsv($boundaryHandle)) !== false) {
                if (count($row) < 26) continue;

                $recordType = trim($row[0]);
                if ($recordType !== '4') continue;

                $zip5Start       = trim($row[17]);
                $zip4Start       = trim($row[18]);
                $zip5End         = trim($row[19]);
                $zip4End         = trim($row[20]);
                $stateFips       = str_pad(trim($row[22]), 2, '0', STR_PAD_LEFT);
                $countyFips      = str_pad(trim($row[24]), 3, '0', STR_PAD_LEFT);
                $jurisdictionCode = trim($row[25]);
                $startDate       = trim($row[1]);
                $endDate         = trim($row[2]);

                if (empty($zip5Start) || strlen($zip5Start) !== 5) continue;

                $startDateFmt = !empty($startDate)
                    ? substr($startDate, 0, 4) . '-' . substr($startDate, 4, 2) . '-' . substr($startDate, 6, 2)
                    : null;
                $endDateFmt = (!empty($endDate) && $endDate !== '99991231')
                    ? substr($endDate, 0, 4) . '-' . substr($endDate, 4, 2) . '-' . substr($endDate, 6, 2)
                    : null;

                $insertBoundary->execute([
                    $zip5Start,
                    $zip4Start ?: '0000',
                    $zip5End   ?: $zip5Start,
                    $zip4End   ?: '9999',
                    $stateFips,
                    $countyFips,
                    $jurisdictionCode ?: null,
                    $startDateFmt,
                    $endDateFmt
                ]);
                $stats['boundaries_imported']++;

                // Track ZIP5s that fall inside a city/special district boundary
                if (!empty($jurisdictionCode)) {
                    $zStart = (int)$zip5Start;
                    $zEnd   = $zip5End ? (int)$zip5End : $zStart;
                    for ($z = $zStart; $z <= $zEnd; $z++) {
                        $complexZips[str_pad($z, 5, '0', STR_PAD_LEFT)] = 'city_or_special';
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
        $pdo->prepare('DELETE FROM tax_zip_complexity WHERE state_fips = ?')->execute([$importStateFips]);

        $stmtComplex = $pdo->prepare(
            'INSERT INTO tax_zip_complexity (zip5, is_complex, reason, state_fips)
             VALUES (?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE is_complex = 1, reason = VALUES(reason), state_fips = VALUES(state_fips)'
        );
        foreach ($complexZips as $zip5 => $reason) {
            $stmtComplex->execute([$zip5, $reason, $importStateFips]);
            $stats['complex_zips']++;
        }
    }

    // ========================================
    // STEP 4: Parse Rate CSV (streaming)
    // ========================================
    //
    // SSTGB rate CSV (e.g. WIR032026.csv) — 9 columns, no header row.
    //
    //   [0] state FIPS  (2-digit, e.g. "55")
    //   [1] county FIPS indicator:
    //         "00" = this is a county-level (or state-level) row;
    //                col[2] holds the actual 3-digit county FIPS code
    //         other = this is a city/sub-district row inside that county;
    //                 col[1] is the 2-digit county FIPS and
    //                 col[2] is the city/special jurisdiction code
    //   [2] jurisdiction code (see [1] above)
    //   [3] rate column 1  (decimal, e.g. 0.005 = 0.5%)
    //   [4] rate column 2
    //   [5] rate column 3
    //   [6] rate column 4
    //   [7] effective start date (YYYYMMDD)
    //   [8] effective end date   (YYYYMMDD; "99991231" = no end)
    //
    // Wisconsin spreads its local rate across all four rate columns, so we sum them.
    // ========================================

    $today = date('Ymd');

    // Clear existing jurisdictions for this state before re-importing
    $pdo->prepare('DELETE FROM tax_jurisdictions WHERE state_fips = ?')->execute([$importStateFips]);

    // Check once whether the legacy tax_rates table has an is_default column
    $hasDefault = (bool)$pdo->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'tax_rates'
           AND COLUMN_NAME  = 'is_default'"
    )->fetchColumn();

    $rateHandle = fopen($rateFile, 'r');
    if (!$rateHandle) {
        throw new Exception('Could not open rate CSV file');
    }

    $lineNum = 0;
    while (($parts = fgetcsv($rateHandle)) !== false) {
        $lineNum++;
        if (count($parts) < 9) {
            $stats['errors'][] = "Rate line {$lineNum}: expected 9 columns, got " . count($parts);
            continue;
        }

        $stateFips       = str_pad(trim($parts[0]), 2, '0', STR_PAD_LEFT);
        $colOne          = trim($parts[1]); // "00" or a 2-digit county FIPS
        $colTwo          = trim($parts[2]); // county FIPS (when colOne="00") or city code
        $rate1           = (float)$parts[3];
        $rate2           = (float)$parts[4];
        $rate3           = (float)$parts[5];
        $rate4           = (float)$parts[6];
        $startDate       = trim($parts[7]);
        $endDate         = trim($parts[8]);

        // Skip rows outside the current active date window
        if ($startDate !== '' && $endDate !== '') {
            if ($today < $startDate || $today > $endDate) {
                $stats['jurisdictions_skipped_inactive']++;
                continue;
            }
        }

        // Sum all four rate columns and convert decimal → percentage
        $localRate = round(($rate1 + $rate2 + $rate3 + $rate4) * 100, 4);

        // For Wisconsin, the local rate is spread across all four columns, so divide by 4
        if ($stateFips === '55') {
            $localRate = $localRate / 4;
        }

        // Add state tax
        $stateTaxRate = (float)($_POST['state_tax_rate'] ?? 5.0);
        $totalRate = $localRate + $stateTaxRate;

        // ---- Decode the county/city fields ----
        if ($colOne === '00') {
            // County-level row: colTwo IS the 3-digit county FIPS
            $countyFipsNorm   = str_pad($colTwo, 3, '0', STR_PAD_LEFT);
            $jurisdictionCode = $countyFipsNorm; // used as the jurisdiction code in DB
            $jurisdictionType = 'county';
        } else {
            // City / sub-district row: colOne = 2-digit county, colTwo = city code
            $countyFipsNorm   = str_pad($colOne, 3, '0', STR_PAD_LEFT);
            $jurisdictionCode = $colTwo;
            $jurisdictionType = 'city';
        }

        // Look up the county name
        $lookupKey  = $stateFips . $countyFipsNorm;
        $countyInfo = $fipsLookup[$lookupKey] ?? null;

        if (!$countyInfo) {
            $stats['unknown_fips'][] = "State {$stateFips}, County {$countyFipsNorm}, Code {$jurisdictionCode}";
            continue;
        }

        $countyName = $countyInfo['county_name'];
        $stateAbbr  = $countyInfo['state_abbr'];

        // Build a human-readable name
        if ($jurisdictionType === 'city') {
            $name = $countyName . ' - ' . $jurisdictionCode . ', ' . $stateAbbr;
        } else {
            $name = $countyName . ', ' . $stateAbbr;
        }

        // Format dates for DB storage
        $startDateFmt = !empty($startDate)
            ? substr($startDate, 0, 4) . '-' . substr($startDate, 4, 2) . '-' . substr($startDate, 6, 2)
            : null;
        $endDateFmt = (!empty($endDate) && $endDate !== '99991231')
            ? substr($endDate, 0, 4) . '-' . substr($endDate, 4, 2) . '-' . substr($endDate, 6, 2)
            : null;

        // Upsert into tax_jurisdictions
        $existingStmt = $pdo->prepare(
            'SELECT id FROM tax_jurisdictions
             WHERE state_fips = ? AND county_fips = ? AND jurisdiction_code = ?
             LIMIT 1'
        );
        $existingStmt->execute([$stateFips, $countyFipsNorm, $jurisdictionCode]);
        $existingId = $existingStmt->fetchColumn();

        if ($existingId) {
            $pdo->prepare(
                'UPDATE tax_jurisdictions SET
                    name = ?, jurisdiction_type = ?,
                    state_rate = ?, county_rate = ?, city_rate = ?, special_rate = ?, total_rate = ?,
                    start_date = ?, end_date = ?, is_active = 1, updated_at = NOW()
                 WHERE id = ?'
            )->execute([
                $name, $jurisdictionType,
                $stateTaxRate, $localRate, 0, 0, $totalRate,
                $startDateFmt, $endDateFmt,
                $existingId
            ]);
            $stats['jurisdictions_updated']++;
        } else {
            $pdo->prepare(
                'INSERT INTO tax_jurisdictions
                    (name, state_fips, county_fips, jurisdiction_code, jurisdiction_type,
                     state_rate, county_rate, city_rate, special_rate, total_rate,
                     start_date, end_date, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
            )->execute([
                $name, $stateFips, $countyFipsNorm, $jurisdictionCode, $jurisdictionType,
                $stateTaxRate, $localRate, 0, 0, $totalRate,
                $startDateFmt, $endDateFmt
            ]);
            $stats['jurisdictions_inserted']++;
        }

        // ---- Mirror county-level rates into the legacy tax_rates table ----
        if ($jurisdictionType === 'county') {
            $rateName = $countyName . ', ' . $stateAbbr;

            $existingRate = $pdo->prepare('SELECT id FROM tax_rates WHERE name = ? LIMIT 1');
            $existingRate->execute([$rateName]);
            $existingRateId = $existingRate->fetchColumn();

            if ($existingRateId) {
                $pdo->prepare('UPDATE tax_rates SET rate = ?, is_active = 1 WHERE id = ?')
                    ->execute([$totalRate, $existingRateId]);
            } else {
                if ($hasDefault) {
                    $pdo->prepare(
                        'INSERT INTO tax_rates (name, country, state, county, rate, is_active, is_default)
                         VALUES (?, ?, ?, ?, ?, 1, 0)'
                    )->execute([$rateName, 'USA', $stateAbbr, $countyName, $totalRate]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO tax_rates (name, country, state, county, rate, is_active)
                         VALUES (?, ?, ?, ?, ?, 1)'
                    )->execute([$rateName, 'USA', $stateAbbr, $countyName, $totalRate]);
                }
            }
        }
    }
    fclose($rateHandle);

    $pdo->commit();

    $summary = buildImportSummary($stats);
    $_SESSION['tax_import_summary'] = $summary;
    $_SESSION['tax_import_stats']   = $stats;

    header('Location: /?page=settings&tab=taxes&import_success=1');
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[tax-rates-import] Error: ' . $e->getMessage());
    header('Location: /?page=settings&tab=taxes&import_error=' . rawurlencode($e->getMessage()));
    exit;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function ensureTablesExist(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS fips_counties (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        state_fips  VARCHAR(2)   NOT NULL,
        county_fips VARCHAR(3)   NOT NULL,
        state_abbr  VARCHAR(2)   NOT NULL,
        county_name VARCHAR(100) NOT NULL,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_fips (state_fips, county_fips)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_jurisdictions (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        name              VARCHAR(150) NOT NULL,
        state_fips        VARCHAR(2)   NOT NULL,
        county_fips       VARCHAR(3)   NOT NULL,
        jurisdiction_code VARCHAR(10)  DEFAULT NULL,
        jurisdiction_type ENUM('state','county','city','special') NOT NULL DEFAULT 'county',
        state_rate        DECIMAL(8,4) NOT NULL DEFAULT 0,
        county_rate       DECIMAL(8,4) NOT NULL DEFAULT 0,
        city_rate         DECIMAL(8,4) NOT NULL DEFAULT 0,
        special_rate      DECIMAL(8,4) NOT NULL DEFAULT 0,
        total_rate        DECIMAL(8,4) NOT NULL DEFAULT 0,
        start_date        DATE         DEFAULT NULL,
        end_date          DATE         DEFAULT NULL,
        is_active         TINYINT(1)   NOT NULL DEFAULT 1,
        created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_boundaries (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        zip5_start        VARCHAR(5)  NOT NULL,
        zip4_start        VARCHAR(4)  NOT NULL,
        zip5_end          VARCHAR(5)  NOT NULL,
        zip4_end          VARCHAR(4)  NOT NULL,
        state_fips        VARCHAR(2)  NOT NULL,
        county_fips       VARCHAR(3)  NOT NULL,
        jurisdiction_code VARCHAR(10) DEFAULT NULL,
        start_date        DATE        DEFAULT NULL,
        end_date          DATE        DEFAULT NULL,
        created_at        TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_zip5 (zip5_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_zip_complexity (
        zip5       VARCHAR(5)  PRIMARY KEY,
        is_complex TINYINT(1)  NOT NULL DEFAULT 0,
        reason     VARCHAR(50) DEFAULT NULL,
        state_fips VARCHAR(2)  DEFAULT NULL,
        created_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function buildImportSummary(array $stats): string
{
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
        "   Updated:  " . $stats['jurisdictions_updated'],
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
    $lines[] = $total > 0
        ? "✅ Import completed successfully!"
        : "⚠️ No jurisdictions were imported. Please check your files.";

    return implode("\n", $lines);
}

function getUploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large',
        UPLOAD_ERR_PARTIAL     => 'File was only partially uploaded',
        UPLOAD_ERR_NO_TMP_DIR  => 'Server configuration error (missing temp directory)',
        UPLOAD_ERR_CANT_WRITE  => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION   => 'Upload blocked by extension',
        default                => 'Unknown upload error',
    };
}

function cleanupTempDir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? cleanupTempDir($path) : unlink($path);
    }
    rmdir($dir);
}