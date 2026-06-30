<?php
// src/views/pages/auth/account.php
// Regular user self-service account page
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/db.php';

$isForceReset = !empty($_GET['force']);
$uid = (int)($_SESSION['user']['id'] ?? 0);

// Fetch current user details
$myEmail = $_SESSION['user']['email'] ?? '';
$myRole = $_SESSION['user']['role'] ?? 'user';

// Get 2FA status
$twofaEnabled = false;
try {
    $st = $pdo->prepare('SELECT enabled FROM user_2fa WHERE user_id = ?');
    $st->execute([$uid]);
    $twofaEnabled = (bool)$st->fetchColumn();
} catch (Throwable $e) {}

// Get active trusted devices
$devices = [];
try {
    $st = $pdo->prepare('SELECT id, device_name, ip_address, last_verified_at, expires_at FROM trusted_devices WHERE user_id = ? AND expires_at > NOW() ORDER BY last_verified_at DESC');
    $st->execute([$uid]);
    $devices = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $devices = [];
}
?>
<section>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
    <h2 style="margin:0;">My Account</h2>
    <span style="padding:4px 10px;border-radius:4px;font-size:13px;font-weight:600;background:#dbeafe;color:#1e40af;"><?php echo htmlspecialchars(ucfirst($myRole)); ?></span>
  </div>

  <?php if ($myRole === 'admin'): ?>
    <div class="alert alert-info">Use this page to manage your own password and two-factor authentication. Use Accounts to manage other users.</div>
  <?php endif; ?>

  <?php if (!empty($_GET['pwd']) && $_GET['pwd']==='1'): ?>
    <div class="alert alert-success">Password updated.</div>
  <?php elseif (!empty($_GET['pwd_error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['pwd_error']); ?></div>
  <?php elseif ($isForceReset): ?>
    <div style="margin:10px 0;padding:16px 20px;border-radius:8px;background:#fef3c7;color:#92400e;border:2px solid #f59e0b;font-weight:600;font-size:16px">
      You must change your password before you can use the application.
    </div>
  <?php endif; ?>

  <div style="display:grid;gap:24px;grid-template-columns:1fr 1fr;align-items:start;">
    <!-- Left: Password change -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">
      <h3 style="margin-top:0">Change Password</h3>
      <p style="margin:0 0 12px;color:#6b7280;font-size:13px;">Update your password. You must enter your current password.</p>
      <form method="post" action="/?page=account-update" style="display:grid;gap:12px;">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf'] ?? ''); ?>">
        <label>
          <div style="margin-bottom:4px;font-weight:600">Current Password</div>
          <input required type="password" name="current_password" autocomplete="current-password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;" <?php echo $isForceReset ? 'autofocus' : ''; ?>>
        </label>
        <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr">
          <label>
            <div style="margin-bottom:4px;font-weight:600">New Password</div>
            <input required minlength="8" type="password" name="new_password" autocomplete="new-password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;">
          </label>
          <label>
            <div style="margin-bottom:4px;font-weight:600">Confirm New Password</div>
            <input required minlength="8" type="password" name="confirm_password" autocomplete="new-password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;">
          </label>
        </div>
        <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer;">Update Password</button>
      </form>
    </div>

    <!-- Right: 2FA + Info -->
    <div style="display:grid;gap:16px;">
      <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">
        <h3 style="margin-top:0">Two-Factor Authentication</h3>
        <p style="margin:0 0 12px;">Status:
          <span style="padding:4px 10px;border-radius:4px;font-size:13px;font-weight:600;<?php echo $twofaEnabled ? 'background:#d1fae5;color:#065f46' : 'background:#f3f4f6;color:#374151'; ?>">
            <?php echo $twofaEnabled ? 'Enabled' : 'Not enabled'; ?>
          </span>
        </p>
        <p style="color:#6b7280;font-size:13px;margin:0 0 12px;">Add an extra layer of security by requiring a code from your authenticator app.</p>
        <a href="/?page=2fa-setup" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;text-decoration:none;font-weight:600;display:inline-block;">
          <?php echo $twofaEnabled ? 'Manage 2FA' : 'Enable 2FA'; ?>
        </a>
      </div>

      <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">
        <h3 style="margin-top:0">Account Info</h3>
        <p style="margin:4px 0;color:#6b7280;font-size:14px;">Email: <strong style="color:#111;"><?php echo htmlspecialchars($myEmail); ?></strong></p>
        <p style="margin:4px 0;color:#6b7280;font-size:14px;">Role: <strong style="color:#111;"><?php echo htmlspecialchars(ucfirst($myRole)); ?></strong></p>
      </div>

      <?php if (!empty($devices)): ?>
      <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">
        <h3 style="margin-top:0">Trusted Devices</h3>
        <p style="margin:0 0 12px;color:#6b7280;font-size:13px;">These devices can skip 2FA for this account. Revoke any you do not recognize.</p>
        <div style="display:grid;gap:10px;">
          <?php foreach ($devices as $d): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px;border:1px solid #e5e7eb;border-radius:6px;">
              <div>
                <div style="font-weight:600;font-size:14px;"><?php echo htmlspecialchars((string)($d['device_name'] ?? 'Unknown device'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div style="font-size:12px;color:#6b7280;">IP: <?php echo htmlspecialchars((string)($d['ip_address'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?> · Last verified: <?php echo htmlspecialchars((string)($d['last_verified_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
              </div>
              <form method="post" action="/?page=account-revoke-device" style="margin:0;">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="device_id" value="<?php echo (int)$d['id']; ?>">
                <button type="submit" style="padding:6px 12px;border-radius:6px;border:0;background:#dc2626;color:#fff;font-weight:600;cursor:pointer;font-size:13px;">Revoke</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>
