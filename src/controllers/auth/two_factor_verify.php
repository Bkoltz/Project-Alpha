<?php
// src/controllers/auth/two_factor_verify.php
// Handles 2FA verification during login with "Remember this device" support

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/logger.php';
require_once __DIR__ . '/../../utils/two_factor_auth.php';

use App\Utils\TwoFactorAuth;

// Check if user is in 2FA pending state
if (!isset($_SESSION['2fa_pending'])) {
    header('Location: /?page=login');
    exit;
}

$userId = (int)$_SESSION['2fa_pending']['user_id'];
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// CSRF check
require_once __DIR__ . '/../../utils/csrf_sf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = $_POST['_token'] ?? '';
    if (!csrf_sf_is_valid('2fa_verify', is_string($submitted) ? $submitted : '')) {
        header('Location: /?page=2fa-verify&error=' . urlencode('Invalid request (CSRF)'));
        exit;
    }
}

$action = $_POST['action'] ?? '';
$code = trim($_POST['code'] ?? '');

/**
 * Detect whether the connection is HTTPS, including reverse-proxy headers.
 */
function is_cookie_secure(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'])) === 'https')
        || (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos((string)$_SERVER['HTTP_CF_VISITOR'], 'https') !== false)
        || (!empty($_SERVER['HTTP_X_SCHEME']) && strtolower((string)$_SERVER['HTTP_X_SCHEME']) === 'https');
}

/**
 * Create or update a trusted device token
 */
function set_trusted_device(PDO $pdo, int $userId, string $ip): void {
    $token = bin2hex(random_bytes(32));
    $deviceName = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Device';
    $userAgentHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    
    $cookieOptions = [
        'expires' => strtotime('+30 days'),
        'path' => '/',
        'domain' => '',
        'secure' => is_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Strict',
    ];
    
    try {
        $st = $pdo->prepare('
            DELETE FROM trusted_devices 
            WHERE user_id = ? AND ip_address = ? AND user_agent_hash = ?
        ');
        $st->execute([$userId, $ip, $userAgentHash]);
        
        $st = $pdo->prepare('
            INSERT INTO trusted_devices (user_id, device_token, device_name, ip_address, user_agent_hash, last_verified_at, expires_at)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
        ');
        $st->execute([$userId, $token, $deviceName, $ip, $userAgentHash, $expires]);
        
        setcookie('device_trust', $token, $cookieOptions);
    } catch (Throwable $e) {
        // If DB table doesn't exist yet, just set the cookie
        setcookie('device_trust', $token, $cookieOptions);
    }
}

try {
    // Get user's 2FA settings
    $st = $pdo->prepare('SELECT * FROM user_2fa WHERE user_id = ? AND enabled = 1');
    $st->execute([$userId]);
    $twofa = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$twofa) {
        // 2FA not enabled, clear pending state and proceed
        unset($_SESSION['2fa_pending']);
        $_SESSION['user'] = $_SESSION['2fa_pending']['user_data'];
        header('Location: /');
        exit;
    }
    
    // Throttle 2FA attempts (max 10 attempts in 10 minutes)
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_2fa_attempts WHERE user_id = ? AND attempted_at >= NOW() - INTERVAL 10 MINUTE');
    $st->execute([$userId]);
    $attempts = (int)$st->fetchColumn();
    
    if ($attempts >= 10) {
        // Log and lock out
        app_log('2fa', '2FA verification throttled', ['user_id' => $userId, 'ip' => $ip]);
        unset($_SESSION['2fa_pending']);
        header('Location: /?page=login&error=' . urlencode('Too many attempts. Please try again later.'));
        exit;
    }
    
    if ($action === 'verify') {
        $useBackup = !empty($_POST['use_backup']);
        $rememberDevice = !empty($_POST['remember_device']);
        $verified = false;
        
        if ($useBackup) {
            // Verify backup code
            $backupCodes = json_decode($twofa['backup_codes'] ?? '[]', true);
            if (TwoFactorAuth::verifyBackupCode($code, $backupCodes)) {
                // Remove used backup code
                $hash = TwoFactorAuth::hashBackupCode($code);
                $backupCodes = array_values(array_filter($backupCodes, fn($h) => $h !== $hash));
                
                $st = $pdo->prepare('UPDATE user_2fa SET backup_codes = ? WHERE user_id = ?');
                $st->execute([json_encode($backupCodes), $userId]);
                
                $verified = true;
                app_log('2fa', '2FA verified with backup code', ['user_id' => $userId, 'ip' => $ip]);
            }
        } else {
            // Verify TOTP code
            if (TwoFactorAuth::verifyCode($code, $twofa['secret'])) {
                $verified = true;
                app_log('2fa', '2FA verified', ['user_id' => $userId, 'ip' => $ip]);
            }
        }
        
        // Log attempt
        $st = $pdo->prepare('INSERT INTO login_2fa_attempts (user_id, ip, success) VALUES (?, ?, ?)');
        $st->execute([$userId, $ip, $verified ? 1 : 0]);
        
        if ($verified) {
            // Complete login
            session_regenerate_id(true);
            $_SESSION['user'] = $_SESSION['2fa_pending']['user_data'];
            unset($_SESSION['2fa_pending']);
            
            // If user checked "Remember this device", create a trusted device token
            if ($rememberDevice) {
                set_trusted_device($pdo, $userId, $ip);
            }
            
            // Check if force password reset is required
            $forceReset = false;
            try {
                $st = $pdo->prepare('SELECT force_password_reset FROM users WHERE id = ?');
                $st->execute([(int)$_SESSION['user']['id']]);
                $forceReset = (bool)$st->fetchColumn();
            } catch (Throwable $e) {}
            
            if ($forceReset) {
                header('Location: /?page=account&force=1');
                exit;
            }
            
            header('Location: /');
            exit;
        } else {
            $errorMsg = $useBackup ? 'Invalid backup code' : 'Invalid verification code';
            header('Location: /?page=2fa-verify&error=' . urlencode($errorMsg));
            exit;
        }
    }
    
} catch (Throwable $e) {
    app_log('2fa', 'Verification error', ['error' => $e->getMessage(), 'user_id' => $userId]);
    header('Location: /?page=2fa-verify&error=' . urlencode('An error occurred'));
    exit;
}

header('Location: /?page=2fa-verify');
exit;
