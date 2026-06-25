<?php
// src/views/pages/account/permissions_overrides.php
// Partial: per-user permission overrides on account-edit page.
// Expects: $userId (int), $pdo (PDO), $csrf (string), get_active_org_id() available.

require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/escaper.php';

$activeOrgId = get_active_org_id();

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

// Load existing overrides for this user / org
$overrides = [];
try {
    $orgId = $activeOrgId > 0 ? $activeOrgId : null;
    $stmt = $pdo->prepare('SELECT permission, allowed FROM user_permissions_overrides WHERE user_id = ? AND organization_id <=> ?');
    $stmt->execute([(int)$userId, $orgId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $overrides[$row['permission']] = (int)$row['allowed'];
    }
} catch (Throwable $e) {
    @error_log('[permissions_overrides] load failed: ' . $e->getMessage());
}

function permLabel(string $perm): string {
    [$module, $action] = explode('.', $perm, 2);
    return ucfirst(str_replace('_', ' ', $action));
}
?>

<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:24px">
    <h3 style="margin:0 0 8px 0">Permission Overrides</h3>
    <p style="margin:0 0 16px 0;color:#6b7280;font-size:14px">Per-user overrides take precedence over role permissions. Leave both boxes unchecked to inherit from the assigned role.</p>

    <?php if ($activeOrgId <= 0): ?>
        <div style="padding:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;color:#92400e;font-size:13px;margin-bottom:16px">
            ⚠️ No active organization selected. Select an organization to manage overrides for that org.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
        <div style="margin:0 0 16px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Overrides saved.</div>
    <?php endif; ?>

    <form method="post" action="/?page=settings/permissions-handler">
        <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="action" value="save_user_overrides">
        <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
        <input type="hidden" name="referer" value="account-edit">

        <div style="display:grid;gap:16px">
            <?php foreach ($permissionGroups as $group => $permissions): ?>
                <fieldset style="border:1px solid #eee;border-radius:8px;padding:14px">
                    <legend style="padding:0 8px;font-weight:600"><?php echo e($group); ?></legend>

                    <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr 2fr">
                        <div style="font-size:12px;font-weight:600;color:#6b7280">Allow</div>
                        <div style="font-size:12px;font-weight:600;color:#6b7280">Deny</div>
                        <div style="font-size:12px;font-weight:600;color:#6b7280">Permission</div>

                        <?php foreach ($permissions as $perm): ?>
                            <?php
                            $allowChecked = isset($overrides[$perm]) && $overrides[$perm] === 1 ? 'checked' : '';
                            $denyChecked  = isset($overrides[$perm]) && $overrides[$perm] === 0 ? 'checked' : '';
                            $allowKey = 'allow_' . str_replace('.', '_', $perm);
                            $denyKey  = 'deny_' . str_replace('.', '_', $perm);
                            ?>
                            <label style="display:flex;align-items:center;justify-content:center;cursor:pointer">
                                <input type="checkbox" name="<?php echo e($allowKey); ?>" value="1" <?php echo $allowChecked; ?> aria-label="Allow <?php echo e($perm); ?>">
                            </label>
                            <label style="display:flex;align-items:center;justify-content:center;cursor:pointer">
                                <input type="checkbox" name="<?php echo e($denyKey); ?>" value="1" <?php echo $denyChecked; ?> aria-label="Deny <?php echo e($perm); ?>">
                            </label>
                            <div style="font-size:13px"><?php echo e(permLabel($perm)); ?> <span style="color:#9ca3af;font-size:12px">(<?php echo e($perm); ?>)</span></div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:20px">
            <button type="submit" style="padding:10px 20px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">Save Overrides</button>
        </div>
    </form>
</div>
