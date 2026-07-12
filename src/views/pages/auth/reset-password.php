<?php
// src/views/pages/auth/reset-password.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/password_reset_tokens.php';

$csrf = csrf_sf_token('reset_request');
$notice = isset($_GET['sent']) && $_GET['sent']==='1';
$error = isset($_GET['error']) ? (string)$_GET['error'] : '';
$resetAvailable = password_reset_email_is_configured($appConfig);
?>
<main>
  <div class="auth-wrap">
    <h1 style="margin:0 0 12px">Reset your password</h1>
    <?php if ($notice): ?>
      <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">
        If an account with that email exists, we sent a reset code.
      </div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (!$resetAvailable): ?>
      <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fffbeb;color:#78350f;border:1px solid #fbbf24">Password reset email is not configured. Contact an administrator or use the Docker recovery command.</div>
    <?php else: ?>
    <form method="post" action="/?page=reset-request" style="display:grid;gap:12px">
      <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrf); ?>">
      <label>
        <div>Email</div>
        <input required type="email" name="email" autocomplete="username" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <div>
        <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Send reset code</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</main>
