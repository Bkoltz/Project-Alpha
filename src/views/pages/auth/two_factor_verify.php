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
$brandName = (string)($appConfig['brand_name'] ?? 'Project Alpha');
$brandLogo = trim((string)($appConfig['logo_path'] ?? '')) ?: '/assets/auth-logo.png';
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="<?php echo $h(asset_url('/assets/auth-login.css')); ?>">

<main class="login-page">
  <div class="login-shell">
    <section class="login-story" aria-label="<?php echo $h($brandName); ?>">
      <div class="login-brand">
        <img src="<?php echo $h($brandLogo); ?>" alt="" loading="eager" fetchpriority="high">
        <span><?php echo $h($brandName); ?></span>
      </div>
      <div class="login-story__copy">
        <p class="login-kicker">Business operations, together</p>
        <h1>One place to run the work.</h1>
        <p>Clients, projects, billing, workforce time, and approvals in the self-hosted system your team controls.</p>
      </div>
      <div class="login-proof" aria-hidden="true"><span>Self-hosted</span><span>Role-based access</span><span>Unified time &amp; billing</span></div>
    </section>

    <section class="login-panel">
      <div class="login-form-wrap">
        <h2>Verify your sign-in</h2>
        <p class="login-intro">Enter the six-digit code from your authenticator app to continue to your workspace.</p>

        <?php if ($error): ?><div class="login-alert login-alert--error" role="alert"><?php echo $h($error); ?></div><?php endif; ?>

        <form method="post" action="/?page=2fa-verify-action" class="login-form">
          <input type="hidden" name="_token" value="<?php echo $h($csrf); ?>">
          <input type="hidden" name="action" value="verify">
          <label class="login-field">
            <span>Verification code</span>
            <input required class="login-input two-factor-code" type="text" name="code" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000" aria-describedby="verification-code-help" autofocus>
          </label>
          <p class="login-help" id="verification-code-help">Use the current code shown in the authenticator app connected to your account.</p>
          <label class="two-factor-remember" for="remember_device">
            <input type="checkbox" name="remember_device" id="remember_device" value="1">
            <span>Remember this device for 30 days</span>
          </label>
          <div class="login-actions">
            <button type="submit" class="login-submit">Verify and continue</button>
          </div>
        </form>

        <div class="login-divider"><span>or</span></div>
        <a href="/?page=login" class="login-passkey two-factor-option">Use a passkey instead</a>
        <form method="post" action="/?page=logout"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string)($_SESSION['csrf'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><button type="submit" class="two-factor-cancel" style="border:0;background:none;cursor:pointer">Cancel and log out</button></form>
        <p class="login-footnote">Protected access to <?php echo $h($brandName); ?></p>
      </div>
    </section>
  </div>
</main>
</body>
</html>
