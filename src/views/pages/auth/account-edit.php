<?php
// src/views/pages/auth/account-edit.php
// Admin-only page for editing a user account
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/escaper.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/document_sender.php';

// Require admin
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /?page=login');
    exit;
}

$csrf = csrf_token();
$userId = (int)($_GET['id'] ?? 0);

// Protect the built-in admin account
if ($userId === 1) {
    header('Location: /?page=accounts&error=' . urlencode('The default admin account cannot be edited here.'));
    exit;
}

$stmt = $pdo->prepare('SELECT id, email, username, role, is_disabled, force_password_reset, created_at,
    document_sender_enabled, document_sender_name, document_sender_company,
    document_sender_address_line1, document_sender_address_line2,
    document_sender_city, document_sender_state, document_sender_postal,
    document_sender_country, document_sender_phone, document_sender_email
    FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: /?page=accounts&error=' . urlencode('User not found'));
    exit;
}

// Get 2FA status
$twofaEnabled = false;
try {
    $st = $pdo->prepare('SELECT enabled FROM user_2fa WHERE user_id = ?');
    $st->execute([$userId]);
    $twofaEnabled = (bool)$st->fetchColumn();
} catch (Throwable $e) {}

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

$availableRoles = [];
$userAclRoleId = null;
try {
    $roleStmt = $pdo->query('SELECT id, name, description, is_system, organization_id FROM roles WHERE organization_id IS NULL OR is_system = 1 ORDER BY CASE name WHEN "member" THEN 0 WHEN "staff" THEN 1 WHEN "owner" THEN 2 WHEN "admin" THEN 3 ELSE 4 END, is_system DESC, name');
    $availableRoles = $roleStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    @error_log('[account-edit] role load failed: ' . $e->getMessage());
}

if (empty($availableRoles)) {
    $availableRoles = [
        ['id' => 0, 'name' => 'member', 'description' => 'Default user access.', 'is_system' => 1, 'organization_id' => null],
        ['id' => -1, 'name' => 'admin', 'description' => 'Full administrative access.', 'is_system' => 1, 'organization_id' => null],
    ];
}

if ($userAclRoleId === null) {
    $currentRoleName = in_array($user['role'], ['admin', 'owner', 'staff', 'member'], true) ? $user['role'] : 'member';
    foreach ($availableRoles as $roleRow) {
        if ($roleRow['name'] === $currentRoleName) {
            $userAclRoleId = (int)$roleRow['id'];
            break;
        }
    }
}

$targetRole = $user['role'];
foreach ($availableRoles as $roleRow) {
    if ((int)$roleRow['id'] === (int)$userAclRoleId) {
        $targetRole = (string)$roleRow['name'];
        break;
    }
}

require_once __DIR__ . '/../../../utils/permission_catalog.php';
$permissionGroupsEdit = permission_catalog();
$flatEditPerms = [];
foreach ($permissionGroupsEdit as $group => $permissions) {
    foreach ($permissions as $perm) {
        $flatEditPerms[$perm] = true;
    }
}

$roleDefaults = [];
$roleMeta = [];
foreach ($availableRoles as $roleRow) {
    $roleId = (int)$roleRow['id'];
    $roleName = (string)$roleRow['name'];
    $roleMeta[(string)$roleId] = [
        'name' => $roleName,
        'isAdmin' => $roleName === 'admin',
    ];
    $roleDefaults[(string)$roleId] = [];
    foreach ($flatEditPerms as $perm => $_) {
        $roleDefaults[(string)$roleId][$perm] = $roleName === 'admin';
    }
}

try {
    $ids = array_values(array_filter(array_map(static fn($r) => (int)$r['id'], $availableRoles), static fn($id) => $id > 0));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rpStmt = $pdo->prepare("SELECT role_id, permission, allowed FROM role_permissions WHERE role_id IN ($placeholders)");
        $rpStmt->execute($ids);
        $rawRolePerms = [];
        foreach ($rpStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rawRolePerms[(int)$row['role_id']][(string)$row['permission']] = (bool)$row['allowed'];
        }
        foreach ($availableRoles as $roleRow) {
            $roleId = (int)$roleRow['id'];
            $roleName = (string)$roleRow['name'];
            if ($roleName === 'admin') {
                continue;
            }
            foreach ($flatEditPerms as $perm => $_) {
                $module = explode('.', $perm, 2)[0] ?? $perm;
                $roleDefaults[(string)$roleId][$perm] =
                    $rawRolePerms[$roleId][$perm]
                    ?? $rawRolePerms[$roleId][$module . '.*']
                    ?? false;
            }
        }
    }
} catch (Throwable $e) {
    @error_log('[account-edit] role defaults load failed: ' . $e->getMessage());
}
?>
<section>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
    <h2 style="margin:0;">Edit User</h2>
    <a href="/?page=accounts" style="padding:8px 14px;border-radius:6px;border:1px solid #ddd;background:#fff;text-decoration:none;color:#374151;font-size:14px;">&larr; Back to Accounts</a>
  </div>

  <?php if ($success === '2fa_disabled'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">2FA has been disabled for this user.</div>
  <?php elseif ($success === 'pwd_reset'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Password reset successfully.</div>
  <?php elseif ($success === 'updated'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">User updated successfully.</div>
  <?php elseif (!empty($error)): ?>
    <div class="alert alert-danger"><?php echo e($error); ?></div>
  <?php endif; ?>

  <style>
    .pa-edit-layout { display: grid; gap: 24px; align-items: start; }
    .pa-edit-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; }
    .pa-edit-card h3 { margin: 0 0 16px 0; }
    .pa-edit-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .pa-edit-grid label { display: flex; flex-direction: column; gap: 4px; }
    .pa-edit-sender-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
    .pa-edit-sender-grid label { display: flex; flex-direction: column; gap: 4px; }
    .pa-edit-grid input,
    .pa-edit-grid select,
    .pa-edit-sender-grid input { width: 100%; box-sizing: border-box; }
    .pa-edit-actionbar { margin-top: 4px; }
    .pa-edit-secondary { display: grid; gap: 16px; grid-template-columns: repeat(3, 1fr); }
    #permissions-panel-edit.pa-hidden {
      opacity: 0;
      max-height: 0 !important;
      padding-top: 0 !important;
      padding-bottom: 0 !important;
      margin: 0 !important;
      border-width: 0 !important;
      pointer-events: none;
    }
    @media (max-width: 960px) {
      .pa-edit-secondary { grid-template-columns: 1fr; }
    }
    @media (max-width: 720px) {
      .pa-edit-grid { grid-template-columns: 1fr; }
      .pa-edit-sender-grid { grid-template-columns: 1fr; }
    }
  </style>

  <div class="pa-edit-layout">
    <!-- Account Details -->
    <div class="pa-edit-card">
      <h3>Account Details</h3>
      <form method="post" action="/?page=accounts-update">
        <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">

        <div class="pa-edit-grid">
          <label>
            <span style="font-weight:600">Email *</span>
            <input required type="email" name="email" value="<?php echo e($user['email']); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;">
          </label>

          <label>
            <span style="font-weight:600">Username</span>
            <input type="text" name="username" value="<?php echo e($user['username'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;">
          </label>

          <label>
            <span style="font-weight:600">Role *</span>
            <select required name="role_id" id="account-role-select" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;">
              <?php foreach ($availableRoles as $roleRow): ?>
                <?php
                  $roleId = (int)$roleRow['id'];
                  $roleName = (string)$roleRow['name'];
                  $roleLabel = ucwords(str_replace('_', ' ', $roleName));
                  $roleScope = (int)($roleRow['is_system'] ?? 0) === 1 ? 'System' : 'Custom';
                ?>
                <option value="<?php echo $roleId; ?>" <?php echo $roleId === (int)$userAclRoleId ? 'selected' : ''; ?>>
                  <?php echo e($roleLabel . ' (' . $roleScope . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:16px;">
          <input type="checkbox" name="is_disabled" value="1" <?php echo ($user['is_disabled'] ?? 0) ? 'checked' : ''; ?>
          <span>Disable account (prevents login)</span>
        </label>

        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:8px;">
          <input type="checkbox" name="force_reset" value="1" <?php echo ($user['force_password_reset'] ?? 0) ? 'checked' : ''; ?>
          <span>Force password change on next login</span>
        </label>

        <div style="margin-top:20px;padding-top:18px;border-top:1px solid #e5e7eb;">
          <h3 style="margin:0 0 10px 0;font-size:16px;">Document Sender Info</h3>
          <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;margin-bottom:14px;">
            <input type="checkbox" name="document_sender_enabled" id="document-sender-enabled" value="1" <?php echo !empty($user['document_sender_enabled']) ? 'checked' : ''; ?> style="margin-top:3px;">
            <span>
              <span style="display:block;font-weight:600;">Use this user's info on documents they create</span>
              <span style="display:block;color:#6b7280;font-size:13px;">When off, quotes, contracts, and invoices use the system default business info from Settings.</span>
            </span>
          </label>

          <div id="document-sender-fields" class="pa-edit-sender-grid">
            <label>
              <span style="font-weight:600">Name</span>
              <input type="text" name="document_sender_name" value="<?php echo e($user['document_sender_name'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">Company</span>
              <input type="text" name="document_sender_company" value="<?php echo e($user['document_sender_company'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">Phone</span>
              <input type="text" name="document_sender_phone" value="<?php echo e($user['document_sender_phone'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">Email</span>
              <input type="email" name="document_sender_email" value="<?php echo e($user['document_sender_email'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">Address Line 1</span>
              <input type="text" name="document_sender_address_line1" value="<?php echo e($user['document_sender_address_line1'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">Address Line 2</span>
              <input type="text" name="document_sender_address_line2" value="<?php echo e($user['document_sender_address_line2'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">City</span>
              <input type="text" name="document_sender_city" value="<?php echo e($user['document_sender_city'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">State</span>
              <input type="text" name="document_sender_state" value="<?php echo e($user['document_sender_state'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">Postal Code</span>
              <input type="text" name="document_sender_postal" value="<?php echo e($user['document_sender_postal'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
            <label>
              <span style="font-weight:600">Country</span>
              <input type="text" name="document_sender_country" value="<?php echo e($user['document_sender_country'] ?? ''); ?>" style="padding:10px;border-radius:8px;border:1px solid #ddd;">
            </label>
          </div>
        </div>

        <div class="pa-edit-actionbar">
          <button type="submit" style="padding:10px 16px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer;">Save Changes</button>
        </div>
      </form>
    </div>

    <!-- Permissions (full-width card) -->
    <?php include __DIR__ . '/../account/permissions_overrides.php'; ?>
    <script>
      window.PA_EDIT_ROLE_DEFAULTS = <?php echo json_encode($roleDefaults, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
      window.PA_EDIT_ROLE_META = <?php echo json_encode($roleMeta, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

      document.addEventListener('DOMContentLoaded', function() {
        var roleSelect = document.getElementById('account-role-select');
        var panel = document.getElementById('permissions-panel-edit');
        var grid = document.getElementById('permissions-grid-edit');
        var adminNote = document.getElementById('admin-permissions-note-edit');
        var senderToggle = document.getElementById('document-sender-enabled');
        var senderFields = document.getElementById('document-sender-fields');

        function selectedRoleMeta() {
          if (!roleSelect || !window.PA_EDIT_ROLE_META) return {};
          return window.PA_EDIT_ROLE_META[roleSelect.value] || {};
        }

        function selectedRoleDefaults() {
          if (roleSelect && window.PA_EDIT_ROLE_DEFAULTS && window.PA_EDIT_ROLE_DEFAULTS[roleSelect.value]) {
            return window.PA_EDIT_ROLE_DEFAULTS[roleSelect.value];
          }
          return {};
        }

        function applyRoleDefaults() {
          var defaults = selectedRoleDefaults();
          Object.keys(defaults).forEach(function(perm) {
            var key = perm.replace(/\./g, '_');
            var allowCb = document.querySelector('#permissions-panel-edit input[name="allow_' + key + '"]');
            var denyCb = document.querySelector('#permissions-panel-edit input[name="deny_' + key + '"]');
            if (allowCb && denyCb) {
              var allowed = !!defaults[perm];
              allowCb.checked = allowed;
              denyCb.checked = !allowed;
            }
          });
        }

        function updatePermissionsForRole() {
          if (!roleSelect || !panel) return;
          var meta = selectedRoleMeta();
          if (meta.isAdmin) {
            panel.classList.add('pa-hidden');
            if (adminNote) adminNote.style.display = 'block';
            if (grid) grid.style.display = 'none';
          } else {
            panel.classList.remove('pa-hidden');
            if (adminNote) adminNote.style.display = 'none';
            if (grid) grid.style.display = 'block';
            applyRoleDefaults();
          }
        }

        if (roleSelect) {
          roleSelect.addEventListener('change', updatePermissionsForRole);
          updatePermissionsForRole();
        }

        function updateSenderFields() {
          if (!senderToggle || !senderFields) return;
          senderFields.style.opacity = senderToggle.checked ? '1' : '0.45';
          senderFields.querySelectorAll('input').forEach(function(input) {
            input.readOnly = !senderToggle.checked;
          });
        }

        if (senderToggle) {
          senderToggle.addEventListener('change', updateSenderFields);
          updateSenderFields();
        }
      });
    </script>

    <!-- Secondary actions: 2FA, Reset Password, Danger Zone -->
    <div class="pa-edit-secondary">
      <div class="pa-edit-card">
        <h3>Two-Factor Authentication</h3>
        <p style="margin:0 0 12px;">Status:
          <span style="padding:4px 10px;border-radius:4px;font-size:13px;font-weight:600;<?php echo $twofaEnabled ? 'background:#d1fae5;color:#065f46' : 'background:#f3f4f6;color:#374151'; ?>">
            <?php echo $twofaEnabled ? 'Enabled' : 'Not enabled'; ?>
          </span>
        </p>
        <?php if ($twofaEnabled): ?>
          <form method="post" action="/?page=2fa-admin-disable" onsubmit="return confirm('This will remove the authenticator requirement for this user. Are you sure?')" style="margin-top:8px;">
            <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
            <button type="submit" style="padding:8px 14px;border-radius:6px;border:1px solid #dc2626;background:#fee2e2;color:#dc2626;font-size:14px;cursor:pointer;font-weight:600;">Disable 2FA for this user</button>
          </form>
        <?php else: ?>
          <?php if ($userId === (int)($_SESSION['user']['id'] ?? 0)): ?>
            <p style="color:#6b7280;font-size:13px;margin:0 0 12px;">Two-factor authentication is not enabled for your account. Set it up from My Account.</p>
            <a href="/?page=2fa-setup" class="btn btn-sm btn-primary">Set Up 2FA</a>
          <?php else: ?>
            <p style="color:#6b7280;font-size:13px;margin:0;">The user has not set up two-factor authentication. They can enable it from their own Account page.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="pa-edit-card">
        <h3>Reset Password</h3>
        <form method="post" action="/?page=accounts-reset-password" style="display:grid;gap:12px;margin-top:12px;">
          <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
          <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
          <label>
            <span style="font-weight:600">New Password</span>
            <input required minlength="8" type="password" name="new_password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;" placeholder="Min 8 characters">
          </label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" name="force_reset" value="1">
            <span>Force password change on next login</span>
          </label>
          <button type="submit" style="padding:8px 14px;border-radius:6px;border:1px solid #dc2626;background:#fee2e2;color:#dc2626;font-size:14px;cursor:pointer;font-weight:600;">Reset Password</button>
        </form>
      </div>

      <div class="pa-edit-card" style="border-color:#fca5a5;">
        <h3 style="color:#991b1b">Danger Zone</h3>
        <p style="color:#6b7280;font-size:14px;margin:8px 0 16px;">Permanently delete this user account. Quotes, invoices, contracts, and other business records will remain in the system and are not affected.</p>
        <form method="post" action="/?page=accounts-delete" onsubmit="return confirm('Are you sure you want to permanently delete this user? This cannot be undone. Related business records will NOT be deleted.')">
          <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
          <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
          <button type="submit" style="padding:10px 16px;border-radius:8px;border:1px solid #dc2626;background:#fee2e2;color:#dc2626;font-weight:600;cursor:pointer;">Delete User Account</button>
        </form>
      </div>
    </div>
  </div>
</section>
