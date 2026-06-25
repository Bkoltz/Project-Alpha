<?php
// src/controllers/accounts/accounts_create.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/password_policy.php';

// Ensure user is logged in and is an admin
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /?page=login');
    exit;
}


$email = trim($_POST['email'] ?? '');
$username = trim($_POST['username'] ?? '');
$role = trim($_POST['role'] ?? 'user');
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

if (!in_array($role, ['user', 'admin'])) {
    $role = 'user';
}

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
    $activeOrgId = (int)($_SESSION['user']['active_org_id'] ?? 0);
    $orgId = $activeOrgId > 0 ? $activeOrgId : null;

    // Assign the user to the default organization with the 'member' role
    try {
        $defaultOrg = $pdo->query('SELECT id FROM organizations ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($defaultOrg) {
            $stmtOrg = $pdo->prepare('INSERT INTO user_organizations (user_id, organization_id, role, is_default) VALUES (?, ?, ?, 1)');
            $stmtOrg->execute([$newUserId, (int)$defaultOrg, 'member']);
        }
    } catch (Throwable $e) { /* non-fatal */ }

    $permissionGroups = [
        'Quotes'        => ['quotes.view','quotes.create','quotes.edit','quotes.delete','quotes.send','quotes.approve','quotes.reject'],
        'Contracts'     => ['contracts.view','contracts.create','contracts.edit','contracts.delete','contracts.sign','contracts.complete','contracts.void','contracts.send'],
        'Invoices'      => ['invoices.view','invoices.create','invoices.edit','invoices.delete','invoices.mark_paid','invoices.send'],
        'Clients'       => ['clients.view','clients.create','clients.edit','clients.delete','clients.purge','clients.restore'],
        'Projects'      => ['projects.view','projects.create','projects.edit','projects.delete','projects.search'],
        'Jobs'          => ['jobs.view','jobs.edit','jobs.delete','jobs.search'],
        'Financial'     => ['financial.view','financial.manage','financial.export','financial.audit'],
        'Reports'       => ['reports.view'],
        'Settings'      => ['settings.view','settings.manage'],
        'Users'         => ['users.view','users.manage','users.reset_password','users.delete'],
        'API Keys'      => ['api_keys.view','api_keys.manage'],
        'Billing'       => ['billing.view','billing.manage'],
        'Organizations' => ['organizations.view','organizations.manage','organizations.delete'],
        'Public Links'  => ['public_links.view','public_links.create','public_links.revoke','public_links.manage'],
        'Time Tracking' => ['time_tracking.view','time_tracking.manage'],
        '2FA'           => ['2fa.manage'],
        'Profile'       => ['profile.view','profile.edit'],
    ];
    $allPermissions = [];
    foreach ($permissionGroups as $group => $keys) {
        foreach ($keys as $key) { $allPermissions[$key] = $group; }
    }
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

    audit_log($pdo, 'user.create', 'user', $newUserId, ['email' => $email, 'role' => $role]);
    header('Location: /?page=accounts&created=1');
} catch (PDOException $e) {
    error_log('Failed to create user: ' . $e->getMessage());
    header('Location: /?page=accounts&error=' . urlencode('Failed to create user'));
}
exit;
