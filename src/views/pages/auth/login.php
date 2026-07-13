<?php
// src/views/pages/auth/login.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/password_reset_tokens.php';

$csrf = csrf_sf_token('auth');
$noUsers = false;
try {
    $noUsers = ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()) === 0;
} catch (Throwable $e) {
    $noUsers = true;
}

$error = isset($_GET['error']) ? (string)$_GET['error'] : '';
$resetAvailable = password_reset_email_is_configured($appConfig);
$brandName = (string)($appConfig['brand_name'] ?? 'Project Alpha');
$brandLogo = trim((string)($appConfig['logo_path'] ?? '')) ?: '/assets/auth-logo.png';
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>

<style>
  .auth-topbar { display: none; }
  body { min-height: 100%; background: #eef3f6; }
  .login-page { min-height: 100vh; display: grid; place-items: center; padding: 32px; position: relative; overflow: hidden; }
  .login-page::before { content: ""; position: fixed; inset: 0; pointer-events: none; background: radial-gradient(circle at 12% 15%, rgba(46,163,214,.14), transparent 30%), radial-gradient(circle at 88% 90%, rgba(15,23,32,.11), transparent 32%); }
  .login-shell { width: min(1040px, 100%); min-height: 630px; display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(380px, .95fr); border: 1px solid rgba(15,23,32,.1); border-radius: 24px; overflow: hidden; background: #fff; box-shadow: 0 30px 80px rgba(15,23,32,.16); position: relative; z-index: 1; }
  .login-story { position: relative; isolation: isolate; display: flex; flex-direction: column; justify-content: space-between; padding: 48px; overflow: hidden; color: #fff; background: linear-gradient(145deg, #0f1720 0%, #122a3a 58%, #15506b 100%); }
  .login-story::before, .login-story::after { content: ""; position: absolute; border-radius: 999px; z-index: -1; }
  .login-story::before { width: 420px; height: 420px; right: -210px; top: -140px; border: 70px solid rgba(255,255,255,.055); }
  .login-story::after { width: 300px; height: 300px; left: -170px; bottom: -180px; background: rgba(46,163,214,.14); box-shadow: 0 0 0 54px rgba(46,163,214,.07); }
  .login-brand { display: inline-flex; align-items: center; gap: 14px; font-size: 18px; font-weight: 700; letter-spacing: .01em; }
  .login-brand img { width: 46px; height: 46px; padding: 5px; object-fit: contain; border-radius: 12px; background: rgba(255,255,255,.96); box-shadow: 0 8px 24px rgba(0,0,0,.2); }
  .login-story__copy { max-width: 440px; margin: 70px 0; }
  .login-kicker { margin: 0 0 12px; color: #7dd3fc; font-size: 12px; font-weight: 700; letter-spacing: .13em; text-transform: uppercase; }
  .login-story h1 { margin: 0; color: #fff; font-size: clamp(34px, 5vw, 54px); line-height: 1.04; letter-spacing: -.045em; }
  .login-story__copy > p:last-child { margin: 22px 0 0; color: rgba(255,255,255,.72); font-size: 16px; line-height: 1.7; }
  .login-proof { display: flex; gap: 18px; flex-wrap: wrap; color: rgba(255,255,255,.72); font-size: 12px; }
  .login-proof span { display: inline-flex; align-items: center; gap: 7px; }
  .login-proof span::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: #38bdf8; box-shadow: 0 0 0 4px rgba(56,189,248,.13); }
  .login-panel { display: flex; align-items: center; padding: 54px clamp(32px, 5vw, 68px); background: linear-gradient(180deg, #fff, #fbfcfd); }
  .login-form-wrap { width: 100%; max-width: 390px; margin: auto; }
  .login-form-wrap h2 { margin: 0; font-size: 30px; letter-spacing: -.035em; }
  .login-intro { margin: 10px 0 28px; color: #64748b; line-height: 1.55; }
  .login-alert { margin: 0 0 18px; padding: 11px 13px; border: 1px solid; border-radius: 10px; font-size: 14px; }
  .login-alert--error { background: #fff1f2; border-color: #fda4af; color: #881337; }
  .login-alert--success { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
  .login-form { display: grid; gap: 17px; }
  .login-field { display: grid; gap: 7px; color: #334155; font-size: 13px; font-weight: 650; }
  .login-input { width: 100%; padding: 12px 13px; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; color: #0f172a; font: inherit; outline: none; transition: border-color .15s, box-shadow .15s; }
  .login-input:focus { border-color: var(--nav-accent); box-shadow: 0 0 0 4px rgba(46,163,214,.13); }
  .login-help { margin: -4px 0 0; color: #64748b; font-size: 12px; line-height: 1.5; }
  .login-actions { display: grid; gap: 15px; margin-top: 6px; }
  .login-submit { width: 100%; padding: 12px 18px; border: 0; border-radius: 10px; color: #fff; background: linear-gradient(135deg, #1684b4, var(--nav-accent)); box-shadow: 0 10px 24px rgba(46,163,214,.24); font: inherit; font-weight: 700; cursor: pointer; }
  .login-submit:hover { filter: brightness(.97); transform: translateY(-1px); }
  .login-reset { justify-self: center; color: #0369a1; font-size: 13px; font-weight: 600; }
  .login-reset:hover { text-decoration: underline; }
  .login-footnote { margin: 28px 0 0; padding-top: 20px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 12px; text-align: center; }
  @media (max-width: 820px) {
    .login-page { padding: 18px; place-items: start center; }
    .login-shell { grid-template-columns: 1fr; min-height: 0; }
    .login-story { min-height: 270px; padding: 30px; }
    .login-story__copy { margin: 38px 0 18px; }
    .login-story h1 { font-size: 34px; }
    .login-story__copy > p:last-child { display: none; }
    .login-panel { padding: 38px 28px 42px; }
  }
  @media (max-width: 480px) {
    .login-page { padding: 0; background: #fff; }
    .login-shell { width: 100%; border: 0; border-radius: 0; box-shadow: none; }
    .login-story { min-height: 220px; padding: 25px 22px; }
    .login-proof { display: none; }
    .login-panel { padding: 34px 22px 42px; }
  }
</style>

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
        <h2><?php echo $noUsers ? 'Create your admin' : 'Welcome back'; ?></h2>
        <p class="login-intro"><?php echo $noUsers ? 'Set up the first Project Alpha administrator to get started.' : 'Sign in to continue to your workspace.'; ?></p>

        <?php if ($error): ?><div class="login-alert login-alert--error" role="alert"><?php echo $h($error); ?></div><?php endif; ?>
        <?php if (!$noUsers && !empty($_GET['created'])): ?><div class="login-alert login-alert--success">Account created. Please sign in.</div><?php endif; ?>
        <?php if (!$noUsers && !empty($_GET['pwd_reset'])): ?><div class="login-alert login-alert--success">Password updated. Please sign in.</div><?php endif; ?>

        <form method="post" action="/?page=auth" class="login-form">
          <input type="hidden" name="_token" value="<?php echo $h($csrf); ?>">
          <input type="hidden" name="action" value="<?php echo $noUsers ? 'register_first' : 'login'; ?>">
          <label class="login-field">
            <span><?php echo $noUsers ? 'Administrator email' : 'Email or username'; ?></span>
            <input required class="login-input" type="<?php echo $noUsers ? 'email' : 'text'; ?>" name="email" autocomplete="username" autofocus>
          </label>
          <?php if ($noUsers): ?>
            <label class="login-field">
              <span>Administrator username</span>
              <input required class="login-input" minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]+" type="text" name="username" autocomplete="username">
            </label>
          <?php endif; ?>
          <label class="login-field">
            <span>Password</span>
            <input required class="login-input" minlength="8" type="password" name="password" autocomplete="<?php echo $noUsers ? 'new-password' : 'current-password'; ?>">
          </label>
          <?php if ($noUsers): ?>
            <label class="login-field">
              <span>Confirm password</span>
              <input required class="login-input" minlength="8" type="password" name="password2" autocomplete="new-password">
            </label>
            <p class="login-help">This creates PA's first administrator. No default or Compose-managed login exists.</p>
          <?php endif; ?>
          <div class="login-actions">
            <button type="submit" class="login-submit"><?php echo $noUsers ? 'Create administrator' : 'Sign in'; ?></button>
            <?php if (!$noUsers && $resetAvailable): ?><a href="/?page=reset-password" class="login-reset">Forgot your password?</a><?php endif; ?>
          </div>
        </form>
        <p class="login-footnote">Protected access to <?php echo $h($brandName); ?></p>
      </div>
    </section>
  </div>
</main>

</body>
</html>
