<?php
/**
 * Minimal manual driver for src/controllers/settings/tax-import-handler.php.
 *
 * This script bypasses HTTP and invokes the import handler by faking the
 * $_FILES / $_POST globals and intercepting the Location: redirect. It must be
 * run from inside the project-alpha web container where the DB is reachable.
 */

require_once __DIR__ . '/../src/config/db.php';

$pdo->exec("DROP TABLE IF EXISTS tax_jurisdictions");
$pdo->exec("DROP TABLE IF EXISTS tax_boundaries");
$pdo->exec("DROP TABLE IF EXISTS tax_zip_complexity");
$pdo->exec("DROP TABLE IF EXISTS fips_counties");

// Fake the upload fields by copying fixtures into a temp file and building
// the $_FILES array.
$tmpDir = sys_get_temp_dir();

$fipsSrc = '/tmp/st55_wi_cou2020.txt';
$rateSrc = '/tmp/WIR062026.csv';
$boundarySrc = '/tmp/WIB062026.csv';

if (!is_readable($fipsSrc)) {
    fwrite(STDERR, "Missing FIPS fixture: {$fipsSrc}\n");
    exit(1);
}
if (!is_readable($rateSrc)) {
    fwrite(STDERR, "Missing rate fixture: {$rateSrc}\n");
    exit(1);
}

$fipsTmp = $tmpDir . '/st55_wi_cou2020.txt';
$rateTmp = $tmpDir . '/WIR062026.csv';
$boundaryTmp = $tmpDir . '/WIB062026.csv';

copy($fipsSrc, $fipsTmp);
copy($rateSrc, $rateTmp);
copy($boundarySrc, $boundaryTmp);

$_FILES = [
    'fips_file' => [
        'name' => basename($fipsSrc),
        'type' => 'text/plain',
        'tmp_name' => $fipsTmp,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($fipsTmp),
    ],
    'rate_file' => [
        'name' => basename($rateSrc),
        'type' => 'text/csv',
        'tmp_name' => $rateTmp,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($rateTmp),
    ],
    'boundary_file' => [
        'name' => basename($boundarySrc),
        'type' => 'text/csv',
        'tmp_name' => $boundaryTmp,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($boundaryTmp),
    ],
];

$_POST = [
    'state_tax_rate' => 5.0,
    'csrf' => 'test-token',
];
$_SESSION = ['csrf' => 'test-token'];
$_SERVER['REQUEST_METHOD'] = 'POST';

// Capture any headers emitted by the handler
$headers = [];
ob_start(function ($buffer) use (&$headers) {
    return $buffer; // let normal output through; we'll also parse headers below
});

require_once __DIR__ . '/../src/controllers/settings/tax-import-handler.php';

ob_end_clean();

// If the handler did not exit via header/redirect we may have already exited.
// The script below only runs if the handler returned (unlikely).
echo "Driver finished unexpectedly.\n";
