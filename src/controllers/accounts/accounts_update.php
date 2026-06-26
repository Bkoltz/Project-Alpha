<?php
// src/controllers/accounts/accounts_update.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/acl.php';

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

$activeOrgId = (int)($_SESSION['user']['active_org_id'] ?? 0);
$orgId = $activeOrgId > 0 ? $activeOrgId : null;

$selectedRole = null;
if ($postedRoleId > 0) {
    try {
        $roleStmt = $pdo->prepare('SELECT id, name, organization_id FROM roles WHERE id = ? AND (organization_id <=> ? OR is_system = 1) LIMIT 1');
        $roleStmt->execute([$postedRoleId, $orgId]);
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
$role = $roleName === 'admin' ? 'admin' : 'user';

// Check if email is taken by another user
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
$stmt->execute([$email, $userId]);
if ($stmt->fetch()) {
    header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Email already exists'));
    exit;
}

// Update user
try {
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

    $targetOrg = $orgId ?: $pdo->query('SELECT organization_id FROM user_organizations WHERE user_id = ' . (int)$userId . ' ORDER BY is_default DESC LIMIT 1')->fetchColumn();
    if ($targetOrg) {
        if ($roleId === null) { $roleId = role_id_by_name($pdo, $roleName, (int)$targetOrg); }
        if ($roleId === null) { $roleId = role_id_by_name($pdo, $roleName, null); }
        $exists = $pdo->prepare('SELECT id FROM user_organizations WHERE user_id = ? AND organization_id = ? LIMIT 1');
        $exists->execute([$userId, (int)$targetOrg]);
        $uoId = $exists->fetchColumn();
        if ($uoId) {
            $updOrg = $pdo->prepare('UPDATE user_organizations SET role = ?, role_id = ? WHERE id = ?');
            $updOrg->execute([$roleName, $roleId, (int)$uoId]);
        } else {
            $insOrg = $pdo->prepare('INSERT INTO user_organizations (user_id, organization_id, role, role_id, is_default) VALUES (?, ?, ?, ?, 1)');
            $insOrg->execute([$userId, (int)$targetOrg, $roleName, $roleId]);
        }
    }
    
    audit_log($pdo, 'user.update', 'user', $userId, ['email' => $email, 'role' => $role, 'acl_role' => $roleName, 'role_id' => $roleId, 'is_disabled' => $isDisabled ? 1 : 0, 'document_sender_enabled' => $documentSenderEnabled ? 1 : 0]);
    header('Location: /?page=account-edit&id=' . $userId . '&success=updated');
} catch (PDOException $e) {
    error_log('Failed to update user: ' . $e->getMessage());
    header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Failed to update user'));
}
exit;
