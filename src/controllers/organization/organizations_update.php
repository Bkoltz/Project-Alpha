<?php
// src/controllers/organization/organizations_update.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/organization_schema.php';

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$remove_tax = !empty($_POST['remove_tax_file']);
$addressValues = [
    'address_line1' => trim((string)($_POST['address_line1'] ?? '')),
    'address_line2' => trim((string)($_POST['address_line2'] ?? '')),
    'city' => trim((string)($_POST['city'] ?? '')),
    'state' => trim((string)($_POST['state'] ?? '')),
    'postal_code' => trim((string)($_POST['postal_code'] ?? '')),
    'country' => trim((string)($_POST['country'] ?? '')),
];

if ($id <= 0 || $name === '') {
    header('Location: /?page=organization/organizations-edit&id=' . $id . '&error=Invalid%20input');
    exit;
}

$addressColumns = pa_ensure_organization_address_columns($pdo);
$addressAssignments = [];
$addressParams = [];
foreach ($addressValues as $column => $value) {
    if (isset($addressColumns[$column])) {
        $addressAssignments[] = "{$column} = ?";
        $addressParams[] = $value !== '' ? $value : null;
    }
}
$addressSql = $addressAssignments ? ', ' . implode(', ', $addressAssignments) : '';

// Handle file upload if present
if (!empty($_FILES['tax_exempt_file']) && is_uploaded_file($_FILES['tax_exempt_file']['tmp_name'])) {
    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png'
    ];
    $tmp = $_FILES['tax_exempt_file']['tmp_name'];
    
    // Get MIME type (try multiple methods for better compatibility)
    $mime = null;
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($tmp);
    }
    if (!$mime && function_exists('finfo_file')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp);
        if (PHP_VERSION_ID < 80500) {
            finfo_close($finfo);
        }
    }
    if (!$mime) {
        $mime = $_FILES['tax_exempt_file']['type'];
    }
    
    error_log('ORG_UPDATE_UPLOAD: MIME detection - detected: ' . ($mime ?: 'NULL') . ' allowed: ' . json_encode($allowed));
    
    if (!array_key_exists($mime, $allowed)) {
        error_log('ORG_UPDATE_UPLOAD: Invalid MIME type: ' . ($mime ?: 'NULL'));
        header('Location: /?page=organization/organizations-edit&id=' . $id . '&error=Invalid%20file%20type%20(' . rawurlencode($mime ?: 'unknown') . ')');
        exit;
    }
    // limit to 8MB
    if ($_FILES['tax_exempt_file']['size'] > 8 * 1024 * 1024) {
        header('Location: /?page=organization/organizations-edit&id=' . $id . '&error=File%20too%20large');
        exit;
    }

    $ext = $allowed[$mime];
    $safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', basename($_FILES['tax_exempt_file']['name']));
    $targetDir = __DIR__ . '/../../uploads/organizations';
    if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
    $filename = time() . '_' . bin2hex(random_bytes(6)) . '_' . $safeName;
    $targetPath = $targetDir . '/' . $filename;
    
    error_log('ORG_UPDATE_UPLOAD: Attempting to save to: ' . $targetPath);
    
    // Try multiple methods for reliability
    $moved = false;
    if (!empty($tmp) && is_uploaded_file($tmp)) {
        $moved = @move_uploaded_file($tmp, $targetPath);
        error_log('ORG_UPDATE_UPLOAD: move_uploaded_file result=' . ($moved ? '1' : '0'));
    }
    if (!$moved && !empty($tmp)) {
        $moved = @rename($tmp, $targetPath);
        error_log('ORG_UPDATE_UPLOAD: rename result=' . ($moved ? '1' : '0'));
    }
    if (!$moved && !empty($tmp)) {
        $moved = @copy($tmp, $targetPath);
        error_log('ORG_UPDATE_UPLOAD: copy result=' . ($moved ? '1' : '0'));
    }
    if (!$moved) {
        error_log('ORG_UPDATE_UPLOAD: FAILED to store uploaded file. tmp_exists=' . (is_file($tmp) ? '1' : '0') . ' dir_exists=' . (is_dir($targetDir)?'1':'0') . ' dir_writable=' . (is_writable($targetDir)?'1':'0') . ' cwd=' . getcwd());
        header('Location: /?page=organization/organizations-edit&id=' . $id . '&error=Failed%20to%20save%20file');
        exit;
    }
    
    error_log('ORG_UPDATE_UPLOAD: File saved successfully to: ' . $targetPath);
    
    // Save filename to DB (relative name) and remove previous file if present
    $prevStmt = $pdo->prepare('SELECT tax_exempt_file FROM organizations WHERE id = ?');
    $prevStmt->execute([$id]);
    $prev = $prevStmt->fetchColumn();

    $stmt = $pdo->prepare('UPDATE organizations SET name = ?, notes = ?' . $addressSql . ', tax_exempt_file = ?, tax_exempt_uploaded_at = NOW() WHERE id = ?');
    $stmt->execute(array_merge([
        $name,
        $notes ?: null,
    ], $addressParams, [
        $filename,
        $id
    ]));
    
    error_log('ORG_UPDATE_UPLOAD: Database updated with filename: ' . $filename);

    // Remove previous file to enforce single-version policy
    if (!empty($prev) && $prev !== $filename) {
        $prevPath = $targetDir . '/' . $prev;
        if (is_file($prevPath)) @unlink($prevPath);
    }

    header('Location: /?page=organization/organizations-edit&id=' . $id . '&updated=1');
    exit;
}

// Handle removal of file if requested
if ($remove_tax) {
    // get previous file
    $st = $pdo->prepare('SELECT tax_exempt_file FROM organizations WHERE id = ?');
    $st->execute([$id]);
    $prev = $st->fetchColumn();
    if ($prev) {
        $path = __DIR__ . '/../../uploads/organizations/' . $prev;
        if (is_file($path)) @unlink($path);
    }
    $stmt = $pdo->prepare('UPDATE organizations SET name = ?, notes = ?' . $addressSql . ', tax_exempt_file = NULL, tax_exempt_uploaded_at = NULL WHERE id = ?');
    $stmt->execute(array_merge([
        $name,
        $notes ?: null,
    ], $addressParams, [
        $id
    ]));
    header('Location: /?page=organization/organizations-edit&id=' . $id . '&updated=1');
    exit;
}

// Default update (no file change)
$stmt = $pdo->prepare('UPDATE organizations SET name = ?, notes = ?' . $addressSql . ' WHERE id = ?');
$stmt->execute(array_merge([
    $name,
    $notes ?: null,
], $addressParams, [
    $id
]));

header('Location: /?page=organization/organizations-list&updated=1');
exit;
