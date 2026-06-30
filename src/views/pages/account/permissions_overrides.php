<?php
// src/views/pages/account/permissions_overrides.php
// Partial: per-user permission overrides on account-edit page.
// Expects: $userId (int), $pdo (PDO), $csrf (string).
// Optionally: $targetRole (string) — if 'admin', render admin notice instead of the grid.

require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/escaper.php';

// Permission groups exposed in the matrix — canonical catalog
require_once __DIR__ . '/../../../utils/permission_catalog.php';
$permissionGroups = permission_catalog();

$allPermissions = [];
foreach ($permissionGroups as $group => $keys) {
    foreach ($keys as $key) {
        $allPermissions[$key] = $group;
    }
}

// Role context: defaults to member if not supplied by including page.
$targetRole = $targetRole ?? ($user['role'] ?? 'member');
$targetRole = in_array($targetRole, ['admin', 'owner', 'staff', 'member'], true) ? $targetRole : 'member';

// Load existing app-level overrides for this user
$overrides = [];
try {
    $stmt = $pdo->prepare('SELECT permission, allowed FROM user_permissions_overrides WHERE user_id = ? AND organization_id IS NULL');
    $stmt->execute([(int)$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $overrides[$row['permission']] = (int)$row['allowed'];
    }
} catch (Throwable $e) {
    @error_log('[permissions_overrides] load failed: ' . $e->getMessage());
}

// Load the user's current role permissions to show as inherited baseline
$inheritedPerms = [];
try {
    $roleId = role_id_by_name($pdo, $targetRole, null);
    if ($roleId) {
        $rpStmt = $pdo->prepare('SELECT permission, allowed FROM role_permissions WHERE role_id = ?');
        $rpStmt->execute([(int)$roleId]);
        foreach ($rpStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $inheritedPerms[$row['permission']] = (bool)$row['allowed'];
        }
    }
} catch (Throwable $e) {
    // ACL tables may not exist yet
}

function permLabel(string $perm): string {
    [$module, $action] = explode('.', $perm, 2);
    return ucfirst(str_replace('_', ' ', $action));
}

$permissionsEmbedInParentForm = !empty($permissionsEmbedInParentForm);
?>

<div id="permissions-panel-edit" class="pa-permissions-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;transition:opacity 180ms ease,max-height 220ms ease,padding 180ms ease,margin 180ms ease,border-width 180ms ease;max-height:5000px;overflow:hidden;">
    <h3 style="margin:0 0 8px 0;">Permissions</h3>
    <p style="margin:0 0 16px 0;color:#6b7280;font-size:14px;">Set what this user can access. Admins always have full access — these settings apply to non-admin users only.</p>

    <?php if (false): ?>
        <div style="padding:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;color:#92400e;font-size:13px;margin-bottom:16px;">
            ⚠️ No active organization selected. Select an organization to manage overrides for that org.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
        <div style="margin:0 0 16px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4;">Overrides saved.</div>
    <?php endif; ?>

        <div id="admin-permissions-note-edit" class="pa-admin-notice" style="display:<?php echo $targetRole === 'admin' ? 'block' : 'none'; ?>;padding:16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;color:#0c4a6e;">
            <p style="margin:0;">Admins have full access to everything. Per-permission settings don't apply to admin accounts.</p>
        </div>

        <div id="permissions-grid-edit" style="display:<?php echo $targetRole === 'admin' ? 'none' : 'block'; ?>;">
        <?php if ($permissionsEmbedInParentForm): ?>
            <input type="hidden" name="save_account_permissions" value="1">
        <?php else: ?>
        <form method="post" action="/?page=settings/permissions-handler">
            <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="save_user_overrides">
            <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
            <input type="hidden" name="referer" value="account-edit">
        <?php endif; ?>

            <div style="display:grid;gap:16px;">
                <?php foreach ($permissionGroups as $group => $permissions): ?>
                    <fieldset style="border:1px solid #eee;border-radius:8px;padding:14px;">
                        <legend style="padding:0 8px;font-weight:600;"><?php echo e($group); ?></legend>
                        <div style="margin-bottom:8px;display:flex;gap:8px;">
                            <button type="button" onclick="selectAllInSection(this, 'allow')" style="padding:4px 10px;border-radius:4px;border:1px solid #d1d5db;background:#f0fdf4;color:#166534;font-size:12px;cursor:pointer;">Allow All</button>
                            <button type="button" onclick="selectAllInSection(this, 'deny')" style="padding:4px 10px;border-radius:4px;border:1px solid #d1d5db;background:#fef2f2;color:#991b1b;font-size:12px;cursor:pointer;">Deny All</button>
                        </div>

                        <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr 2fr;">
                            <div style="font-size:12px;font-weight:600;color:#6b7280;">Allow</div>
                            <div style="font-size:12px;font-weight:600;color:#6b7280;">Deny</div>
                            <div style="font-size:12px;font-weight:600;color:#6b7280;">Permission</div>

                            <?php foreach ($permissions as $perm): ?>
                                <?php
                                $allowKey = 'allow_' . str_replace('.', '_', $perm);
                                $denyKey  = 'deny_' . str_replace('.', '_', $perm);
                                // Determine effective state: override > inherited > deny
                                if (isset($overrides[$perm])) {
                                    $isAllowed = $overrides[$perm] === 1;
                                } elseif (isset($inheritedPerms[$perm])) {
                                    $isAllowed = $inheritedPerms[$perm];
                                } else {
                                    $isAllowed = false; // Default to deny
                                }
                                $allowChecked = $isAllowed ? 'checked' : '';
                                $denyChecked  = !$isAllowed ? 'checked' : '';
                                $inheritedText = '';
                                if (!isset($overrides[$perm]) && isset($inheritedPerms[$perm])) {
                                    $inheritedText = ' <span style="color:#9ca3af;font-size:11px">(role)</span>';
                                }
                                ?>
                                <label style="display:flex;align-items:center;justify-content:center;cursor:pointer;">
                                    <input type="checkbox" name="<?php echo e($allowKey); ?>" value="1" <?php echo $allowChecked; ?> aria-label="Allow <?php echo e($perm); ?>">
                                </label>
                                <label style="display:flex;align-items:center;justify-content:center;cursor:pointer;">
                                    <input type="checkbox" name="<?php echo e($denyKey); ?>" value="1" <?php echo $denyChecked; ?> aria-label="Deny <?php echo e($perm); ?>">
                                </label>
                                <div style="font-size:13px;"><?php echo e(permLabel($perm)); ?> <span style="color:#9ca3af;font-size:12px;">(<?php echo e($perm); ?>)</span><?php echo $inheritedText; ?></div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:20px;">
                <button type="submit" style="padding:10px 20px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer;"><?php echo $permissionsEmbedInParentForm ? 'Save Changes' : 'Save Permissions'; ?></button>
            </div>
        <?php if (!$permissionsEmbedInParentForm): ?>
        </form>
        <?php endif; ?>
        <script>
        function selectAllInSection(btn, type) {
            var fieldset = btn.closest('fieldset');
            var checkboxes = fieldset.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(function(cb) {
                if (type === 'allow' && cb.name.startsWith('allow_')) {
                    cb.checked = true;
                    var denyName = cb.name.replace('allow_', 'deny_');
                    var denyCb = fieldset.querySelector('input[name="' + denyName + '"]');
                    if (denyCb) denyCb.checked = false;
                } else if (type === 'deny' && cb.name.startsWith('deny_')) {
                    cb.checked = true;
                    var allowName = cb.name.replace('deny_', 'allow_');
                    var allowCb = fieldset.querySelector('input[name="' + allowName + '"]');
                    if (allowCb) allowCb.checked = false;
                }
            });
        }
        // Mutual exclusion: checking Allow unchecks Deny and vice versa
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('input[name^="allow_"]').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    if (this.checked) {
                        var denyName = this.name.replace('allow_', 'deny_');
                        var denyCb = document.querySelector('input[name="' + denyName + '"]');
                        if (denyCb) denyCb.checked = false;
                    } else {
                        // If unchecking allow, auto-check deny (every box must be one or the other)
                        var denyName = this.name.replace('allow_', 'deny_');
                        var denyCb = document.querySelector('input[name="' + denyName + '"]');
                        if (denyCb) denyCb.checked = true;
                    }
                });
            });
            document.querySelectorAll('input[name^="deny_"]').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    if (this.checked) {
                        var allowName = this.name.replace('deny_', 'allow_');
                        var allowCb = document.querySelector('input[name="' + allowName + '"]');
                        if (allowCb) allowCb.checked = false;
                    } else {
                        // If unchecking deny, auto-check allow
                        var allowName = this.name.replace('deny_', 'allow_');
                        var allowCb = document.querySelector('input[name="' + allowName + '"]');
                        if (allowCb) allowCb.checked = true;
                    }
                });
            });
        });
        </script>
        </div>
</div>
