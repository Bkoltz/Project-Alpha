<?php
// src/views/pages/reset-new.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';

$csrf = csrf_sf_token('reset_update');
$email = isset($_GET['email']) ? (string)$_GET['email'] : '';
?>
<main>
  <div class="auth-wrap">
    <h1 style="margin:0 0 12px">Set a new password</h1>
<form method="post" action="/?page=reset-update" style="display:grid;gap:12px">
      <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrf); ?>">
      <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
      <?php if (empty($_SESSION['reset_user_id'])): ?>
      <label>
        <div>Reset code</div>
        <input type="text" name="token" placeholder="6-digit code" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <div style="color:#6b7280;font-size:12px;margin-top:4px">Paste the code from your email (optional if you already verified)</div>
      </label>
      <?php endif; ?>
      <label>
        <div>New Password</div>
        <input required minlength="8" type="password" name="new_password" autocomplete="new-password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Confirm New Password</div>
        <input required minlength="8" type="password" name="confirm_password" autocomplete="new-password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <div>
        <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Update Password</button>
      </div>
    </form>
  </div>
</main>