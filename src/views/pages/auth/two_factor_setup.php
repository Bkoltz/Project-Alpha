<?php
// src/views/pages/auth/two_factor_setup.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Require authenticated user
if (!isset($_SESSION['user'])) {
    header('Location: /?page=login');
    exit;
}

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/two_factor_auth.php';
require_once __DIR__ . '/../../../utils/two_factor_policy.php';

use App\Utils\TwoFactorAuth;

$userId = (int)$_SESSION['user']['id'];
$userEmail = $_SESSION['user']['email'];
$csrf = csrf_sf_token('2fa_setup');

// Get current 2FA status
$twofa = null;
try {
    $st = $pdo->prepare('SELECT * FROM user_2fa WHERE user_id = ?');
    $st->execute([$userId]);
    $twofa = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$isEnabled = $twofa && $twofa['enabled'];
$isRequired = two_factor_required_for_user($pdo, $userId);
$step = $_GET['step'] ?? 'main';
$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';
$isRecoveryEnrollment = !empty($_GET['recovery']);

// Check if we're showing backup codes
$showBackupCodes = isset($_SESSION['2fa_backup_codes']);
$backupCodes = $_SESSION['2fa_backup_codes'] ?? [];
?>

<style>
  .settings-container { max-width: 700px; margin: 0 auto; padding: 20px; }
  .status-badge { display: inline-block; padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 14px; }
  .status-enabled { background: #d1fae5; color: #065f46; }
  .status-disabled { background: #fee2e2; color: #991b1b; }
  .qr-container { background: #fff; padding: 20px; border-radius: 12px; text-align: center; margin: 20px 0; border: 2px solid #e5e7eb; }
  .backup-codes { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin: 20px 0; }
  .backup-code { background: #f3f4f6; padding: 12px; border-radius: 8px; font-family: monospace; font-size: 16px; font-weight: 600; text-align: center; border: 1px solid #d1d5db; }
  .alert { padding: 12px 16px; border-radius: 8px; margin: 16px 0; }
  .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
  .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
  .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
  .btn { padding: 10px 18px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; transition: all 0.2s; }
  .btn-primary { background: var(--nav-accent, #2563eb); color: #fff; }
  .btn-danger { background: #dc2626; color: #fff; }
  .btn-secondary { background: #6b7280; color: #fff; }
  .form-group { margin-bottom: 16px; }
  .form-group label { display: block; margin-bottom: 6px; font-weight: 600; }
  .form-group input { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 16px; }
  .secret-display { background: #f9fafb; padding: 16px; border-radius: 8px; margin: 16px 0; border: 1px solid #e5e7eb; }
  .secret-code { font-family: monospace; font-size: 18px; font-weight: 600; letter-spacing: 2px; text-align: center; padding: 12px; background: #fff; border-radius: 6px; word-break: break-all; }
</style>

<section class="settings-container">
  <h1>Two-Factor Authentication</h1>
  <?php if ($isRecoveryEnrollment): ?>
    <div class="alert alert-warning">TOTP was explicitly reset by the Docker recovery command. Enroll a new authenticator now to finish account recovery.</div>
  <?php endif; ?>
  
  <?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  
  <?php if ($success === 'enabled'): ?>
    <div class="alert alert-success">✓ Two-factor authentication has been successfully enabled!</div>
  <?php elseif ($success === 'disabled'): ?>
    <div class="alert alert-success">Two-factor authentication has been disabled.</div>
  <?php elseif ($success === 'backup_regenerated'): ?>
    <div class="alert alert-success">Backup codes have been regenerated. Save them securely!</div>
  <?php endif; ?>
  
  <?php if ($isRequired && !$isEnabled): ?>
    <div class="alert alert-warning">Two-factor authentication is required for administrators and users with privileged settings, user-management, payment, or financial-import access. Enable it to continue.</div>
  <?php elseif ($isRequired): ?>
    <div class="alert alert-warning">Two-factor authentication is strongly recommended for this account because it has admin or privileged access.</div>
  <?php endif; ?>

  <?php if ($step === 'main'): ?>
    <!-- Main 2FA status page -->
    <div style="display: flex; align-items: center; gap: 12px; margin: 20px 0;">
      <span>Status:</span>
      <span class="status-badge <?php echo $isEnabled ? 'status-enabled' : 'status-disabled'; ?>">
        <?php echo $isEnabled ? '✓ Enabled' : '✗ Disabled'; ?>
      </span>
    </div>
    
    <p style="color: #6b7280; margin: 20px 0;">
      Two-factor authentication adds an extra layer of security to your account by requiring a code from your 
      authenticator app (like Google Authenticator or Authy) in addition to your password.
    </p>
    
    <?php if (!$isEnabled): ?>
      <!-- Enable 2FA -->
      <form method="post" action="/?page=2fa-setup-action" style="margin-top: 24px;">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="action" value="enable_init">
        <button type="submit" class="btn btn-primary">Enable Two-Factor Authentication</button>
      </form>
      
    <?php else: ?>
      <!-- Disable 2FA or regenerate backup codes -->
      <div style="border-top: 1px solid #e5e7eb; padding-top: 24px; margin-top: 24px;">
        <h3>Disable Two-Factor Authentication</h3>
        <?php if ($isRequired): ?>
          <p style="color: #92400e; margin: 12px 0;">Your account has elevated privileges, so PA will keep recommending 2FA if you disable it.</p>
        <?php else: ?>
          <p style="color: #6b7280; margin: 12px 0;">Enter your password to disable 2FA.</p>
        <?php endif; ?>
        <form method="post" action="/?page=2fa-setup-action" style="margin-top: 16px;">
          <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="action" value="disable">
          <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required autocomplete="current-password">
          </div>
          <button type="submit" class="btn btn-danger">Disable 2FA</button>
        </form>
      </div>
      
      <div style="border-top: 1px solid #e5e7eb; padding-top: 24px; margin-top: 24px;">
        <h3>Regenerate Backup Codes</h3>
        <p style="color: #6b7280; margin: 12px 0;">
          Generate new backup codes. Your old backup codes will no longer work.
        </p>
        <form method="post" action="/?page=2fa-setup-action" style="margin-top: 16px;">
          <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="action" value="regenerate_backup">
          <div class="form-group">
            <label>Enter a 2FA code to confirm</label>
            <input type="text" name="code" required pattern="[0-9]{6}" maxlength="6" placeholder="000000" autocomplete="one-time-code">
          </div>
          <button type="submit" class="btn btn-secondary">Regenerate Backup Codes</button>
        </form>
      </div>
    <?php endif; ?>
    
  <?php elseif ($step === 'verify'): ?>
    <!-- Setup verification step -->
    <div class="alert alert-warning">
      <strong>Important:</strong> Save your backup codes before completing setup!
    </div>
    
    <h2>Step 1: Add PA to Your Authenticator</h2>
    <p style="color: #6b7280;">Choose the manual setup option in your authenticator app and enter the key below. PA keeps the secret on this server and does not send it to an external QR service.</p>
    
    <?php if ($twofa): ?>
      <div class="qr-container">
        <div class="secret-display">
          <p style="margin: 0 0 8px 0; font-size: 14px; color: #6b7280;">Manual setup key:</p>
          <div class="secret-code"><?php echo htmlspecialchars($twofa['secret']); ?></div>
        </div>
      </div>
    <?php endif; ?>
    
    <?php if ($showBackupCodes): ?>
      <h2>Step 2: Save Backup Codes</h2>
      <div class="alert alert-warning">
        <strong>Save these backup codes!</strong> Each code can be used once if you lose access to your authenticator app.
      </div>
      <div class="backup-codes">
        <?php foreach ($backupCodes as $code): ?>
          <div class="backup-code"><?php echo htmlspecialchars($code); ?></div>
        <?php endforeach; ?>
      </div>
      <p style="color: #6b7280; font-size: 14px; margin-top: 12px;">
        💡 Store these codes in a secure location like a password manager.
      </p>
    <?php endif; ?>
    
    <h2>Step 3: Verify Code</h2>
    <p style="color: #6b7280;">Enter the 6-digit code from your authenticator app to complete setup.</p>
    <form method="post" action="/?page=2fa-setup-action" style="margin-top: 16px;">
      <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrf); ?>">
      <input type="hidden" name="action" value="enable_verify">
      <div class="form-group">
        <label>Verification Code</label>
        <input type="text" name="code" required pattern="[0-9]{6}" maxlength="6" placeholder="000000" autocomplete="one-time-code" autofocus>
      </div>
      <button type="submit" class="btn btn-primary">Complete Setup</button>
      <a href="/?page=2fa-setup" class="btn btn-secondary" style="margin-left: 8px; text-decoration: none; display: inline-block;">Cancel</a>
    </form>
  <?php endif; ?>
  
  <?php if ($showBackupCodes && $step === 'main'): ?>
    <!-- Show backup codes after regeneration -->
    <div style="border-top: 1px solid #e5e7eb; padding-top: 24px; margin-top: 24px;">
      <h3>Your New Backup Codes</h3>
      <div class="alert alert-warning">
        <strong>Save these backup codes now!</strong> You won't be able to see them again.
      </div>
      <div class="backup-codes">
        <?php foreach ($backupCodes as $code): ?>
          <div class="backup-code"><?php echo htmlspecialchars($code); ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</section>
