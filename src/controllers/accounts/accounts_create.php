<?php
// src/controllers/accounts/accounts_create.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/password_policy.php';
require_once __DIR__ . '/../../utils/acl.php';

// Ensure user is logged in and is an admin
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /?page=login');
    exit;
}


$email = trim($_POST['email'] ?? '');
$username = trim($_POST['username'] ?? '');
$postedRoleId = (int)($_POST['role_id'] ?? 0);
$postedLegacyRole = trim($_POST['role'] ?? 'user');
$password = $_POST['password'] ?? '';
$forceReset = !empty($_POST['force_reset']);

// Validation
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: /?page=accounts&error=' . urlencode('Invalid email address'));
    exit;
}

if ($username !== '') {
    $usernameCheck = $pdo->prepare('SELECT id FROM users WHERE username = ? AND deleted_at IS NULL LIMIT 1');
    $usernameCheck->execute([$username]);
    if ($usernameCheck->fetchColumn()) {
        header('Location: /?page=accounts&action=create&error=' . urlencode('Username already exists'));
        exit;
    }
}

$pwdErr = password_policy_error((string)$password);
if ($pwdErr !== null) {
    header('Location: /?page=accounts&error=' . urlencode($pwdErr));
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

// Check if email already exists
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    header('Location: /?page=accounts&error=' . urlencode('Email already exists'));
    exit;
}

// Hash password
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// Insert user
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO users (email, username, password_hash, role, force_password_reset) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$email, $username ?: null, $passwordHash, $role, $forceReset ? 1 : 0]);
    $newUserId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO team_members (user_id,display_name,email,is_active,profile_source) VALUES (?,?,?,?, 'pa')")
        ->execute([$newUserId,$username!==''?$username:$email,$email,1]);

    require_once __DIR__ . '/../../utils/permission_catalog.php';
    if ($role !== 'admin') {
        $allPermissions = permission_catalog_flat();
        try {
            $insertStmt = $pdo->prepare('INSERT INTO user_permissions_overrides (user_id, organization_id, permission, allowed) VALUES (?, ?, ?, ?)');
            foreach ($allPermissions as $perm => $_) {
                $allowKey = 'allow_' . str_replace('.', '_', $perm);
                $denyKey  = 'deny_' . str_replace('.', '_', $perm);
                if (!empty($_POST[$allowKey])) {
                    $insertStmt->execute([$newUserId, $orgId, $perm, 1]);
                } elseif (!empty($_POST[$denyKey])) {
                    $insertStmt->execute([$newUserId, $orgId, $perm, 0]);
                }
            }
        } catch (Throwable $e) { /* non-fatal — ACL tables may not exist */ }
    }

    $pdo->commit();
    audit_log($pdo, 'user.create', 'user', $newUserId, ['email' => $email, 'role' => $role, 'acl_role' => $roleName, 'role_id' => $roleId, 'team_member_created'=>true]);
    header('Location: /?page=accounts&created=1');
} catch (Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('Failed to create user: ' . $e->getMessage());
    header('Location: /?page=accounts&error=' . urlencode('Failed to create user'));
}
exit;
