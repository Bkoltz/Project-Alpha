<?php
// src/controllers/auth/two_factor_setup.php
// Handles 2FA setup: enable, disable, verify setup

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/logger.php';
require_once __DIR__ . '/../../utils/two_factor_auth.php';

use App\Utils\TwoFactorAuth;

// Require authenticated user
if (!isset($_SESSION['user'])) {
    header('Location: /?page=login');
    exit;
}

$userId = (int)$_SESSION['user']['id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF check
require_once __DIR__ . '/../../utils/csrf_sf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = $_POST['_token'] ?? '';
    if (!csrf_sf_is_valid('2fa_setup', is_string($submitted) ? $submitted : '')) {
        header('Location: /?page=2fa-setup&error=' . urlencode('Invalid request (CSRF)'));
        exit;
    }
}

try {
    // Get current 2FA status
    $st = $pdo->prepare('SELECT * FROM user_2fa WHERE user_id = ?');
    $st->execute([$userId]);
    $twofa = $st->fetch(PDO::FETCH_ASSOC);
    
    if ($action === 'enable_init') {
        // Generate new secret and backup codes
        $secret = TwoFactorAuth::generateSecret();
        $backupCodesPlain = TwoFactorAuth::generateBackupCodes(8);
        $backupCodesHashed = array_map([TwoFactorAuth::class, 'hashBackupCode'], $backupCodesPlain);
        
        // Store in database (not enabled yet, waiting for verification)
        if ($twofa) {
            $st = $pdo->prepare('UPDATE user_2fa SET secret = ?, enabled = 0, backup_codes = ? WHERE user_id = ?');
            $st->execute([$secret, json_encode($backupCodesHashed), $userId]);
        } else {
            $st = $pdo->prepare('INSERT INTO user_2fa (user_id, secret, enabled, backup_codes) VALUES (?, ?, 0, ?)');
            $st->execute([$userId, $secret, json_encode($backupCodesHashed)]);
        }
        
        // Store backup codes in session temporarily for display
        $_SESSION['2fa_backup_codes'] = $backupCodesPlain;
        
        header('Location: /?page=2fa-setup&step=verify');
        exit;
    }
    
    if ($action === 'enable_verify') {
        // Verify the code before enabling
        $code = trim($_POST['code'] ?? '');
        
        if (!$twofa) {
            header('Location: /?page=2fa-setup&error=' . urlencode('2FA setup not initialized'));
            exit;
        }
        
        if (TwoFactorAuth::verifyCode($code, $twofa['secret'])) {
            // Enable 2FA
            $st = $pdo->prepare('UPDATE user_2fa SET enabled = 1, enabled_at = NOW() WHERE user_id = ?');
            $st->execute([$userId]);
            
            app_log('2fa', '2FA enabled', ['user_id' => $userId]);
            
            // Clear backup codes from session after successful setup
            // (user should have saved them already)
            unset($_SESSION['2fa_backup_codes']);
            
            header('Location: /?page=2fa-setup&success=enabled');
            exit;
        } else {
            header('Location: /?page=2fa-setup&step=verify&error=' . urlencode('Invalid code. Please try again.'));
            exit;
        }
    }
    
    if ($action === 'disable') {
        // Verify password before disabling
        $password = $_POST['password'] ?? '';
        
        if (empty($password)) {
            header('Location: /?page=2fa-setup&error=' . urlencode('Password required to disable 2FA'));
            exit;
        }
        
        // Verify user's password
        $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$userId]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !password_verify($password, $user['password_hash'])) {
            header('Location: /?page=2fa-setup&error=' . urlencode('Invalid password'));
            exit;
        }
        
        // Disable 2FA
        if ($twofa) {
            $st = $pdo->prepare('DELETE FROM user_2fa WHERE user_id = ?');
            $st->execute([$userId]);
            
            app_log('2fa', '2FA disabled', ['user_id' => $userId]);
        }
        
        header('Location: /?page=2fa-setup&success=disabled');
        exit;
    }
    
    if ($action === 'regenerate_backup') {
        // Regenerate backup codes (requires current 2FA code)
        $code = trim($_POST['code'] ?? '');
        
        if (!$twofa || !$twofa['enabled']) {
            header('Location: /?page=2fa-setup&error=' . urlencode('2FA not enabled'));
            exit;
        }
        
        if (!TwoFactorAuth::verifyCode($code, $twofa['secret'])) {
            header('Location: /?page=2fa-setup&error=' . urlencode('Invalid 2FA code'));
            exit;
        }
        
        // Generate new backup codes
        $backupCodesPlain = TwoFactorAuth::generateBackupCodes(8);
        $backupCodesHashed = array_map([TwoFactorAuth::class, 'hashBackupCode'], $backupCodesPlain);
        
        $st = $pdo->prepare('UPDATE user_2fa SET backup_codes = ? WHERE user_id = ?');
        $st->execute([json_encode($backupCodesHashed), $userId]);
        
        $_SESSION['2fa_backup_codes'] = $backupCodesPlain;
        
        app_log('2fa', 'Backup codes regenerated', ['user_id' => $userId]);
        
        header('Location: /?page=2fa-setup&success=backup_regenerated');
        exit;
    }
    
    // Revoke a trusted device
    if ($action === 'revoke_device') {
        $deviceId = (int)($_POST['device_id'] ?? 0);
        
        try {
            // Only allow revoking own devices
            $st = $pdo->prepare('DELETE FROM trusted_devices WHERE id = ? AND user_id = ?');
            $st->execute([$deviceId, $userId]);
            
            app_log('2fa', 'Trusted device revoked', ['user_id' => $userId, 'device_id' => $deviceId]);
            header('Location: /?page=2fa-setup&success=device_revoked');
            exit;
        } catch (Throwable $e) {
            header('Location: /?page=2fa-setup&error=' . urlencode('Failed to revoke device'));
            exit;
        }
    }
    
    // Add trusted IP (admin only)
    if ($action === 'add_trusted_ip') {
        if (($_SESSION['user']['role'] ?? '') !== 'admin') {
            header('Location: /?page=2fa-setup&error=' . urlencode('Admin access required'));
            exit;
        }
        
        $ipAddress = trim($_POST['ip_address'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if (empty($ipAddress) || !filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            header('Location: /?page=2fa-setup&error=' . urlencode('Invalid IP address'));
            exit;
        }
        
        try {
            $st = $pdo->prepare('INSERT INTO trusted_ips (ip_address, description, created_by) VALUES (?, ?, ?)');
            $st->execute([$ipAddress, $description, $userId]);
            
            app_log('2fa', 'Trusted IP added', ['user_id' => $userId, 'ip' => $ipAddress]);
            header('Location: /?page=2fa-setup&success=ip_added');
            exit;
        } catch (Throwable $e) {
            header('Location: /?page=2fa-setup&error=' . urlencode('Failed to add trusted IP'));
            exit;
        }
    }
    
    // Remove trusted IP (admin only)
    if ($action === 'remove_trusted_ip') {
        if (($_SESSION['user']['role'] ?? '') !== 'admin') {
            header('Location: /?page=2fa-setup&error=' . urlencode('Admin access required'));
            exit;
        }
        
        $ipId = (int)($_POST['ip_id'] ?? 0);
        
        try {
            $st = $pdo->prepare('DELETE FROM trusted_ips WHERE id = ?');
            $st->execute([$ipId]);
            
            app_log('2fa', 'Trusted IP removed', ['user_id' => $userId, 'ip_id' => $ipId]);
            header('Location: /?page=2fa-setup&success=ip_removed');
            exit;
        } catch (Throwable $e) {
            header('Location: /?page=2fa-setup&error=' . urlencode('Failed to remove trusted IP'));
            exit;
        }
    }
    
} catch (Throwable $e) {
    app_log('2fa', 'Setup error', ['error' => $e->getMessage(), 'user_id' => $userId]);
    header('Location: /?page=2fa-setup&error=' . urlencode('An error occurred'));
    exit;
}

header('Location: /?page=2fa-setup');
exit;
