<?php
// src/views/pages/reset-verify.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/app.php';

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];
$email = isset($_GET['email']) ? (string)$_GET['email'] : '';
$prefillToken = isset($_GET['token']) ? (string)$_GET['token'] : '';
$error = isset($_GET['error']) ? (string)$_GET['error'] : '';
?>
<main>
  <div class="auth-wrap">
    <h1 style="margin:0 0 12px">Enter reset code</h1>
    <?php if ($error): ?>
      <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" action="/?page=reset-verify" style="display:grid;gap:12px">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
      <label>
        <div>Email</div>
        <input required type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" autocomplete="username" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Reset code</div>
        <input required type="text" name="token" value="<?php echo htmlspecialchars($prefillToken); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Paste the code you received">
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
    </form>
  </div>
</main>
