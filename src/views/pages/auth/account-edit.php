<?php
// src/views/pages/auth/account-edit.php
// Admin-only page for editing a user account
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf.php';

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

$stmt = $pdo->prepare('SELECT id, email, username, role, is_disabled, force_password_reset, created_at FROM users WHERE id = ?');
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
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <div style="display:grid;gap:24px;grid-template-columns:1fr 1fr;align-items:start;">
    <!-- Left: Account Details -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">
      <h3 style="margin-top:0">Account Details</h3>
      <form method="post" action="/?page=accounts-update" style="display:grid;gap:12px;">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="user_id" value="<?php echo $userId; ?>">

        <label>
          <div style="margin-bottom:4px;font-weight:600">Email *</div>
          <input required type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;">
        </label>

        <label>
          <div style="margin-bottom:4px;font-weight:600">Username</div>
          <input type="text" name="username" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;">
        </label>

        <label>
          <div style="margin-bottom:4px;font-weight:600">Role *</div>
          <select required name="role" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;">
            <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>User</option>
            <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
          </select>
        </label>

        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
          <input type="checkbox" name="is_disabled" value="1" <?php echo ($user['is_disabled'] ?? 0) ? 'checked' : ''; ?>>
          <span>Disable account (prevents login)</span>
        </label>

        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
          <input type="checkbox" name="force_reset" value="1" <?php echo ($user['force_password_reset'] ?? 0) ? 'checked' : ''; ?>>
          <span>Force password change on next login</span>
        </label>

        <div style="margin-top:4px;">
          <button type="submit" style="padding:10px 16px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer;">Save Changes</button>
        </div>
      </form>
    </div>

    <!-- Right: 2FA + Security -->
    <div style="display:grid;gap:16px;">
      <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">
        <h3 style="margin-top:0">Two-Factor Authentication</h3>
        <p style="margin:0 0 12px;">Status:
          <span style="padding:4px 10px;border-radius:4px;font-size:13px;font-weight:600;<?php echo $twofaEnabled ? 'background:#d1fae5;color:#065f46' : 'background:#f3f4f6;color:#374151'; ?>">
            <?php echo $twofaEnabled ? 'Enabled' : 'Not enabled'; ?>
          </span>
        </p>
        <?php if ($twofaEnabled): ?>
          <form method="post" action="/?page=2fa-admin-disable" onsubmit="return confirm('This will remove the authenticator requirement for this user. Are you sure?')" style="margin-top:8px;">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
            <button type="submit" style="padding:8px 14px;border-radius:6px;border:1px solid #dc2626;background:#fee2e2;color:#dc2626;font-size:14px;cursor:pointer;font-weight:600;">Disable 2FA for this user</button>
          </form>
        <?php else: ?>
          <p style="color:#6b7280;font-size:13px;margin:0;">The user has not set up two-factor authentication. They can enable it from their own Account page.</p>
        <?php endif; ?>
      </div>

      <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">
        <h3 style="margin-top:0">Reset Password</h3>
        <form method="post" action="/?page=accounts-reset-password" style="display:grid;gap:12px;margin-top:12px;">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
          <label>
            <div style="margin-bottom:4px;font-weight:600">New Password</div>
            <input required minlength="8" type="password" name="new_password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;" placeholder="Min 8 characters">
          </label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" name="force_reset" value="1">
            <span>Force password change on next login</span>
          </label>
          <button type="submit" style="padding:8px 14px;border-radius:6px;border:1px solid #dc2626;background:#fee2e2;color:#dc2626;font-size:14px;cursor:pointer;font-weight:600;">Reset Password</button>
        </form>
      </div>

      <div style="background:#fff;border:1px solid #fca5a5;border-radius:8px;padding:20px;">
        <h3 style="margin-top:0;color:#991b1b">Danger Zone</h3>
        <p style="color:#6b7280;font-size:14px;margin:8px 0 16px;">Permanently delete this user account. Quotes, invoices, contracts, and other business records will remain in the system and are not affected.</p>
        <form method="post" action="/?page=accounts-delete" onsubmit="return confirm('Are you sure you want to permanently delete this user? This cannot be undone. Related business records will NOT be deleted.')">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
          <button type="submit" style="padding:10px 16px;border-radius:8px;border:1px solid #dc2626;background:#fee2e2;color:#dc2626;font-weight:600;cursor:pointer;">Delete User Account</button>
        </form>
      </div>
    </div>
  </div>
</section>
