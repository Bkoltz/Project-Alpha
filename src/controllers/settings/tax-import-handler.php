<?php
// src/controllers/settings/tax-import-handler.php
// Streaming FIPS + rate importer with optional boundary upload.
// Writes tax_jurisdictions, mirrors county-level rows into tax_rates,
// and optionally populates tax_boundaries / tax_zip_complexity.

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
        'counties_loaded'                => 0,
        'jurisdictions_inserted'         => 0,
        'jurisdictions_updated'          => 0,
        'jurisdictions_skipped_inactive' => 0,
        'county_rates_mirrored'          => 0,
        'boundaries_imported'            => 0,
        'complex_zips'                   => 0,
        'skipped_city_rows'              => 0,
        'errors'                         => []
    ];

    // -------------------------------------------------
    // Validate uploads
    // -------------------------------------------------
    if (!isset($_FILES['fips_file']) || $_FILES['fips_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Please upload a FIPS county file (.txt)');
    }
    if (!isset($_FILES['rate_file']) || $_FILES['rate_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Please upload a tax rates file (.csv)');
    }
    if ($_FILES['fips_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('FIPS file upload error: ' . getUploadErrorMessage($_FILES['fips_file']['error']));
    }
    if ($_FILES['rate_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Rate file upload error: ' . getUploadErrorMessage($_FILES['rate_file']['error']));
    }

    $fipsExt = strtolower(pathinfo($_FILES['fips_file']['name'], PATHINFO_EXTENSION));
    $rateExt = strtolower(pathinfo($_FILES['rate_file']['name'], PATHINFO_EXTENSION));
    if ($fipsExt !== 'txt') {
        throw new Exception('FIPS file must be a .txt file');
    }
    if ($rateExt !== 'csv') {
        throw new Exception('Rate file must be a .csv file');
    }

    $hasBoundaryFile = false;
    $boundaryFile = null;
    if (isset($_FILES['boundary_file']) && $_FILES['boundary_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['boundary_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Boundary file upload error: ' . getUploadErrorMessage($_FILES['boundary_file']['error']));
        }
        $boundaryExt = strtolower(pathinfo($_FILES['boundary_file']['name'], PATHINFO_EXTENSION));
        if ($boundaryExt !== 'csv') {
            throw new Exception('Boundary file must be a .csv file');
        }
        $hasBoundaryFile = true;
        $boundaryFile = $_FILES['boundary_file']['tmp_name'];
    }

    $stateTaxRate = (float)($_POST['state_tax_rate'] ?? 5.0);
    $today = date('Ymd');

    // -------------------------------------------------
    // Ensure DB tables exist
    // -------------------------------------------------
    ensureTablesExist($pdo);

    $pdo->beginTransaction();

    // -------------------------------------------------
    // STEP 1: Parse FIPS file (pipe-delimited, header detection)
    // -------------------------------------------------
    $fipsFile = $_FILES['fips_file']['tmp_name'];
    $fipsHandle = fopen($fipsFile, 'r');
    if (!$fipsHandle) {
        throw new Exception('Could not open FIPS file');
    }

    $fipsLookup = [];
    $headerMap = null;
    $importStateFips = '';
    $importStateAbbr = '';

    while (($line = fgets($fipsHandle)) !== false) {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        $line = trim($line);
        if (empty($line)) {
            continue;
        }

        $parts = explode('|', $line);
        if (count($parts) < 5) {
            continue;
        }

        // First non-data-looking row is the header
        if ($headerMap === null && preg_match('/^[A-Z][A-Z0-9_]*$/', strtoupper(trim($parts[0])))) {
            $headerMap = [];
            foreach ($parts as $idx => $colName) {
                $headerMap[strtoupper(trim($colName))] = $idx;
            }
            continue;
        }

        $stateAbbrIdx = $headerMap['STATE'] ?? $headerMap['USPS'] ?? 0;
        $stateFipsIdx = $headerMap['STATEFP'] ?? 1;
        $countyFipsIdx = $headerMap['COUNTYFP'] ?? 2;
        $countyNameIdx = $headerMap['COUNTYNAME'] ?? 4;

        $stateAbbr = trim($parts[$stateAbbrIdx] ?? '');
        $stateFips = trim($parts[$stateFipsIdx] ?? '');
        $countyFipsRaw = trim($parts[$countyFipsIdx] ?? '');
        $countyNameRaw = trim($parts[$countyNameIdx] ?? '');

        if (empty($stateAbbr) || empty($stateFips) || empty($countyFipsRaw) || empty($countyNameRaw)) {
            continue;
        }

        $stateFips = str_pad($stateFips, 2, '0', STR_PAD_LEFT);
        $countyFips = str_pad($countyFipsRaw, 3, '0', STR_PAD_LEFT);
        $countyName = preg_replace('/\s+County$/i', '', $countyNameRaw);

        $key = $stateFips . $countyFips;
        if (!isset($fipsLookup[$key])) {
            $fipsLookup[$key] = [
                'state_abbr'  => $stateAbbr,
                'state_fips'  => $stateFips,
                'county_fips' => $countyFips,
                'county_name' => $countyName
            ];
            $stats['counties_loaded']++;
        }

        if (empty($importStateFips)) {
            $importStateFips = $stateFips;
            $importStateAbbr = $stateAbbr;
        }
    }
    fclose($fipsHandle);

    if (empty($fipsLookup)) {
        throw new Exception('No counties found in FIPS file. Check format (pipe-delimited with header: STATE|STATEFP|COUNTYFP|COUNTYNS|COUNTYNAME|...)');
    }

    if (empty($importStateAbbr)) {
        $importStateAbbr = $stateFipsToAbbr[$importStateFips] ?? '';
    }

    // Upsert FIPS reference rows into fips_counties for other consumers
    $fipsStmt = $pdo->prepare(
        'INSERT INTO fips_counties (state_fips, county_fips, state_abbr, county_name)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE state_abbr = VALUES(state_abbr), county_name = VALUES(county_name)'
    );
    foreach ($fipsLookup as $row) {
        $fipsStmt->execute([$row['state_fips'], $row['county_fips'], $row['state_abbr'], $row['county_name']]);
    }

    // -------------------------------------------------
    // STEP 2: Parse optional Boundary CSV (streaming)
    // -------------------------------------------------
    if ($hasBoundaryFile) {
        $pdo->prepare('DELETE FROM tax_boundaries WHERE state_fips = ?')->execute([$importStateFips]);

        $insertBoundary = $pdo->prepare(
            'INSERT INTO tax_boundaries
                (zip5_start, zip4_start, zip5_end, zip4_end, state_fips, county_fips, jurisdiction_code, start_date, end_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $boundaryHandle = fopen($boundaryFile, 'r');
        if (!$boundaryHandle) {
            throw new Exception('Could not open boundary CSV file');
        }

        $complexZips = [];

        while (($row = fgetcsv($boundaryHandle)) !== false) {
            if (count($row) < 26) {
                continue;
            }

            $recordType = trim($row[0]);
            if ($recordType !== '4') {
                continue;
            }

            $zip5Start         = trim($row[17]);
            $zip4Start         = trim($row[18]);
            $zip5End           = trim($row[19]);
            $zip4End           = trim($row[20]);
            $stateFips         = str_pad(trim($row[22]), 2, '0', STR_PAD_LEFT);
            $countyFips        = str_pad(trim($row[24]), 3, '0', STR_PAD_LEFT);
            $jurisdictionCode  = trim($row[25]);
            $startDate         = trim($row[1]);
            $endDate           = trim($row[2]);

            if (empty($zip5Start) || strlen($zip5Start) !== 5) {
                continue;
            }

            $startDateFmt = !empty($startDate)
                ? substr($startDate, 0, 4) . '-' . substr($startDate, 4, 2) . '-' . substr($startDate, 6, 2)
                : null;
            $endDateFmt = (!empty($endDate) && $endDate !== '99991231')
                ? substr($endDate, 0, 4) . '-' . substr($endDate, 4, 2) . '-' . substr($endDate, 6, 2)
                : null;

            $insertBoundary->execute([
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

            if (!empty($jurisdictionCode)) {
                $zStart = (int)$zip5Start;
                $zEnd   = $zip5End ? (int)$zip5End : $zStart;
                for ($z = $zStart; $z <= $zEnd; $z++) {
                    $complexZips[str_pad($z, 5, '0', STR_PAD_LEFT)] = 'city_or_special';
                }
            }
        }
        fclose($boundaryHandle);

        // Populate tax_zip_complexity for this state
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
    }

    // -------------------------------------------------
    // STEP 3: Parse Rate CSV (streaming)
    // -------------------------------------------------
    $rateFile = $_FILES['rate_file']['tmp_name'];
    $rateHandle = fopen($rateFile, 'r');
    if (!$rateHandle) {
        throw new Exception('Could not open rate CSV file');
    }

    $hasDefault = (bool)$pdo->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'tax_rates'
           AND COLUMN_NAME  = 'is_default'"
    )->fetchColumn();

    // Wipe previous jurisdiction data for this state so re-imports stay clean
    $pdo->prepare('DELETE FROM tax_jurisdictions WHERE state_fips = ?')->execute([$importStateFips]);

    $lineNum = 0;
    while (($parts = fgetcsv($rateHandle)) !== false) {
        $lineNum++;
        if (count($parts) < 9) {
            $stats['errors'][] = "Rate line {$lineNum}: expected 9 columns, got " . count($parts);
            continue;
        }

        $stateFips        = str_pad(trim($parts[0]), 2, '0', STR_PAD_LEFT);
        $colOne           = trim($parts[1]); // "00" for county, else 2-digit county FIPS
        $colTwo           = trim($parts[2]); // county/city code
        $rate1            = (float)$parts[3];
        $rate2            = (float)$parts[4];
        $rate3            = (float)$parts[5];
        $rate4            = (float)$parts[6];
        $startDate        = trim($parts[7]);
        $endDate          = trim($parts[8]);

        // Skip inactive rate rows
        if ($startDate !== '' && $endDate !== '') {
            if ($today < $startDate || $today > $endDate) {
                $stats['jurisdictions_skipped_inactive']++;
                continue;
            }
        }

        // Sum all four rate columns and convert decimal → percentage
        $localRate = round(($rate1 + $rate2 + $rate3 + $rate4) * 100, 4);

        // Wisconsin-specific: local rate is spread across the four columns
        if ($stateFips === '55') {
            $localRate = round($localRate / 4, 4);
        }

        $totalRate = round($localRate + $stateTaxRate, 4);

        if ($colOne === '00' || $colOne === '0') {
            $countyFipsNorm   = str_pad($colTwo, 3, '0', STR_PAD_LEFT);
            $jurisdictionCode = $countyFipsNorm;
            $jurisdictionType = 'county';
        } else {
            $countyFipsNorm   = str_pad($colOne, 3, '0', STR_PAD_LEFT);
            $jurisdictionCode = $colTwo;
            $jurisdictionType = 'city';
            // City/special rows are inserted into tax_jurisdictions below,
            // but are intentionally NOT mirrored into legacy tax_rates.
        }

        $lookupKey  = $stateFips . $countyFipsNorm;
        $countyInfo = $fipsLookup[$lookupKey] ?? null;
        if (!$countyInfo) {
            $stats['errors'][] = "Rate line {$lineNum}: unknown FIPS State {$stateFips}, County {$countyFipsNorm}";
            continue;
        }

        $countyName = $countyInfo['county_name'];
        $stateAbbr  = $countyInfo['state_abbr'];
        $name       = $countyName . ', ' . $stateAbbr;

        $startDateFmt = !empty($startDate)
            ? substr($startDate, 0, 4) . '-' . substr($startDate, 4, 2) . '-' . substr($startDate, 6, 2)
            : null;
        $endDateFmt = (!empty($endDate) && $endDate !== '99991231')
            ? substr($endDate, 0, 4) . '-' . substr($endDate, 4, 2) . '-' . substr($endDate, 6, 2)
            : null;

        // Upsert into tax_jurisdictions (table was cleared for this state, so insert only here)
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

        // Mirror only county-level rows into legacy tax_rates
        if ($jurisdictionType === 'county') {
            $existingRate = $pdo->prepare('SELECT id FROM tax_rates WHERE name = ? LIMIT 1');
            $existingRate->execute([$name]);
            $existingRateId = $existingRate->fetchColumn();

            if ($existingRateId) {
                $pdo->prepare('UPDATE tax_rates SET rate = ?, state = ?, county = ?, is_active = 1 WHERE id = ?')
                    ->execute([$totalRate, $stateAbbr, $countyName, $existingRateId]);
            } else {
                if ($hasDefault) {
                    $pdo->prepare(
                        'INSERT INTO tax_rates (name, country, state, county, rate, is_active, is_default)
                         VALUES (?, ?, ?, ?, ?, 1, 0)'
                    )->execute([$name, 'USA', $stateAbbr, $countyName, $totalRate]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO tax_rates (name, country, state, county, rate, is_active)
                         VALUES (?, ?, ?, ?, ?, 1)'
                    )->execute([$name, 'USA', $stateAbbr, $countyName, $totalRate]);
                }
            }
            $stats['county_rates_mirrored']++;
        } else {
            $stats['skipped_city_rows']++;
        }
    }
    fclose($rateHandle);

    $pdo->commit();

    $summary = buildImportSummary($stats, $importStateAbbr);
    $_SESSION['tax_import_summary'] = $summary;
    $_SESSION['tax_import_stats']   = $stats;

    header('Location: /?page=settings&tab=taxes&import_success=1');
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[tax-import] Error: ' . $e->getMessage());
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

function buildImportSummary(array $stats, string $stateAbbr): string
{
    $lines = [
        "Tax Import Summary — {$stateAbbr}",
        str_repeat("-", 50),
        "",
        "📍 Counties loaded from FIPS: " . $stats['counties_loaded'],
        "",
        "🏛️ Tax Jurisdictions:",
        "   Inserted: " . $stats['jurisdictions_inserted'],
        "   Updated:  " . $stats['jurisdictions_updated'],
        "   Skipped (inactive dates): " . $stats['jurisdictions_skipped_inactive'],
        "   Skipped (city-level rows): " . $stats['skipped_city_rows'],
        "",
        "💰 County rates mirrored to tax_rates: " . $stats['county_rates_mirrored'],
    ];

    if ($stats['boundaries_imported'] > 0 || $stats['complex_zips'] > 0) {
        $lines[] = "";
        $lines[] = "🗺️ Boundary Records: " . $stats['boundaries_imported'];
        $lines[] = "🔍 Complex ZIP5s flagged: " . $stats['complex_zips'];
    }

    if (!empty($stats['errors'])) {
        $lines[] = "";
        $lines[] = "⚠️ Errors / warnings (" . count($stats['errors']) . "):";
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
