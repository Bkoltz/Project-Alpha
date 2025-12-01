<?php
// src/controllers/organization/organizations_create.php
require_once __DIR__ . '/../../config/db.php';

$name = trim($_POST['name'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$return_to = trim($_POST['return_to'] ?? '');

if ($name === '') {
    header('Location: /?page=organization/organizations-create&error=Name%20is%20required');
    exit;
}

// Handle optional file upload (from full-page create)
$tax_filename = null;
if (!empty($_FILES['tax_exempt_file']) && is_uploaded_file($_FILES['tax_exempt_file']['tmp_name'])) {
    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png'
    ];
    $tmp = $_FILES['tax_exempt_file']['tmp_name'];
    $mime = mime_content_type($tmp) ?: $_FILES['tax_exempt_file']['type'];
    if (!array_key_exists($mime, $allowed)) {
        header('Location: /?page=organization/organizations-create&error=Invalid%20file%20type');
        exit;
    }
    if ($_FILES['tax_exempt_file']['size'] > 8 * 1024 * 1024) {
        header('Location: /?page=organization/organizations-create&error=File%20too%20large');
        exit;
    }
    $safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', basename($_FILES['tax_exempt_file']['name']));
    $targetDir = __DIR__ . '/../../uploads/organizations';
    if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
    $tax_filename = time() . '_' . bin2hex(random_bytes(6)) . '_' . $safeName;
    $targetPath = $targetDir . '/' . $tax_filename;
    if (!move_uploaded_file($tmp, $targetPath)) {
        header('Location: /?page=organization/organizations-create&error=Failed%20to%20save%20file');
        exit;
    }
}

$stmt = $pdo->prepare('INSERT INTO organizations (name, notes, tax_exempt_file, tax_exempt_uploaded_at) VALUES (?, ?, ?, NOW())');
$stmt->execute([
    $name,
    $notes ?: null,
    $tax_filename
]);

$id = (int)$pdo->lastInsertId();
if ($return_to === 'clients-create') {
    // redirect back to client create with the new org info
    $q = http_build_query([
        'page' => 'clients-create',
        'org_created' => 1,
        'org_id' => $id,
        'org_name' => $name,
    ]);
    header('Location: /?' . $q);
    exit;
}

header('Location: /?page=organization/organizations-list&created=1');
exit;
