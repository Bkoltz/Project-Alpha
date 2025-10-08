<?php
// src/views/pages/login.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';

// CSRF token
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

// Determine if first-run (no users)
$noUsers = false;
try {
  $noUsers = ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()) === 0;
} catch (Throwable $e) { $noUsers = true; }

$error = isset($_GET['error']) ? (string)$_GET['error'] : '';
?>

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
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
      <input type="hidden" name="action" value="<?php echo $noUsers ? 'register_first' : 'login'; ?>">
      <label>
        <div>Email</div>
        <input required type="email" name="email" autocomplete="username" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <label>
        <div>Password</div>
        <input required minlength="8" type="password" name="password" autocomplete="<?php echo $noUsers ? 'new-password' : 'current-password'; ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      </label>
      <?php if ($noUsers): ?>
        <label>
          <div>Confirm Password</div>
          <input required minlength="8" type="password" name="password2" autocomplete="new-password" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        </label>
        <div style="color:var(--muted);font-size:12px">The first account will be created as an admin.</div>
      <?php else: ?>
        <!-- Remember me temporarily disabled -->
      <?php endif; ?>
      <div style="display:flex;gap:8px;align-items:center;justify-content:space-between">
        <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">
          <?php echo $noUsers ? 'Create Admin' : 'Sign In'; ?>
        </button>
        <?php if (!$noUsers): ?>
          <a href="/?page=reset-password" style="font-size:13px;color:#0369a1;text-decoration:underline">Forgot your password?</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</main>

</body>
</html>
