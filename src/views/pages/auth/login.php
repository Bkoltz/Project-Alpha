<?php
// src/views/pages/auth/login.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/password_reset_tokens.php';

// CSRF token (Symfony-backed)
$csrf = csrf_sf_token('auth');

// Determine if first-run (no users)
$noUsers = false;
try {
  $noUsers = ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()) === 0;
} catch (Throwable $e) { $noUsers = true; }

$error = isset($_GET['error']) ? (string)$_GET['error'] : '';
$resetAvailable = password_reset_email_is_configured($appConfig);
?>

<style>
  body { background: #0b1220 url('/assets/IMG_0342.JPG') no-repeat right bottom fixed; background-size: cover; }
  .photo-credit { position: fixed; right: 10px; bottom: 10px; color: rgba(255,255,255,0.9); font-size: 12px; background: rgba(0,0,0,0.35); padding: 4px 8px; border-radius: 6px; }
</style>
<main>
  <div class="auth-wrap">
    <h1 style="margin:0 0 12px"><?php echo $noUsers ? 'Set up admin user' : 'Sign in'; ?></h1>
<?php if ($error): ?>
      <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if (!$noUsers && !empty($_GET['created'])): ?>
      <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Account created. Please sign in.</div>
    <?php endif; ?>
    <?php if (!$noUsers && !empty($_GET['pwd_reset'])): ?>
      <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Password updated. Please sign in.</div>
    <?php endif; ?>

    <form method="post" action="/?page=auth" style="display:grid;gap:12px">
      <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrf); ?>">
      <input type="hidden" name="action" value="<?php echo $noUsers ? 'register_first' : 'login'; ?>">
      <label>
        <div><?php echo $noUsers ? 'Administrator Email' : 'Email or Username'; ?></div>
        <input required type="<?php echo $noUsers ? 'email' : 'text'; ?>" name="email" autocomplete="username" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <?php if ($noUsers): ?>
        <label>
          <div>Administrator Username</div>
          <input required minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]+" type="text" name="username" autocomplete="username" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
      <?php endif; ?>
      <label>
        <div>Password</div>
        <input required minlength="8" type="password" name="password" autocomplete="<?php echo $noUsers ? 'new-password' : 'current-password'; ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <?php if ($noUsers): ?>
        <label>
          <div>Confirm Password</div>
          <input required minlength="8" type="password" name="password2" autocomplete="new-password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <div style="color:var(--muted);font-size:12px">This creates PA's first normal administrator. No default or Compose-managed login exists.</div>
      <?php endif; ?>
      <div style="display:flex;gap:8px;align-items:center;justify-content:space-between">
        <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">
          <?php echo $noUsers ? 'Create Admin' : 'Sign In'; ?>
        </button>
        <?php if (!$noUsers && $resetAvailable): ?>
          <a href="/?page=reset-password" style="font-size:13px;color:#0369a1;text-decoration:underline">Forgot your password?</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
  <div class="photo-credit">Photo: Ledge Top Drone Services</div>
</main>

</body>
</html>
