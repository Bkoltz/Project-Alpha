<?php
// src/controllers/auth/auth_handler.php
// Handles login and first-admin registration with CSRF verification
// Now includes: trusted device checking, daily MFA window, trusted IP support

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/logger.php';
require_once __DIR__ . '/../../utils/crypto.php';

// Verbose error toggle: set APP_VERBOSE_ERRORS=true or AUTH_VERBOSE_ERRORS=true (or APP_DEBUG=true)
$VERBOSE_AUTH = filter_var(getenv('APP_VERBOSE_ERRORS') ?: getenv('AUTH_VERBOSE_ERRORS') ?: getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
// CSRF check (prefer Symfony token, fallback to legacy)
require_once __DIR__ . '/../../utils/csrf_sf.php';
$submitted = $_POST['_token'] ?? ($_POST['csrf'] ?? '');
$csrfValid = false;
if (is_string($submitted) && $submitted !== '') {
    // Try Symfony token first
    $csrfValid = csrf_sf_is_valid('auth', $submitted);
    // Fallback to legacy token
    if (!$csrfValid && !empty($_SESSION['csrf'])) {
        $csrfValid = hash_equals((string)$_SESSION['csrf'], $submitted);
    }
    // Also check _csrf Symfony array format
    if (!$csrfValid && !empty($_SESSION['_csrf']['auth'])) {
        $csrfValid = hash_equals((string)$_SESSION['_csrf']['auth'], $submitted);
    }
}
if (!$csrfValid) {
    header('Location: /?page=login&error=' . urlencode('Invalid request (CSRF)'));
    exit;
}

$action = $_POST['action'] ?? '';
$emailOrUsername = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

function valid_email($e) { return filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }

/**
 * Check if the current IP is in the trusted IPs whitelist
 */
function is_trusted_ip(PDO $pdo, string $ip): bool {
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM trusted_ips WHERE ip_address = ?');
        $st->execute([$ip]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check if user has a valid trusted device token
 */
function has_trusted_device(PDO $pdo, int $userId, string $ip): bool {
    $deviceToken = $_COOKIE['device_trust'] ?? '';
    if (empty($deviceToken) || strlen($deviceToken) !== 64) {
        return false;
    }
    
    try {
        // Check if token exists, matches IP, and hasn't expired
        $st = $pdo->prepare('
            SELECT COUNT(*) FROM trusted_devices 
            WHERE user_id = ? 
            AND device_token = ? 
            AND ip_address = ? 
            AND expires_at > NOW()
        ');
        $st->execute([$userId, $deviceToken, $ip]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check if user has verified MFA within the last 24 hours on this device
 */
function has_daily_mfa_verification(PDO $pdo, int $userId): bool {
    $deviceToken = $_COOKIE['device_trust'] ?? '';
    if (empty($deviceToken) || strlen($deviceToken) !== 64) {
        return false;
    }
    
    try {
        $st = $pdo->prepare('
            SELECT COUNT(*) FROM trusted_devices 
            WHERE user_id = ? 
            AND device_token = ? 
            AND last_verified_at >= NOW() - INTERVAL 1 DAY
        ');
        $st->execute([$userId, $deviceToken]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Create or update a trusted device token
 */
function set_trusted_device(PDO $pdo, int $userId, string $ip): void {
    // Generate a new secure token
    $token = bin2hex(random_bytes(32)); // 64 chars hex
    $deviceName = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Device';
    $userAgentHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    
    try {
        // Delete old tokens for this user+IP+UA combo
        $st = $pdo->prepare('
            DELETE FROM trusted_devices 
            WHERE user_id = ? AND ip_address = ? AND user_agent_hash = ?
        ');
        $st->execute([$userId, $ip, $userAgentHash]);
        
        // Insert new token
        $st = $pdo->prepare('
            INSERT INTO trusted_devices (user_id, device_token, device_name, ip_address, user_agent_hash, last_verified_at, expires_at)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
        ');
        $st->execute([$userId, $token, $deviceName, $ip, $userAgentHash, $expires]);
        
        // Set cookie (30 days)
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'])) === 'https');
        setcookie('device_trust', $token, [
            'expires' => strtotime('+30 days'),
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } catch (Throwable $e) {
        // If DB table doesn't exist yet, just set the cookie
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'])) === 'https');
        setcookie('device_trust', $token, [
            'expires' => strtotime('+30 days'),
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

try {
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable $e) {
    $count = 0;
}

// Throttle login if too many attempts from this IP in a short window
if ($action === 'login') {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND attempted_at >= NOW() - INTERVAL 10 MINUTE");
        $st->execute([$ip]);
        $attempts = (int)$st->fetchColumn();
        if ($attempts >= 15) {
            header('Location: /?page=login&error=' . urlencode('Too many attempts. Try again later.'));
            exit;
        }
    } catch (Throwable $e) { /* ignore throttle errors */ }
}

if ($action === 'register_first') {
    // Only allow if there are no users yet
    if ($count > 0) {
        header('Location: /?page=login&error=' . urlencode('Setup already completed'));
        exit;
    }
    if (!valid_email($emailOrUsername)) {
        header('Location: /?page=login&error=' . urlencode('Enter a valid email'));
        exit;
    }
    if (strlen($password) < 8) {
        header('Location: /?page=login&error=' . urlencode('Password must be at least 8 characters'));
        exit;
    }
    $password2 = (string)($_POST['password2'] ?? '');
    if ($password !== $password2) {
        header('Location: /?page=login&error=' . urlencode('Passwords do not match'));
        exit;
    }
    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $st = $pdo->prepare('INSERT INTO users (email, password_hash, role) VALUES (?,?,?)');
        $st->execute([$emailOrUsername, $hash, 'admin']);
        // Do not auto-login the new admin; require explicit sign-in
        // This ensures session/cookies are established via the normal login flow.
        header('Location: /?page=login&created=1');
        exit;
    } catch (Throwable $e) {
        // Log exception for debugging (do not reveal to user)
        try { app_log('auth', 'register_first failed', ['ex' => $e->getMessage()]); } catch (Throwable $_e) { /* ignore logging failure */ }
        $msg = 'Failed to create admin';
        if ($VERBOSE_AUTH) { $msg .= ': ' . $e->getMessage(); }
        header('Location: /?page=login&error=' . urlencode($msg));
        exit;
    }
}

if ($action === 'login') {
    if (empty($emailOrUsername)) {
        // record attempt
        try { $pdo->prepare('INSERT INTO login_attempts (ip, email) VALUES (?,?)')->execute([$ip, null]); } catch (Throwable $e) {}
        header('Location: /?page=login&error=' . urlencode('Invalid credentials'));
        exit;
    }
    try {
        $isEmail = valid_email($emailOrUsername);
        if ($isEmail) {
            $st = $pdo->prepare('SELECT id, email, password_hash, role FROM users WHERE email=?');
            $st->execute([$emailOrUsername]);
        } else {
            // Accept either username or email when the input isn't a valid email
            $st = $pdo->prepare('SELECT id, email, password_hash, role FROM users WHERE username=? OR email=?');
            $st->execute([$emailOrUsername, $emailOrUsername]);
        }
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            try { $pdo->prepare('INSERT INTO login_attempts (ip, email) VALUES (?,?)')->execute([$ip, $isEmail ? $emailOrUsername : null]); } catch (Throwable $e) {}
            app_log('auth', 'login failed', ['ip'=>$ip, 'input'=>$emailOrUsername]);
            header('Location: /?page=login&error=' . urlencode('Invalid credentials'));
            exit;
        }
        // Check if account is disabled
        if (!empty($u['is_disabled'])) {
            app_log('auth', 'login denied - account disabled', ['ip'=>$ip, 'uid'=>(int)$u['id']]);
            header('Location: /?page=login&error=' . urlencode('Account disabled. Contact an administrator.'));
            exit;
        }
        // Check if user has 2FA enabled
        $twofa_enabled = false;
        try {
            $st2fa = $pdo->prepare('SELECT enabled FROM user_2fa WHERE user_id = ? AND enabled = 1');
            $st2fa->execute([$userId]);
            $twofa_enabled = (bool)$st2fa->fetchColumn();
        } catch (Throwable $e) {}
        
        if ($twofa_enabled) {
            // Check if user is coming from a trusted IP
            $trustedIp = is_trusted_ip($pdo, $ip);
            
            // Check if user has a trusted device with daily MFA already verified
            $trustedDevice = has_trusted_device($pdo, $userId, $ip);
            $dailyMfa = has_daily_mfa_verification($pdo, $userId);
            
            if ($trustedIp) {
                // Trusted IP - skip 2FA entirely
                app_log('auth', 'login skip 2FA (trusted IP)', ['user_id'=>$userId, 'ip'=>$ip]);
            } elseif ($trustedDevice && $dailyMfa) {
                // Trusted device + verified MFA today - skip 2FA
                app_log('auth', 'login skip 2FA (trusted device)', ['user_id'=>$userId, 'ip'=>$ip]);
            } else {
                // User has 2FA enabled, redirect to 2FA verification
                // Preserve CSRF token before regeneration
                $csrfToken = $_SESSION['_csrf'] ?? null;
                $legacyCsrf = $_SESSION['csrf'] ?? null;
                session_regenerate_id(true);
                // Restore CSRF token in new session
                if ($csrfToken !== null) {
                    $_SESSION['_csrf'] = $csrfToken;
                }
                if ($legacyCsrf !== null) {
                    $_SESSION['csrf'] = $legacyCsrf;
                }
                $_SESSION['2fa_pending'] = [
                    'user_id' => $userId,
                    'user_data' => ['id'=>$userId, 'email'=>$u['email'], 'role'=>$u['role']]
                ];
                app_log('auth', 'login requires 2FA', ['user_id'=>$userId, 'ip'=>$ip]);
                header('Location: /?page=2fa-verify');
                exit;
            }
        }
        
        // on success (no 2FA or 2FA skipped), regenerate session and optionally clear attempts
        // Preserve CSRF token before regeneration
        $csrfToken = $_SESSION['_csrf'] ?? null;
        $legacyCsrf = $_SESSION['csrf'] ?? null;
        session_regenerate_id(true);
        // Restore CSRF token in new session
        if ($csrfToken !== null) {
            $_SESSION['_csrf'] = $csrfToken;
        }
        if ($legacyCsrf !== null) {
            $_SESSION['csrf'] = $legacyCsrf;
        }
        
        // Fetch user's organizations for multi-tenant support
        $orgs = [];
        $defaultOrgId = null;
        try {
            $orgStmt = $pdo->prepare('
                SELECT o.id, o.name, uo.role, uo.is_default 
                FROM organizations o 
                JOIN user_organizations uo ON uo.organization_id = o.id 
                WHERE uo.user_id = ?
            ');
            $orgStmt->execute([$userId]);
            $orgs = $orgStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($orgs as $org) {
                if ($org['is_default']) {
                    $defaultOrgId = (int)$org['id'];
                    break;
                }
            }
            if (!$defaultOrgId && count($orgs) > 0) {
                $defaultOrgId = (int)$orgs[0]['id'];
            }
        } catch (Throwable $e) { /* ignore org fetch errors */ }
        
        $_SESSION['user'] = [
            'id'=>$userId, 
            'email'=>$u['email'], 
            'role'=>$u['role'],
            'organization_id' => $defaultOrgId,
            'organizations' => $orgs
        ];
        
        // Set trusted device if user checked "Remember this device"
        if (!empty($_POST['remember_device'])) {
            set_trusted_device($pdo, $userId, $ip);
        }
        
        $_SESSION['user'] = ['id'=>(int)$u['id'], 'email'=>$u['email'], 'role'=>$u['role']];
        try { $pdo->prepare('DELETE FROM login_attempts WHERE ip=? AND attempted_at < NOW() - INTERVAL 1 DAY')->execute([$ip]); } catch (Throwable $e) {}
        
        // Check if force password reset is required
        $forceReset = false;
        try {
            $st = $pdo->prepare('SELECT force_password_reset FROM users WHERE id = ?');
            $st->execute([$userId]);
            $forceReset = (bool)$st->fetchColumn();
        } catch (Throwable $e) {}
        
        if ($forceReset) {
            header('Location: /?page=account&force=1');
            exit;
        }
        
        app_log('auth', 'login success', ['uid'=>$userId, 'ip'=>$ip]);
        header('Location: /');
        exit;
    } catch (Throwable $e) {
        header('Location: /?page=login&error=' . urlencode('Login failed'));
        exit;
    }
}

header('Location: /?page=login');
exit;
