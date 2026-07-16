<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user']['id'])) { header('Location: /?page=login'); exit; }

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/passkey_auth.php';

use App\Utils\PasskeyService;

$userId = (int)$_SESSION['user']['id'];
$csrf = csrf_sf_token('passkey_manage');
$passkeys = [];
$availabilityError = null;
try {
    $passkeys = (new PasskeyService($pdo, $appConfig))->listForUser($userId);
} catch (Throwable $e) {
    $availabilityError = 'Passkeys are unavailable until an installation administrator configures WEBAUTHN_ORIGIN.';
}
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<style>
  .passkey-page { max-width:900px; margin:0 auto; }
  .passkey-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; margin-bottom:16px; }
  .passkey-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
  .passkey-field { display:grid; gap:6px; font-weight:600; font-size:14px; }
  .passkey-field input { width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; }
  .passkey-actions { display:flex; gap:10px; align-items:end; flex-wrap:wrap; }
  .passkey-button { padding:9px 14px; border:0; border-radius:8px; background:var(--nav-accent,#2563eb); color:#fff; font-weight:650; cursor:pointer; }
  .passkey-button--danger { background:#dc2626; }
  .passkey-meta { color:#64748b; font-size:13px; }
  .passkey-alert { padding:12px 14px; border-radius:8px; margin:12px 0; }
  .passkey-alert--error,.passkey-message-error { background:#fff1f2; color:#9f1239; }
  .passkey-alert--success { background:#ecfdf5; color:#065f46; }
  @media(max-width:700px){.passkey-grid{grid-template-columns:1fr}}
</style>
<section class="passkey-page">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div><h1 style="margin-bottom:6px">Passkeys</h1><p style="color:#64748b;margin-top:0">Sign in with your fingerprint, face, device PIN, or security key—without typing your password.</p></div>
    <a href="/?page=account">Back to My Account</a>
  </div>
  <?php if (!empty($_GET['success'])): ?><div class="passkey-alert passkey-alert--success"><?php echo $h($_GET['success']); ?></div><?php endif; ?>
  <?php if (!empty($_GET['error'])): ?><div class="passkey-alert passkey-alert--error"><?php echo $h($_GET['error']); ?></div><?php endif; ?>
  <?php if ($availabilityError): ?><div class="passkey-alert passkey-alert--error"><?php echo $h($availabilityError); ?></div><?php endif; ?>

  <?php if (!$availabilityError): ?>
    <div class="passkey-card">
      <h2 style="margin-top:0">Add a Passkey</h2>
      <p class="passkey-meta">Use a name you will recognize, such as “Office Windows Hello” or “iPhone.” Confirm your current password before the browser creates it.</p>
      <form id="passkey-register-form" class="passkey-grid" data-passkey-register data-csrf="<?php echo $h($csrf); ?>" data-options-url="/?page=passkey-register-options" data-complete-url="/?page=passkey-register-complete" data-status-id="passkey-register-status">
        <label class="passkey-field">Passkey name<input name="name" required maxlength="100" autocomplete="off" placeholder="Office computer"></label>
        <label class="passkey-field">Current password<input name="current_password" required type="password" autocomplete="current-password"></label>
        <div class="passkey-actions"><button class="passkey-button" type="submit">Add Passkey</button></div>
      </form>
      <p id="passkey-register-status" class="passkey-alert" hidden aria-live="polite"></p>
      <p class="passkey-meta">For account recovery, add a second passkey kept on another device or security key.</p>
    </div>

    <h2>Your Passkeys</h2>
    <?php if (!$passkeys): ?><div class="passkey-card"><p style="margin:0">No passkeys have been added yet.</p></div><?php endif; ?>
    <?php foreach ($passkeys as $passkey): ?>
      <article class="passkey-card">
        <h3 style="margin:0 0 6px"><?php echo $h($passkey['display_name']); ?></h3>
        <p class="passkey-meta">Added <?php echo $h($passkey['created_at']); ?><?php if ($passkey['last_used_at']): ?> · Last used <?php echo $h($passkey['last_used_at']); ?><?php endif; ?><?php if (!empty($passkey['backup_status'])): ?> · Synced/backup available<?php endif; ?></p>
        <div class="passkey-grid" style="margin-top:14px">
          <form method="post" action="/?page=passkey-manage" class="passkey-actions">
            <input type="hidden" name="_token" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="rename"><input type="hidden" name="credential_id" value="<?php echo (int)$passkey['id']; ?>">
            <label class="passkey-field" style="flex:1">New name<input name="name" required maxlength="100" value="<?php echo $h($passkey['display_name']); ?>"></label>
            <label class="passkey-field" style="flex:1">Current password<input name="current_password" required type="password" autocomplete="current-password"></label>
            <button class="passkey-button" type="submit">Rename</button>
          </form>
          <form method="post" action="/?page=passkey-manage" class="passkey-actions" data-passkey-confirm="Remove this passkey? It will stop working immediately.">
            <input type="hidden" name="_token" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="credential_id" value="<?php echo (int)$passkey['id']; ?>">
            <label class="passkey-field" style="flex:1">Current password<input name="current_password" required type="password" autocomplete="current-password"></label>
            <button class="passkey-button passkey-button--danger" type="submit">Remove Passkey</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
    <script src="/assets/passkeys.js" defer></script>
  <?php endif; ?>
</section>
