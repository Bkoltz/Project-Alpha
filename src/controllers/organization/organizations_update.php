<?php
// src/controllers/organization/organizations_update.php
require_once __DIR__ . '/../../config/db.php';

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$remove_tax = !empty($_POST['remove_tax_file']);

if ($id <= 0 || $name === '') {
    header('Location: /?page=organization/organizations-edit&id=' . $id . '&error=Invalid%20input');
    exit;
}

// Handle file upload if present
if (!empty($_FILES['tax_exempt_file']) && is_uploaded_file($_FILES['tax_exempt_file']['tmp_name'])) {
    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png'
    ];
    $tmp = $_FILES['tax_exempt_file']['tmp_name'];
    $mime = mime_content_type($tmp) ?: $_FILES['tax_exempt_file']['type'];
    if (!array_key_exists($mime, $allowed)) {
        header('Location: /?page=organization/organizations-edit&id=' . $id . '&error=Invalid%20file%20type');
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
    if (!move_uploaded_file($tmp, $targetPath)) {
        header('Location: /?page=organization/organizations-edit&id=' . $id . '&error=Failed%20to%20save%20file');
        exit;
    }
    // Save filename to DB (relative name) and remove previous file if present
    $prevStmt = $pdo->prepare('SELECT tax_exempt_file FROM organizations WHERE id = ?');
    $prevStmt->execute([$id]);
    $prev = $prevStmt->fetchColumn();

    $stmt = $pdo->prepare('UPDATE organizations SET name = ?, notes = ?, tax_exempt_file = ?, tax_exempt_uploaded_at = NOW() WHERE id = ?');
    $stmt->execute([
        $name,
        $notes ?: null,
        $filename,
        $id
    ]);

    // Remove previous file to enforce single-version policy
    if (!empty($prev) && $prev !== $filename) {
        $prevPath = $targetDir . '/' . $prev;
        if (is_file($prevPath)) @unlink($prevPath);
    }

    header('Location: /?page=organization/organization-view&id=' . $id . '&updated=1');
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
    $stmt = $pdo->prepare('UPDATE organizations SET name = ?, notes = ?, tax_exempt_file = NULL, tax_exempt_uploaded_at = NULL WHERE id = ?');
    $stmt->execute([
        $name,
        $notes ?: null,
        $id
    ]);
    header('Location: /?page=organization/organization-view&id=' . $id . '&updated=1');
    exit;
}

// Default update (no file change)
$stmt = $pdo->prepare('UPDATE organizations SET name = ?, notes = ? WHERE id = ?');
$stmt->execute([
    $name,
    $notes ?: null,
    $id
]);

header('Location: /?page=organization/organization-view&id=' . $id . '&updated=1');
exit;
