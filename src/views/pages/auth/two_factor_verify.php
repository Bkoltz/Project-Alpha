<?php
// src/views/pages/auth/two_factor_verify.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Check if user is in 2FA pending state
if (!isset($_SESSION['2fa_pending'])) {
    header('Location: /?page=login');
    exit;
}

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';

$csrf = csrf_sf_token('2fa_verify');
$error = isset($_GET['error']) ? (string)$_GET['error'] : '';
$showBackup = isset($_GET['backup']);
?>

<style>
  body { background: #0b1220 url('/assets/IMG_0342.JPG') no-repeat right bottom fixed; background-size: cover; }
  .photo-credit { position: fixed; right: 10px; bottom: 10px; color: rgba(255,255,255,0.9); font-size: 12px; background: rgba(0,0,0,0.35); padding: 4px 8px; border-radius: 6px; }
  .code-input { font-size: 24px; letter-spacing: 8px; text-align: center; font-family: monospace; }
</style>

<main>
  <div class="auth-wrap">
    <h1 style="margin:0 0 12px">Two-Factor Authentication</h1>
    <p style="color: var(--muted); margin: 0 0 20px 0;">
      <?php echo $showBackup ? 'Enter one of your backup codes' : 'Enter the 6-digit code from your authenticator app'; ?>
    </p>

    <?php if ($error): ?>
      <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">
        <?php echo htmlspecialchars($error); ?>
      </div>
    <?php endif; ?>

    <form method="post" action="/?page=2fa-verify-action" style="display:grid;gap:12px">
      <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrf); ?>">
      <input type="hidden" name="action" value="verify">
      <?php if ($showBackup): ?>
        <input type="hidden" name="use_backup" value="1">
      <?php endif; ?>
      
      <label>
        <div><?php echo $showBackup ? 'Backup Code' : 'Verification Code'; ?></div>
        <input 
          required 
          type="text" 
          name="code" 
          autocomplete="one-time-code" 
          class="code-input"
          <?php echo $showBackup ? 'maxlength="9" placeholder="XXXX-XXXX"' : 'pattern="[0-9]{6}" maxlength="6" placeholder="000000"'; ?>
          style="width:100%;padding:14px;border-radius:8px;border:1px solid #ddd"
          autofocus
        >
      </label>

      <div style="display:flex;gap:8px;flex-direction:column">
        <button type="submit" style="padding:12px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">
          Verify
        </button>
        
        <?php if (!$showBackup): ?>
          <a href="/?page=2fa-verify&backup=1" style="text-align:center;padding:8px;color:#0369a1;text-decoration:underline;font-size:14px">
            Use a backup code instead
          </a>
        <?php else: ?>
          <a href="/?page=2fa-verify" style="text-align:center;padding:8px;color:#0369a1;text-decoration:underline;font-size:14px">
            Use authenticator code instead
          </a>
        <?php endif; ?>
        
        <a href="/?page=logout" style="text-align:center;padding:8px;color:#dc2626;text-decoration:underline;font-size:14px">
          Cancel and logout
        </a>
      </div>
    </form>
  </div>
  <div class="photo-credit">Photo: Ledge Top Drone Services</div>
</main>

</body>
</html>
