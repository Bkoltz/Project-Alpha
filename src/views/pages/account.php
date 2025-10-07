<?php
// src/views/pages/account.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
// Auth is enforced by the main router; avoid duplicate/false Unauthorized here.
require_once __DIR__ . '/../../config/app.php';
?>
<section>
  <h2>Account</h2>
  <?php if (!empty($_GET['pwd']) && $_GET['pwd']==='1'): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Password updated.</div>
  <?php elseif (!empty($_GET['pwd_error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars($_GET['pwd_error']); ?></div>
  <?php endif; ?>

  <form method="post" action="/?page=account-update" style="display:grid;gap:16px;max-width:600px">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf'] ?? ''); ?>">
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
      <legend style="padding:0 6px;color:var(--muted)">Change Password</legend>
      <p style="margin:0 0 8px;color:var(--muted);font-size:12px">Update your password. You must enter your current password.</p>
      <label>
        <div>Current Password</div>
        <input required type="password" name="current_password" autocomplete="current-password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr">
        <label>
          <div>New Password</div>
          <input required minlength="8" type="password" name="new_password" autocomplete="new-password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <label>
          <div>Confirm New Password</div>
          <input required minlength="8" type="password" name="confirm_password" autocomplete="new-password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
      </div>
      <div>
        <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Update Password</button>
      </div>
    </fieldset>
  </form>
</section>
