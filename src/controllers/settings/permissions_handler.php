<?php
// src/controllers/settings/permissions_handler.php
// POST handler for role permissions, role CRUD, user role assignment, and per-user overrides.

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl_middleware.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Gate: only admin may manage permissions
if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    deny_response('settings/permissions');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /?page=settings&tab=permissions');
    exit;
}

$token = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
    header('Location: /?page=settings&tab=permissions&error=' . rawurlencode('Invalid request (CSRF)'));
    exit;
}

$action = $_POST['action'] ?? '';

// All permission keys that the UI exposes
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
    foreach ($keys as $key) {
        $allPermissions[$key] = $group;
    }
}

$activeOrgId = get_active_org_id();

try {
    switch ($action) {
        case 'save_role_permissions':
            $roleId = (int)($_POST['role_id'] ?? 0);
            if ($roleId <= 0) {
                throw new Exception('Role required');
            }

            // Verify role exists and is not admin system role (id 1)
            $roleStmt = $pdo->prepare('SELECT id, is_system, organization_id FROM roles WHERE id = ?');
            $roleStmt->execute([$roleId]);
            $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
            if (!$role) {
                throw new Exception('Role not found');
            }
            if ((int)$role['id'] === 1) {
                throw new Exception('System admin role cannot be edited');
            }

            $pdo->beginTransaction();

            // Reset all known permissions for this role to 0, then set posted to 1
            $zeroStmt = $pdo->prepare('INSERT INTO role_permissions (role_id, permission, allowed) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE allowed = 0');
            foreach ($allPermissions as $perm => $_group) {
                $zeroStmt->execute([$roleId, $perm]);
            }

            $oneStmt = $pdo->prepare('INSERT INTO role_permissions (role_id, permission, allowed) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE allowed = 1');
            foreach ($_POST['permissions'] ?? [] as $perm => $val) {
                $perm = preg_replace('/[^a-z0-9._]/i', '', $perm);
                if ($perm === '' || !isset($allPermissions[$perm])) {
                    continue;
                }
                if ($val === '1' || $val === 1 || $val === true || $val === 'on') {
                    $oneStmt->execute([$roleId, $perm]);
                }
            }

            $pdo->commit();
            header('Location: /?page=settings&tab=permissions&role_id=' . $roleId . '&saved=1');
            exit;

        case 'create_role':
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            if ($name === '') {
                throw new Exception('Role name required');
            }

            // Unique by org; NULL means global (matches roles schema uq_role_name_org)
            $orgId = $activeOrgId > 0 ? $activeOrgId : null;
            $stmt = $pdo->prepare('INSERT INTO roles (name, description, is_system, organization_id) VALUES (?, ?, 0, ?)');
            try {
                $stmt->execute([$name, $description, $orgId]);
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    throw new Exception('A role with that name already exists in this organization');
                }
                throw $e;
            }
            $roleId = (int)$pdo->lastInsertId();
            header('Location: /?page=settings&tab=permissions&role_id=' . $roleId . '&saved=1');
            exit;

        case 'delete_role':
            $roleId = (int)($_POST['role_id'] ?? 0);
            if ($roleId <= 0) {
                throw new Exception('Role required');
            }
            // Prevent deleting system or admin role
            $stmt = $pdo->prepare('DELETE FROM roles WHERE id = ? AND is_system = 0 AND id <> 1');
            $stmt->execute([$roleId]);
            if ($stmt->rowCount() === 0) {
                throw new Exception('Cannot delete system role');
            }
            header('Location: /?page=settings&tab=permissions&saved=1');
            exit;

        case 'save_user_role':
            // POST arrays: user_orgs[uo_id] = role_id
            $assignments = $_POST['user_orgs'] ?? [];
            if (!is_array($assignments) || empty($assignments)) {
                throw new Exception('No assignments provided');
            }
            $stmt = $pdo->prepare('UPDATE user_organizations SET role_id = ? WHERE id = ?');
            foreach ($assignments as $uoId => $roleId) {
                $uoId = (int)$uoId;
                $roleId = (int)$roleId;
                if ($uoId <= 0) {
                    continue;
                }
                $stmt->execute([$roleId > 0 ? $roleId : null, $uoId]);
            }
            header('Location: /?page=settings&tab=permissions&saved=1');
            exit;

        case 'save_user_overrides':
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new Exception('User required');
            }
            $orgId = $activeOrgId > 0 ? $activeOrgId : null;

            $pdo->beginTransaction();

            // Wipe existing overrides for this user/org combo for known permissions
            $wipeStmt = $pdo->prepare('DELETE FROM user_permissions_overrides WHERE user_id = ? AND organization_id <=> ? AND permission = ?');
            foreach ($allPermissions as $perm => $_group) {
                $wipeStmt->execute([$userId, $orgId, $perm]);
            }

            // Insert allow/deny overrides
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

            $pdo->commit();

            // Clear cached permission hash so it is recomputed next request
            if (isset($_SESSION['user']['permissions_hash'])) {
                unset($_SESSION['user']['permissions_hash']);
            }

            header('Location: /?page=account-edit&id=' . $userId . '&saved=1');
            exit;

        default:
            throw new Exception('Unknown action');
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @error_log('[PermissionsHandler] Error: ' . $e->getMessage());
    $referrer = ($_POST['referer'] ?? '') !== '' ? (string)$_POST['referer'] : 'settings/permissions';
    header('Location: /?page=' . rawurlencode($referrer) . '&saved=0&error=' . rawurlencode($e->getMessage()));
    exit;
}

header('Location: /?page=settings&tab=permissions');
exit;
