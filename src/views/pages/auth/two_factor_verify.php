<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (!isset($_SESSION['2fa_pending'])) {
    header('Location: /?page=login');
    exit;
}
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
$csrf = csrf_sf_token('2fa_verify');
$error = isset($_GET['error']) ? (string)$_GET['error'] : '';
?>
<style>
  body { background:#0b1220 url('/assets/IMG_0342.JPG') no-repeat right bottom fixed; background-size:cover; }
  .photo-credit { position:fixed; right:10px; bottom:10px; color:rgba(255,255,255,.9); font-size:12px; background:rgba(0,0,0,.35); padding:4px 8px; border-radius:6px; }
  .code-input { font-size:24px; letter-spacing:8px; text-align:center; font-family:monospace; }
</style>
<main>
  <div class="auth-wrap">
    <h1 style="margin:0 0 12px">Authenticator Verification</h1>
    <p style="color:var(--muted);margin:0 0 20px">Enter the six-digit code from your authenticator app.</p>
    <?php if ($error): ?><div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <form method="post" action="/?page=2fa-verify-action" style="display:grid;gap:12px">
      <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="verify">
      <label><div>Verification code</div><input required type="text" name="code" autocomplete="one-time-code" inputmode="numeric" class="code-input" pattern="[0-9]{6}" maxlength="6" placeholder="000000" style="width:100%;padding:14px;border-radius:8px;border:1px solid #ddd" autofocus></label>
      <div style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="remember_device" id="remember_device" value="1" style="width:18px;height:18px;cursor:pointer"><label for="remember_device" style="margin:0;font-size:14px;cursor:pointer;color:var(--muted)">Remember this device for 30 days</label></div>
      <div style="display:flex;gap:8px;flex-direction:column">
        <button type="submit" style="padding:12px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Verify</button>
        <a href="/?page=login" style="text-align:center;padding:8px;color:#0369a1;text-decoration:underline;font-size:14px">Use a passkey instead</a>
        <a href="/?page=logout" style="text-align:center;padding:8px;color:#dc2626;text-decoration:underline;font-size:14px">Cancel and log out</a>
      </div>
    </form>
  </div>
  <div class="photo-credit">Photo: Ledge Top Drone Services</div>
</main>
</body>
</html>
