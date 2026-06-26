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

$pwdErr = password_policy_error((string)$password);
if ($pwdErr !== null) {
    header('Location: /?page=accounts&error=' . urlencode($pwdErr));
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
    $stmt = $pdo->prepare('INSERT INTO users (email, username, password_hash, role, force_password_reset) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$email, $username ?: null, $passwordHash, $role, $forceReset ? 1 : 0]);
    $newUserId = (int)$pdo->lastInsertId();

    // Save permission overrides if provided
    // Assign the user to the default organization with the mapped DB role and role_id
    try {
        $defaultOrg = $orgId ?: $pdo->query('SELECT id FROM organizations ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($defaultOrg) {
            if ($roleId === null) { $roleId = role_id_by_name($pdo, $roleName, (int)$defaultOrg); }
            if ($roleId === null) { $roleId = role_id_by_name($pdo, $roleName, null); }
            if ($roleId === null) { @error_log('[accounts_create] CRITICAL: cannot resolve role_id for '.$roleName); }
            $stmtOrg = $pdo->prepare('INSERT INTO user_organizations (user_id, organization_id, role, role_id, is_default) VALUES (?, ?, ?, ?, 1)');
            $stmtOrg->execute([$newUserId, (int)$defaultOrg, $roleName, $roleId]);
        }
    } catch (Throwable $e) { /* non-fatal */ }

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

    audit_log($pdo, 'user.create', 'user', $newUserId, ['email' => $email, 'role' => $role, 'acl_role' => $roleName, 'role_id' => $roleId]);
    header('Location: /?page=accounts&created=1');
} catch (PDOException $e) {
    error_log('Failed to create user: ' . $e->getMessage());
    header('Location: /?page=accounts&error=' . urlencode('Failed to create user'));
}
exit;
