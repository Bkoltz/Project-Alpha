<?php
// src/controllers/settings/fips-import-handler.php
// Importer A: FIPS Places (county + city/town/village data from Census TXT)

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
    header('Location: /?page=settings&tab=taxes&fips_error=' . rawurlencode('Invalid request (CSRF)'));
    exit;
}

try {
    $stats = [
        'counties_imported' => 0,
        'places_imported' => 0,
        'skipped' => 0,
        'errors' => []
    ];
    
    // Validate file upload
    if (!isset($_FILES['fips_file']) || $_FILES['fips_file']['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Please upload a FIPS file (.txt)');
    }
    if ($_FILES['fips_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Upload error: ' . getUploadErrorMessage($_FILES['fips_file']['error']));
    }
    
    $ext = strtolower(pathinfo($_FILES['fips_file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'txt') {
        throw new Exception('File must be a .txt file');
    }
    
    // Ensure tables exist (before transaction - DDL causes implicit commit)
    ensureFipsPlacesTable($pdo);
    
    // Stream file line-by-line
    $file = new SplFileObject($_FILES['fips_file']['tmp_name'], 'r');
    $file->setFlags(SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
    
    $pdo->beginTransaction();
    
    // Detect state from first data line and clear existing data
    $detectedState = null;
    $batch = [];
    $batchSize = 1000;
    
    $insertStmt = $pdo->prepare('INSERT INTO fips_places 
        (state_fips, county_fips, place_fips, state_abbr, county_name, place_name, place_type, functional_status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            state_abbr = VALUES(state_abbr), 
            county_name = VALUES(county_name), 
            place_name = VALUES(place_name),
            place_type = VALUES(place_type),
            functional_status = VALUES(functional_status)');
    
    foreach ($file as $lineNum => $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $parts = explode('|', $line);
        if (count($parts) < 7) {
            $stats['skipped']++;
            continue;
        }
        
        $stateAbbr = trim($parts[0]);
        
        // Skip header row
        if (strtoupper($stateAbbr) === 'STATE' || strtoupper($stateAbbr) === 'USPS') {
            continue;
        }
        
        $stateFips = str_pad(trim($parts[1]), 2, '0', STR_PAD_LEFT);
        $countyFips = str_pad(trim($parts[2]), 3, '0', STR_PAD_LEFT);
        $placeFips = trim($parts[3] ?? ''); // COUSUBFP - city/place FIPS
        $placeName = trim($parts[4] ?? '');
        $placeType = trim($parts[5] ?? ''); // LSAD - place type code
        $functionalStatus = trim($parts[6] ?? ''); // FUNCSTAT
        
        // First data row - detect state and clear old data
        if ($detectedState === null) {
            $detectedState = $stateFips;
            $pdo->prepare('DELETE FROM fips_places WHERE state_fips = ?')->execute([$stateFips]);
        }
        
        // Determine county name from place name (for county subdivision files)
        // County-level records typically have placeFips ending in 00000 or similar
        $isCountyRecord = (substr($placeFips, -5) === '00000' || empty($placeFips));
        
        // Extract county name (remove type suffixes like " County", " town", " city")
        $countyName = preg_replace('/\s+(County|town|city|village|township)$/i', '', $placeName);
        
        // Insert record
        $insertStmt->execute([
            $stateFips,
            $countyFips,
            $placeFips ?: null,
            $stateAbbr,
            $countyName,
            $placeName,
            $placeType ?: null,
            $functionalStatus ?: null
        ]);
        
        if ($isCountyRecord) {
            $stats['counties_imported']++;
        } else {
            $stats['places_imported']++;
        }
    }
    
    $pdo->commit();
    
    // Log the import (after commit since log table creation is DDL)
    $totalImported = $stats['counties_imported'] + $stats['places_imported'];
    $stmt = $pdo->prepare('INSERT INTO tax_import_log (import_type, state_fips, records_imported, filename) VALUES (?, ?, ?, ?)');
    $stmt->execute(['fips', $detectedState, $totalImported, basename($_FILES['fips_file']['name'])]);
    
    // Build summary
    $summary = "FIPS Import Summary\n";
    $summary .= str_repeat("-", 40) . "\n\n";
    $summary .= "📍 Counties imported: " . $stats['counties_imported'] . "\n";
    $summary .= "🏘️ Places imported: " . $stats['places_imported'] . "\n";
    $summary .= "⏭️ Skipped: " . $stats['skipped'] . "\n\n";
    $summary .= "✅ FIPS import completed!";
    
    $_SESSION['fips_import_summary'] = $summary;
    
    header('Location: /?page=settings&tab=taxes&fips_success=1');
    exit;
    
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[fips-import] Error: ' . $e->getMessage());
    header('Location: /?page=settings&tab=taxes&fips_error=' . rawurlencode($e->getMessage()));
    exit;
}

function ensureFipsPlacesTable(PDO $pdo): void {
    // Drop and recreate if columns are wrong size (simpler than ALTER)
    $needsRecreate = false;
    try {
        $result = $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fips_places' AND COLUMN_NAME = 'functional_status'");
        $len = $result->fetchColumn();
        if ($len !== false && (int)$len < 50) {
            $needsRecreate = true;
        }
    } catch (PDOException $e) {}
    
    if ($needsRecreate) {
        $pdo->exec("DROP TABLE IF EXISTS fips_places");
    }
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS fips_places (
        id INT AUTO_INCREMENT PRIMARY KEY,
        state_fips VARCHAR(2) NOT NULL,
        county_fips VARCHAR(3) NOT NULL,
        place_fips VARCHAR(50) DEFAULT NULL,
        state_abbr VARCHAR(5) NOT NULL,
        county_name VARCHAR(150) NOT NULL,
        place_name VARCHAR(200) NOT NULL,
        place_type VARCHAR(50) DEFAULT NULL,
        functional_status VARCHAR(50) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_place (state_fips, county_fips, place_fips),
        INDEX idx_county (state_fips, county_fips),
        INDEX idx_place (place_fips)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Create import tracking table
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
