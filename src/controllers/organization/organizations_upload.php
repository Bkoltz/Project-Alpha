<?php
// src/controllers/organization/organizations_upload.php
require_once __DIR__ . '/../../config/db.php';

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    header('Location: /?page=organization/organizations-list&error=Invalid%20organization');
    exit;
}

if (empty($_FILES['tax_exempt_file']) || !is_uploaded_file($_FILES['tax_exempt_file']['tmp_name'])) {
    header('Location: /?page=organization/organization-view&id=' . $id . '&error=No%20file%20uploaded');
    exit;
}

$allowed = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png'
];

$tmp = $_FILES['tax_exempt_file']['tmp_name'];
$mime = mime_content_type($tmp) ?: $_FILES['tax_exempt_file']['type'];
if (!array_key_exists($mime, $allowed)) {
    header('Location: /?page=organization/organization-view&id=' . $id . '&error=Invalid%20file%20type');
    exit;
}

if ($_FILES['tax_exempt_file']['size'] > 8 * 1024 * 1024) {
    header('Location: /?page=organization/organization-view&id=' . $id . '&error=File%20too%20large');
    exit;
}

$safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', basename($_FILES['tax_exempt_file']['name']));
$targetDir = __DIR__ . '/../../uploads/organizations';
if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
$filename = time() . '_' . bin2hex(random_bytes(6)) . '_' . $safeName;
$targetPath = $targetDir . '/' . $filename;

if (!move_uploaded_file($tmp, $targetPath)) {
    header('Location: /?page=organization/organization-view&id=' . $id . '&error=Failed%20to%20save%20file');
    exit;
}

// Fetch previous file name
$prevStmt = $pdo->prepare('SELECT tax_exempt_file FROM organizations WHERE id = ?');
$prevStmt->execute([$id]);
$prev = $prevStmt->fetchColumn();

// Update DB with new filename
$stmt = $pdo->prepare('UPDATE organizations SET tax_exempt_file = ?, tax_exempt_uploaded_at = NOW() WHERE id = ?');
$stmt->execute([
    $filename,
    $id
]);

// Remove previous file (single-version policy)
if (!empty($prev) && $prev !== $filename) {
    $prevPath = $targetDir . '/' . $prev;
    if (is_file($prevPath)) @unlink($prevPath);
}

header('Location: /?page=organization/organization-view&id=' . $id . '&uploaded=1');
exit;
