<?php
// src/controllers/settings/tax-import-handler.php
// Streaming tax import for FIPS, rate, and boundary files. Each source file can
// be uploaded independently; missing files are reused from the persisted tables.

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/tax_lookup.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /?page=settings&tab=taxes');
    exit;
}

$token = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
    header('Location: /?page=settings&tab=taxes&import_error=' . rawurlencode('Invalid request (CSRF)'));
    exit;
}

@set_time_limit(0);
@ini_set('memory_limit', '512M');
ignore_user_abort(true);

const TAX_IMPORT_BATCH_SIZE = 2000;

try {
    $stats = [
        'counties_loaded' => 0,
        'fips_reused' => 0,
        'jurisdictions_inserted' => 0,
        'jurisdictions_skipped_inactive' => 0,
        'county_rates_mirrored' => 0,
        'boundaries_imported' => 0,
        'complex_zips' => 0,
        'skipped_city_rows' => 0,
        'files_uploaded' => [],
        'files_reused' => [],
        'county_rate_summary' => [],
        'errors' => [],
    ];

    ensureTablesExist($pdo);

    $hasFipsFile = taxImportHasUpload('fips_file');
    $hasRateFile = taxImportHasUpload('rate_file');
    $hasBoundaryFile = taxImportHasUpload('boundary_file');
    if (!$hasFipsFile && !$hasRateFile && !$hasBoundaryFile) {
        throw new Exception('Upload at least one FIPS, tax rate, or boundary file.');
    }

    $importStateFips = pa_tax_state_fips_for_hint((string)($_POST['tax_state'] ?? ''));
    if ($importStateFips === null) {
        throw new Exception('Choose the state these tax files belong to before importing.');
    }
    $importStateAbbr = pa_tax_state_abbr_for_fips($importStateFips);
    $_SESSION['tax_import_state'] = $importStateAbbr;
    $stateTaxRate = max(0.0, min(20.0, (float)($_POST['state_tax_rate'] ?? 5.0)));

    if ($hasFipsFile) {
        taxImportValidateFile('fips_file', ['txt']);
        [$importStateFips, $importStateAbbr] = importFipsFile($pdo, $_FILES['fips_file']['tmp_name'], $stats, $importStateFips);
        taxImportRememberFile($pdo, $importStateFips, 'fips', $_FILES['fips_file'], null);
        $stats['files_uploaded'][] = 'FIPS counties';
    }

    if ($hasRateFile) {
        taxImportValidateFile('rate_file', ['csv']);
        [$rateStateFips, $rateStateAbbr] = importRateFile($pdo, $_FILES['rate_file']['tmp_name'], $stateTaxRate, $stats, $importStateFips);
        $importStateFips = $rateStateFips;
        $importStateAbbr = $rateStateAbbr;
        taxImportRememberFile($pdo, $rateStateFips, 'rates', $_FILES['rate_file'], $stateTaxRate);
        $stats['files_uploaded'][] = 'Tax rates';
    } else {
        $stats['files_reused'][] = 'Existing tax rates';
        refreshTaxRateNamesFromFips($pdo, $importStateFips);
    }

    if ($hasBoundaryFile) {
        taxImportValidateFile('boundary_file', ['csv']);
        [$boundaryStateFips, $boundaryStateAbbr] = importBoundaryFile($pdo, $_FILES['boundary_file']['tmp_name'], $stats, $importStateFips);
        $importStateFips = $boundaryStateFips;
        $importStateAbbr = $boundaryStateAbbr;
        taxImportRememberFile($pdo, $boundaryStateFips, 'boundaries', $_FILES['boundary_file'], null);
        $stats['files_uploaded'][] = 'Boundaries';
    } else {
        $stats['files_reused'][] = 'Existing boundaries';
    }

    $_SESSION['tax_import_summary'] = buildImportSummary($stats, $importStateAbbr);
    $_SESSION['tax_import_stats'] = $stats;

    header('Location: /?page=settings&tab=taxes&import_success=1&tax_state=' . rawurlencode($importStateAbbr));
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @error_log('[tax-import] Error: ' . $e->getMessage());
    $stateParam = isset($_SESSION['tax_import_state']) ? '&tax_state=' . rawurlencode((string)$_SESSION['tax_import_state']) : '';
    header('Location: /?page=settings&tab=taxes&import_error=' . rawurlencode($e->getMessage()) . $stateParam);
    exit;
}

function taxImportHasUpload(string $field): bool
{
    return isset($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

function taxImportValidateFile(string $field, array $extensions): void
{
    if (!isset($_FILES[$field])) {
        throw new Exception("Missing upload field {$field}.");
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception(ucwords(str_replace('_', ' ', $field)) . ' upload error: ' . getUploadErrorMessage((int)$_FILES[$field]['error']));
    }
    $ext = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $extensions, true)) {
        throw new Exception(ucwords(str_replace('_', ' ', $field)) . ' must be a .' . implode(' or .', $extensions) . ' file.');
    }
    if (!is_readable((string)$_FILES[$field]['tmp_name'])) {
        throw new Exception(ucwords(str_replace('_', ' ', $field)) . ' could not be read by the server.');
    }
}

function importFipsFile(PDO $pdo, string $path, array &$stats, string $expectedStateFips): array
{
    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new Exception('Could not open FIPS file.');
    }

    $headerMap = null;
    $importStateFips = '';
    $importStateAbbr = '';
    $rows = [];

    while (($line = fgets($handle)) !== false) {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', trim($line));
        if ($line === '') {
            continue;
        }
        $parts = explode('|', $line);
        if (count($parts) < 5) {
            continue;
        }
        if ($headerMap === null && strtoupper(trim($parts[0])) === 'STATE') {
            $headerMap = [];
            foreach ($parts as $idx => $colName) {
                $headerMap[strtoupper(trim($colName))] = $idx;
            }
            continue;
        }

        $stateAbbr = trim($parts[$headerMap['STATE'] ?? 0] ?? '');
        $stateFips = str_pad(trim($parts[$headerMap['STATEFP'] ?? 1] ?? ''), 2, '0', STR_PAD_LEFT);
        $countyFips = str_pad(trim($parts[$headerMap['COUNTYFP'] ?? 2] ?? ''), 3, '0', STR_PAD_LEFT);
        $countyName = preg_replace('/\s+County$/i', '', trim($parts[$headerMap['COUNTYNAME'] ?? 4] ?? ''));
        if ($stateAbbr === '' || $stateFips === '' || $countyFips === '' || $countyName === '') {
            continue;
        }
        if ($stateFips !== $expectedStateFips) {
            continue;
        }
        $rows[$stateFips . $countyFips] = [$stateFips, $countyFips, $stateAbbr, $countyName];
        $importStateFips = $importStateFips ?: $stateFips;
        $importStateAbbr = $importStateAbbr ?: $stateAbbr;
    }
    fclose($handle);

    if (!$rows) {
        throw new Exception('No counties found in the FIPS file for selected state ' . pa_tax_state_abbr_for_fips($expectedStateFips) . '.');
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO fips_counties (state_fips, county_fips, state_abbr, county_name)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE state_abbr = VALUES(state_abbr), county_name = VALUES(county_name)'
    );
    foreach ($rows as $row) {
        $stmt->execute($row);
        $stats['counties_loaded']++;
    }
    $pdo->commit();

    return [$importStateFips, $importStateAbbr];
}

function importRateFile(PDO $pdo, string $path, float $stateTaxRate, array &$stats, string $expectedStateFips): array
{
    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new Exception('Could not open tax rate CSV file.');
    }

    $fipsLookup = loadFipsLookup($pdo);
    if (!$fipsLookup) {
        fclose($handle);
        throw new Exception('No FIPS counties are stored yet. Upload the FIPS file before importing rates.');
    }

    $today = date('Ymd');
    $stateFips = $expectedStateFips;
    $stateAbbr = pa_tax_state_abbr_for_fips($expectedStateFips);
    $lineNum = 0;
    $batch = 0;
    $hasDefault = taxRatesHasColumn($pdo, 'is_default');
    $hasCountry = taxRatesHasColumn($pdo, 'country');
    $stateFipsRows = array_filter($fipsLookup, static fn($row) => $row['state_fips'] === $stateFips);
    if (!$stateFipsRows) {
        fclose($handle);
        throw new Exception("No FIPS counties stored for {$stateAbbr}. Upload that state's FIPS file before importing rates.");
    }
    $stateAbbr = reset($stateFipsRows)['state_abbr'] ?? $stateAbbr;
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM tax_jurisdictions WHERE state_fips = ?')->execute([$stateFips]);

    $insertJurisdiction = $pdo->prepare(
        'INSERT INTO tax_jurisdictions
            (name, state_fips, county_fips, jurisdiction_code, jurisdiction_type,
             state_rate, county_rate, city_rate, special_rate, total_rate,
             start_date, end_date, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    while (($parts = fgetcsv($handle)) !== false) {
        $lineNum++;
        if (count($parts) < 9) {
            $stats['errors'][] = "Rate line {$lineNum}: expected 9 columns, got " . count($parts);
            continue;
        }

        $rowStateFips = str_pad(trim($parts[0]), 2, '0', STR_PAD_LEFT);
        if ($rowStateFips !== $stateFips) {
            $stats['errors'][] = "Rate line {$lineNum}: skipped mixed state FIPS {$rowStateFips}";
            continue;
        }

        $colOne = trim($parts[1]);
        $colTwo = trim($parts[2]);
        $startDate = trim($parts[7]);
        $endDate = trim($parts[8]);
        if ($startDate !== '' && $endDate !== '' && ($today < $startDate || $today > $endDate)) {
            $stats['jurisdictions_skipped_inactive']++;
            continue;
        }

        $localRate = pa_tax_rate_from_import_columns($parts);
        $totalRate = round($localRate + $stateTaxRate, 4);

        if ($colOne === '00' || $colOne === '0') {
            $countyFips = str_pad($colTwo, 3, '0', STR_PAD_LEFT);
            $jurisdictionCode = $countyFips;
            $jurisdictionType = 'county';
            $countyRate = $localRate;
            $cityRate = 0.0;
            $specialRate = 0.0;
        } else {
            $countyFips = str_pad($colOne, 3, '0', STR_PAD_LEFT);
            $jurisdictionCode = $colTwo;
            $jurisdictionType = $colOne === '01' ? 'city' : 'special';
            $countyRate = 0.0;
            $cityRate = $jurisdictionType === 'city' ? $localRate : 0.0;
            $specialRate = $jurisdictionType === 'special' ? $localRate : 0.0;
        }

        $countyInfo = $fipsLookup[$rowStateFips . $countyFips] ?? null;
        if (!$countyInfo) {
            $stats['errors'][] = "Rate line {$lineNum}: unknown FIPS State {$rowStateFips}, County {$countyFips}";
            continue;
        }

        $countyName = $countyInfo['county_name'];
        $name = $jurisdictionType === 'county'
            ? $countyName . ', ' . $countyInfo['state_abbr']
            : $countyName . ', ' . $countyInfo['state_abbr'] . ' - jurisdiction ' . $jurisdictionCode;

        $insertJurisdiction->execute([
            $name,
            $rowStateFips,
            $countyFips,
            $jurisdictionCode,
            $jurisdictionType,
            $stateTaxRate,
            $countyRate,
            $cityRate,
            $specialRate,
            $totalRate,
            taxImportDate($startDate),
            taxImportDate($endDate),
        ]);
        $stats['jurisdictions_inserted']++;
        $batch++;

        if ($jurisdictionType === 'county') {
            $stats['county_rates_mirrored']++;
        } else {
            $stats['skipped_city_rows']++;
        }

        if ($batch >= TAX_IMPORT_BATCH_SIZE) {
            $pdo->commit();
            $pdo->beginTransaction();
            $batch = 0;
        }
    }
    fclose($handle);

    if ($stats['jurisdictions_inserted'] === 0) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new Exception('No usable rows found in the tax rate file for selected state ' . $stateAbbr . '.');
    }
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    $stats['county_rates_mirrored'] = rebuildCountyTaxRateMirror($pdo, $stateFips, $stateAbbr, $hasCountry, $hasDefault);
    summarizeCountyRates($pdo, $stateFips, $stats);
    addCountyRateSanityWarnings($pdo, $stateFips, $stats);

    return [$stateFips, $stateAbbr];
}

function rebuildCountyTaxRateMirror(PDO $pdo, string $stateFips, string $stateAbbr, bool $hasCountry, bool $hasDefault): int
{
    $pdo->beginTransaction();
    $pdo->prepare(
        'DELETE FROM tax_rates
         WHERE state = ?
           AND county IN (
               SELECT county_name
               FROM fips_counties
               WHERE state_fips = ?
           )'
    )->execute([$stateAbbr, $stateFips]);

    if ($hasCountry && $hasDefault) {
        $insertSql = 'INSERT INTO tax_rates (name, country, state, county, rate, is_active, is_default) VALUES (?, "USA", ?, ?, ?, 1, 0)';
    } elseif ($hasCountry) {
        $insertSql = 'INSERT INTO tax_rates (name, country, state, county, rate, is_active) VALUES (?, "USA", ?, ?, ?, 1)';
    } elseif ($hasDefault) {
        $insertSql = 'INSERT INTO tax_rates (name, state, county, rate, is_active, is_default) VALUES (?, ?, ?, ?, 1, 0)';
    } else {
        $insertSql = 'INSERT INTO tax_rates (name, state, county, rate, is_active) VALUES (?, ?, ?, ?, 1)';
    }

    $select = $pdo->prepare(
        'SELECT f.county_name, f.state_abbr, j.total_rate
         FROM tax_jurisdictions j
         JOIN fips_counties f
           ON f.state_fips = j.state_fips
          AND f.county_fips = j.county_fips
         WHERE j.state_fips = ?
           AND j.jurisdiction_type = "county"
           AND j.is_active = 1
         ORDER BY f.county_name'
    );
    $select->execute([$stateFips]);
    $insert = $pdo->prepare($insertSql);
    $count = 0;
    foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $countyName = (string)$row['county_name'];
        $state = (string)$row['state_abbr'];
        $rate = (float)$row['total_rate'];
        $insert->execute([$countyName . ', ' . $state, $state, $countyName, $rate]);
        $count++;
    }
    $pdo->commit();

    return $count;
}

function summarizeCountyRates(PDO $pdo, string $stateFips, array &$stats): void
{
    $stmt = $pdo->prepare(
        'SELECT f.county_name, j.total_rate
         FROM tax_jurisdictions j
         JOIN fips_counties f
           ON f.state_fips = j.state_fips
          AND f.county_fips = j.county_fips
         WHERE j.state_fips = ?
           AND j.jurisdiction_type = "county"
           AND j.is_active = 1
         ORDER BY f.county_name'
    );
    $stmt->execute([$stateFips]);
    $rates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rates[(string)$row['county_name']] = (float)$row['total_rate'];
    }

    $distribution = [];
    foreach ($rates as $rate) {
        $key = number_format($rate, 2);
        $distribution[$key] = ($distribution[$key] ?? 0) + 1;
    }
    ksort($distribution, SORT_NUMERIC);
    foreach ($distribution as $rate => $count) {
        $stats['county_rate_summary'][] = "{$rate}% ({$count})";
    }
}

function addCountyRateSanityWarnings(PDO $pdo, string $stateFips, array &$stats): void
{
    $stmt = $pdo->prepare(
        'SELECT f.county_name, j.total_rate
         FROM tax_jurisdictions j
         JOIN fips_counties f
           ON f.state_fips = j.state_fips
          AND f.county_fips = j.county_fips
         WHERE j.state_fips = ?
           AND j.jurisdiction_type = "county"
           AND j.is_active = 1
         ORDER BY f.county_name'
    );
    $stmt->execute([$stateFips]);
    $rates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rates[(string)$row['county_name']] = (float)$row['total_rate'];
    }

    if ($stateFips === '55') {
        foreach (['Milwaukee' => 5.9, 'Waukesha' => 5.0, 'Winnebago' => 5.0] as $county => $expectedRate) {
            if (isset($rates[$county]) && abs($rates[$county] - $expectedRate) > 0.0001) {
                $stats['errors'][] = "{$county} imported at {$rates[$county]}%, expected {$expectedRate}% from the active WI county rate row.";
            }
        }
    }
}

function importBoundaryFile(PDO $pdo, string $path, array &$stats, string $expectedStateFips): array
{
    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new Exception('Could not open boundary CSV file.');
    }

    $batchKey = bin2hex(random_bytes(12));
    $stateFips = $expectedStateFips;
    $stateAbbr = pa_tax_state_abbr_for_fips($expectedStateFips);
    $rowCount = 0;
    $batch = 0;

    $pdo->prepare('DELETE FROM tax_boundaries_stage WHERE batch_key = ?')->execute([$batchKey]);
    $insertStage = $pdo->prepare(
        'INSERT INTO tax_boundaries_stage
            (batch_key, zip5_start, zip4_start, zip5_end, zip4_end, state_fips, county_fips, jurisdiction_code, start_date, end_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $pdo->beginTransaction();
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 26 || trim((string)$row[0]) !== '4') {
            continue;
        }

        $zip5Start = trim((string)$row[17]);
        $zip4Start = trim((string)$row[18]);
        $zip5End = trim((string)$row[19]);
        $zip4End = trim((string)$row[20]);
        $rowStateFips = str_pad(trim((string)$row[22]), 2, '0', STR_PAD_LEFT);
        $countyFips = str_pad(trim((string)$row[24]), 3, '0', STR_PAD_LEFT);
        $jurisdictionCode = trim((string)$row[25]);
        if ($zip5Start === '' || strlen($zip5Start) !== 5 || $rowStateFips === '00') {
            continue;
        }
        if ($rowStateFips !== $stateFips) {
            $stats['errors'][] = 'Boundary row skipped for mixed state FIPS ' . $rowStateFips;
            continue;
        }

        $insertStage->execute([
            $batchKey,
            $zip5Start,
            $zip4Start !== '' ? $zip4Start : '0000',
            $zip5End !== '' ? $zip5End : $zip5Start,
            $zip4End !== '' ? $zip4End : '9999',
            $rowStateFips,
            $countyFips,
            $jurisdictionCode !== '' ? $jurisdictionCode : null,
            taxImportDate(trim((string)$row[1])),
            taxImportDate(trim((string)$row[2])),
        ]);
        $rowCount++;
        $batch++;

        if ($batch >= TAX_IMPORT_BATCH_SIZE) {
            $pdo->commit();
            $pdo->beginTransaction();
            $batch = 0;
        }
    }
    fclose($handle);
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    if ($rowCount === 0) {
        $pdo->prepare('DELETE FROM tax_boundaries_stage WHERE batch_key = ?')->execute([$batchKey]);
        throw new Exception('No usable boundary rows found for selected state ' . $stateAbbr . '.');
    }

    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM tax_boundaries WHERE state_fips = ?')->execute([$stateFips]);
    $pdo->prepare(
        'INSERT INTO tax_boundaries
            (zip5_start, zip4_start, zip5_end, zip4_end, state_fips, county_fips, jurisdiction_code, start_date, end_date)
         SELECT zip5_start, zip4_start, zip5_end, zip4_end, state_fips, county_fips, jurisdiction_code, start_date, end_date
         FROM tax_boundaries_stage
         WHERE batch_key = ?'
    )->execute([$batchKey]);
    $pdo->prepare('DELETE FROM tax_zip_complexity WHERE state_fips = ?')->execute([$stateFips]);
    $zipStmt = $pdo->prepare(
        'SELECT DISTINCT zip5_start AS zip5
         FROM tax_boundaries_stage
         WHERE batch_key = ? AND jurisdiction_code IS NOT NULL
         UNION
         SELECT DISTINCT zip5_end AS zip5
         FROM tax_boundaries_stage
         WHERE batch_key = ? AND jurisdiction_code IS NOT NULL'
    );
    $zipStmt->execute([$batchKey, $batchKey]);
    $insertComplex = $pdo->prepare(
        'INSERT INTO tax_zip_complexity (zip5, is_complex, reason, state_fips)
         VALUES (?, 1, "city_or_special", ?)
         ON DUPLICATE KEY UPDATE is_complex = 1, reason = VALUES(reason), state_fips = VALUES(state_fips)'
    );
    foreach ($zipStmt->fetchAll(PDO::FETCH_COLUMN) as $zip5) {
        if (is_string($zip5) && strlen($zip5) === 5) {
            $insertComplex->execute([$zip5, $stateFips]);
            $stats['complex_zips']++;
        }
    }
    $pdo->prepare('DELETE FROM tax_boundaries_stage WHERE batch_key = ?')->execute([$batchKey]);
    $pdo->commit();

    $stats['boundaries_imported'] = $rowCount;
    return [$stateFips, $stateAbbr];
}

function refreshTaxRateNamesFromFips(PDO $pdo, string $stateFips): void
{
    $stmt = $pdo->prepare('SELECT state_abbr, county_name FROM fips_counties WHERE state_fips = ?');
    $stmt->execute([$stateFips]);
    $update = $pdo->prepare('UPDATE tax_rates SET name = ?, county = ? WHERE state = ? AND county = ?');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = $row['county_name'] . ', ' . $row['state_abbr'];
        $update->execute([$name, $row['county_name'], $row['state_abbr'], $row['county_name']]);
    }
}

function loadFipsLookup(PDO $pdo): array
{
    $lookup = [];
    $rows = $pdo->query('SELECT state_fips, county_fips, state_abbr, county_name FROM fips_counties')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $lookup[$row['state_fips'] . $row['county_fips']] = $row;
    }
    return $lookup;
}

function taxImportDate(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || $value === '99991231' || !preg_match('/^\d{8}$/', $value)) {
        return null;
    }
    return substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
}

function taxRatesHasColumn(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'tax_rates'
           AND COLUMN_NAME = ?"
    );
    $stmt->execute([$column]);
    return (bool)$stmt->fetchColumn();
}

function stateAbbrForFips(string $stateFips): string
{
    return pa_tax_state_abbr_for_fips($stateFips);
}

function taxImportRememberFile(PDO $pdo, string $stateFips, string $fileType, array $file, ?float $stateTaxRate): void
{
    $hash = @hash_file('sha256', (string)$file['tmp_name']) ?: null;
    $stmt = $pdo->prepare(
        'INSERT INTO tax_import_files
            (state_fips, file_type, original_name, content_hash, size_bytes, state_tax_rate, imported_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            original_name = VALUES(original_name),
            content_hash = VALUES(content_hash),
            size_bytes = VALUES(size_bytes),
            state_tax_rate = VALUES(state_tax_rate),
            imported_at = VALUES(imported_at)'
    );
    $stmt->execute([
        $stateFips,
        $fileType,
        basename((string)$file['name']),
        $hash,
        (int)($file['size'] ?? 0),
        $stateTaxRate,
    ]);
}

function ensureTablesExist(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS fips_counties (
        id INT AUTO_INCREMENT PRIMARY KEY,
        state_fips VARCHAR(2) NOT NULL,
        county_fips VARCHAR(3) NOT NULL,
        state_abbr VARCHAR(2) NOT NULL,
        county_name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_fips (state_fips, county_fips)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $taxRateCountryExists = (bool)$pdo->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'tax_rates'
           AND COLUMN_NAME = 'country'"
    )->fetchColumn();
    if (!$taxRateCountryExists) {
        $pdo->exec("ALTER TABLE tax_rates ADD COLUMN country VARCHAR(100) NULL DEFAULT 'USA' AFTER name");
    }

    $taxRateActiveExists = (bool)$pdo->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'tax_rates'
           AND COLUMN_NAME = 'is_active'"
    )->fetchColumn();
    if (!$taxRateActiveExists) {
        $pdo->exec("ALTER TABLE tax_rates ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_default");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_jurisdictions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        state_fips VARCHAR(2) NOT NULL,
        county_fips VARCHAR(3) NOT NULL,
        jurisdiction_code VARCHAR(10) DEFAULT NULL,
        jurisdiction_type ENUM('state','county','city','special') NOT NULL DEFAULT 'county',
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
        INDEX idx_tax_jurisdiction_state (state_fips, county_fips),
        INDEX idx_tax_jurisdiction_code (state_fips, county_fips, jurisdiction_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
        INDEX idx_tax_boundaries_state_zip (state_fips, zip5_start),
        INDEX idx_tax_boundaries_county (state_fips, county_fips),
        INDEX idx_tax_boundaries_jurisdiction (state_fips, county_fips, jurisdiction_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_boundaries_stage (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        batch_key VARCHAR(32) NOT NULL,
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
        INDEX idx_tax_boundary_stage_batch (batch_key),
        INDEX idx_tax_boundary_stage_state_zip (state_fips, zip5_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_zip_complexity (
        id INT AUTO_INCREMENT PRIMARY KEY,
        zip5 VARCHAR(5) NOT NULL,
        is_complex TINYINT(1) NOT NULL DEFAULT 0,
        reason VARCHAR(50) DEFAULT NULL,
        state_fips VARCHAR(2) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_tax_zip_complexity_state_zip (state_fips, zip5),
        INDEX idx_tax_zip_complexity_zip5 (zip5),
        INDEX idx_tax_zip_complexity_state (state_fips)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_import_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        state_fips VARCHAR(2) NOT NULL,
        file_type ENUM('fips','rates','boundaries') NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        content_hash CHAR(64) NULL,
        size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        state_tax_rate DECIMAL(8,4) NULL,
        imported_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_tax_import_file (state_fips, file_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function buildImportSummary(array $stats, string $stateAbbr): string
{
    $lines = [
        "Tax Import Summary - {$stateAbbr}",
        str_repeat('-', 50),
        '',
        'Files uploaded: ' . ($stats['files_uploaded'] ? implode(', ', $stats['files_uploaded']) : 'None'),
        'Existing data reused: ' . ($stats['files_reused'] ? implode(', ', $stats['files_reused']) : 'None'),
        '',
        'Counties loaded from FIPS: ' . $stats['counties_loaded'],
        'Tax jurisdictions inserted: ' . $stats['jurisdictions_inserted'],
        'Skipped inactive rate rows: ' . $stats['jurisdictions_skipped_inactive'],
        'Skipped city-level rows for legacy tax_rates: ' . $stats['skipped_city_rows'],
        'County rates mirrored to tax_rates: ' . $stats['county_rates_mirrored'],
        'Mirrored county rate distribution: ' . ($stats['county_rate_summary'] ? implode(', ', $stats['county_rate_summary']) : 'None'),
        'Boundary records imported: ' . $stats['boundaries_imported'],
        'Complex ZIP5s flagged: ' . $stats['complex_zips'],
    ];

    if (!empty($stats['errors'])) {
        $lines[] = '';
        $lines[] = 'Warnings (' . count($stats['errors']) . '):';
        foreach (array_slice($stats['errors'], 0, 8) as $err) {
            $lines[] = ' - ' . $err;
        }
        if (count($stats['errors']) > 8) {
            $lines[] = ' ... and ' . (count($stats['errors']) - 8) . ' more';
        }
    }

    $lines[] = '';
    $lines[] = 'Import completed.';
    return implode("\n", $lines);
}

function getUploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error (missing temp directory)',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'Upload blocked by extension',
        default => 'Unknown upload error',
    };
}
