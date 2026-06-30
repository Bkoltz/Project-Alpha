<?php
// src/views/pages/settings/permissions.php
// Permissions tab: app-level role matrix and role CRUD.

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/escaper.php';
require_once __DIR__ . '/../../../utils/permission_catalog.php';

$csrf = csrf_token();
$permissionGroups = permission_catalog();

$roles = [];
try {
    $roleStmt = $pdo->query('SELECT id, name, description, is_system, organization_id FROM roles WHERE organization_id IS NULL OR is_system = 1 ORDER BY is_system DESC, name');
    $roles = $roleStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    @error_log('[permissions view] roles load failed: ' . $e->getMessage());
}

$selectedRoleId = (int)($_GET['role_id'] ?? 0);
$selectedRole = null;
foreach ($roles as $role) {
    if ((int)$role['id'] === $selectedRoleId) {
        $selectedRole = $role;
        break;
    }
}

$rolePerms = [];
if ($selectedRole) {
    try {
        $rpStmt = $pdo->prepare('SELECT permission, allowed FROM role_permissions WHERE role_id = ?');
        $rpStmt->execute([$selectedRoleId]);
        foreach ($rpStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rolePerms[$row['permission']] = (bool)$row['allowed'];
        }
    } catch (Throwable $e) {
        @error_log('[permissions view] role permissions load failed: ' . $e->getMessage());
    }
}

function permLabel(string $perm): string
{
    [$module, $action] = explode('.', $perm, 2);
    return ucfirst(str_replace('_', ' ', $action));
}
?>

<div style="max-width:1100px">
    <h2 style="margin:0 0 8px 0">Permissions</h2>
    <p style="margin:0 0 20px 0;color:var(--muted)">Manage app-level roles and permission matrices for this PA install.</p>

    <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
        <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Saved.</div>
    <?php elseif (isset($_GET['saved']) && $_GET['saved'] === '0'): ?>
        <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Failed to save. <?php echo e($_GET['error'] ?? ''); ?></div>
    <?php elseif (!empty($_GET['error'])): ?>
        <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo e($_GET['error']); ?></div>
    <?php endif; ?>

    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:24px">
        <h3 style="margin:0 0 16px 0">Roles</h3>
        <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(260px,1fr))">
            <?php foreach ($roles as $role): ?>
                <div style="border:1px solid #e5e7eb;border-radius:8px;padding:14px;background:<?php echo $selectedRoleId === (int)$role['id'] ? '#f8fafc' : '#fff'; ?>">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px">
                        <strong><?php echo e($role['name']); ?></strong>
                        <?php if ((int)$role['is_system'] === 1): ?>
                            <span style="font-size:12px;padding:2px 8px;border-radius:4px;background:#dbeafe;color:#1e40af;font-weight:600">System</span>
                        <?php else: ?>
                            <span style="font-size:12px;padding:2px 8px;border-radius:4px;background:#f3f4f6;color:#374151;font-weight:600">Custom</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:13px;color:#6b7280;margin-bottom:10px;min-height:38px"><?php echo e($role['description'] ?: '-'); ?></div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <a href="/?page=settings&tab=permissions&role_id=<?php echo (int)$role['id']; ?>" style="padding:6px 12px;border-radius:6px;border:1px solid #ddd;background:#fff;text-decoration:none;color:#374151;font-size:13px">Edit permissions</a>
                        <?php if ((int)$role['is_system'] === 0 && (int)$role['id'] !== 1): ?>
                            <form method="post" action="/?page=settings/permissions-handler" onsubmit="return confirm('Delete this role?')" style="display:inline">
                                <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                                <input type="hidden" name="action" value="delete_role">
                                <input type="hidden" name="role_id" value="<?php echo (int)$role['id']; ?>">
                                <button type="submit" style="padding:6px 12px;border-radius:6px;border:1px solid #fca5a5;background:#fee2e2;color:#991b1b;font-size:13px;cursor:pointer">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($roles)): ?>
                <p style="color:#6b7280">No roles found.</p>
            <?php endif; ?>
        </div>

        <hr style="border:0;border-top:1px solid #e5e7eb;margin:20px 0">

        <h4 style="margin:0 0 12px 0">Create Role</h4>
        <form method="post" action="/?page=settings/permissions-handler" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="create_role">
            <label style="flex:1 1 220px">
                <div style="margin-bottom:4px;font-weight:600">Role name *</div>
                <input type="text" name="name" required maxlength="50" style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd" placeholder="e.g. Sales Manager">
            </label>
            <label style="flex:2 1 300px">
                <div style="margin-bottom:4px;font-weight:600">Description</div>
                <input type="text" name="description" style="width:100%;padding:8px;border-radius:6px;border:1px solid #ddd" placeholder="Optional">
            </label>
            <button type="submit" style="padding:8px 16px;border-radius:6px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">Create Role</button>
        </form>
    </div>

    <?php if ($selectedRole): ?>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap">
            <h3 style="margin:0">Permission Matrix: <?php echo e($selectedRole['name']); ?></h3>
            <?php if ((int)$selectedRole['is_system'] === 1): ?>
                <span style="font-size:13px;color:#6b7280">System roles can be edited but not deleted.</span>
            <?php endif; ?>
        </div>

        <?php if ((int)$selectedRole['id'] === 1): ?>
            <p style="color:#6b7280">The admin role grants all permissions and cannot be changed.</p>
        <?php else: ?>
            <form method="post" action="/?page=settings/permissions-handler">
                <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="action" value="save_role_permissions">
                <input type="hidden" name="role_id" value="<?php echo (int)$selectedRole['id']; ?>">
                <input type="hidden" name="referer" value="settings/permissions">

                <div style="display:grid;gap:16px">
                    <?php foreach ($permissionGroups as $group => $permissions): ?>
                        <fieldset style="border:1px solid #eee;border-radius:8px;padding:14px">
                            <legend style="padding:0 8px;font-weight:600"><?php echo e($group); ?></legend>
                            <div style="display:grid;gap:8px;grid-template-columns:repeat(auto-fill,minmax(180px,1fr))">
                                <?php foreach ($permissions as $perm): ?>
                                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                                        <input type="checkbox" name="permissions[<?php echo e($perm); ?>]" value="1" <?php echo !empty($rolePerms[$perm]) ? 'checked' : ''; ?>>
                                        <span style="font-size:13px"><?php echo e(permLabel($perm)); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:20px">
                    <button type="submit" style="padding:10px 20px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">Save Permissions</button>
                    <a href="/?page=settings&tab=permissions" style="margin-left:12px;padding:10px 20px;border-radius:8px;border:1px solid #ddd;background:#fff;text-decoration:none;color:#374151;font-size:14px">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px">
        <h3 style="margin:0 0 8px 0">User Role Assignment</h3>
        <p style="margin:0;color:#6b7280">
            User roles are app-level in this version. Assign roles and user-specific permission overrides from
            <a href="/?page=accounts">Account Management</a>.
        </p>
    </div>
</div>
