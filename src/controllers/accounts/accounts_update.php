<?php
// src/controllers/accounts/accounts_update.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/permission_catalog.php';

// Ensure user is logged in and is an admin
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /?page=login');
    exit;
}


$userId = (int)($_POST['user_id'] ?? 0);
$email = trim($_POST['email'] ?? '');
$username = trim($_POST['username'] ?? '');
$postedRoleId = (int)($_POST['role_id'] ?? 0);
$postedLegacyRole = trim($_POST['role'] ?? 'user');
$forceReset = !empty($_POST['force_reset']);
$isDisabled = !empty($_POST['is_disabled']);
$documentSenderEnabled = !empty($_POST['document_sender_enabled']);
$documentSenderName = trim((string)($_POST['document_sender_name'] ?? ''));
$documentSenderCompany = trim((string)($_POST['document_sender_company'] ?? ''));
$documentSenderAddressLine1 = trim((string)($_POST['document_sender_address_line1'] ?? ''));
$documentSenderAddressLine2 = trim((string)($_POST['document_sender_address_line2'] ?? ''));
$documentSenderCity = trim((string)($_POST['document_sender_city'] ?? ''));
$documentSenderState = trim((string)($_POST['document_sender_state'] ?? ''));
$documentSenderPostal = trim((string)($_POST['document_sender_postal'] ?? ''));
$documentSenderCountry = trim((string)($_POST['document_sender_country'] ?? ''));
$documentSenderPhone = trim((string)($_POST['document_sender_phone'] ?? ''));
$documentSenderEmail = trim((string)($_POST['document_sender_email'] ?? ''));

// Validation
if ($userId <= 0) {
    header('Location: /?page=accounts&error=' . urlencode('Invalid user ID'));
    exit;
}

// Protect the seeded admin account (id=1)
if ($userId === 1) {
    header('Location: /?page=accounts&error=' . urlencode('The default admin account cannot be modified'));
    exit;
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Invalid email address'));
    exit;
}

if ($documentSenderEmail !== '' && !filter_var($documentSenderEmail, FILTER_VALIDATE_EMAIL)) {
    header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Invalid document sender email address'));
    exit;
}

$orgId = null;

$selectedRole = null;
if ($postedRoleId > 0) {
    try {
        $roleStmt = $pdo->prepare('SELECT id, name, organization_id FROM roles WHERE id = ? AND (organization_id IS NULL OR is_system = 1) LIMIT 1');
        $roleStmt->execute([$postedRoleId]);
        $selectedRole = $roleStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $selectedRole = null;
    }
}

if (!$selectedRole) {
    $fallbackRoleName = $postedLegacyRole === 'admin' ? 'admin' : 'member';
    $fallbackRoleId = role_id_by_name($pdo, $fallbackRoleName, $orgId);
    if ($fallbackRoleId === null && $fallbackRoleName !== 'member') {
        $fallbackRoleName = 'member';
        $fallbackRoleId = role_id_by_name($pdo, 'member', $orgId);
    }
    if ($fallbackRoleId !== null) {
        $selectedRole = ['id' => $fallbackRoleId, 'name' => $fallbackRoleName, 'organization_id' => $orgId];
    }
}

$roleName = (string)($selectedRole['name'] ?? 'member');
$roleId = isset($selectedRole['id']) ? (int)$selectedRole['id'] : null;
$role = in_array($roleName, ['admin', 'owner', 'staff', 'member'], true) ? $roleName : 'member';

// Check if email is taken by another user
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
$stmt->execute([$email, $userId]);
if ($stmt->fetch()) {
    header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Email already exists'));
    exit;
}

// Update user
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('UPDATE users SET
        email = ?,
        username = ?,
        role = ?,
        is_disabled = ?,
        force_password_reset = ?,
        document_sender_enabled = ?,
        document_sender_name = ?,
        document_sender_company = ?,
        document_sender_address_line1 = ?,
        document_sender_address_line2 = ?,
        document_sender_city = ?,
        document_sender_state = ?,
        document_sender_postal = ?,
        document_sender_country = ?,
        document_sender_phone = ?,
        document_sender_email = ?
        WHERE id = ?');
    $stmt->execute([
        $email,
        $username ?: null,
        $role,
        $isDisabled ? 1 : 0,
        $forceReset ? 1 : 0,
        $documentSenderEnabled ? 1 : 0,
        $documentSenderName !== '' ? $documentSenderName : null,
        $documentSenderCompany !== '' ? $documentSenderCompany : null,
        $documentSenderAddressLine1 !== '' ? $documentSenderAddressLine1 : null,
        $documentSenderAddressLine2 !== '' ? $documentSenderAddressLine2 : null,
        $documentSenderCity !== '' ? $documentSenderCity : null,
        $documentSenderState !== '' ? $documentSenderState : null,
        $documentSenderPostal !== '' ? $documentSenderPostal : null,
        $documentSenderCountry !== '' ? $documentSenderCountry : null,
        $documentSenderPhone !== '' ? $documentSenderPhone : null,
        $documentSenderEmail !== '' ? $documentSenderEmail : null,
        $userId
    ]);

    if (!empty($_POST['save_account_permissions'])) {
        $allPermissions = permission_catalog_flat();
        $wipeStmt = $pdo->prepare('DELETE FROM user_permissions_overrides WHERE user_id = ? AND organization_id IS NULL AND permission = ?');
        foreach ($allPermissions as $perm => $_group) {
            $wipeStmt->execute([$userId, $perm]);
        }

        if ($role !== 'admin') {
            $insertStmt = $pdo->prepare('INSERT INTO user_permissions_overrides (user_id, organization_id, permission, allowed) VALUES (?, ?, ?, ?)');
            foreach ($allPermissions as $perm => $_group) {
                $allowKey = 'allow_' . str_replace('.', '_', $perm);
                $denyKey  = 'deny_' . str_replace('.', '_', $perm);
                if (!empty($_POST[$allowKey])) {
                    $insertStmt->execute([$userId, $orgId, $perm, 1]);
                } elseif (!empty($_POST[$denyKey])) {
                    $insertStmt->execute([$userId, $orgId, $perm, 0]);
                }
            }
        }
    }

    $pdo->commit();

    audit_log($pdo, 'user.update', 'user', $userId, ['email' => $email, 'role' => $role, 'acl_role' => $roleName, 'role_id' => $roleId, 'is_disabled' => $isDisabled ? 1 : 0, 'document_sender_enabled' => $documentSenderEnabled ? 1 : 0]);
    header('Location: /?page=account-edit&id=' . $userId . '&success=updated');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Failed to update user: ' . $e->getMessage());
    header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Failed to update user'));
}
exit;
