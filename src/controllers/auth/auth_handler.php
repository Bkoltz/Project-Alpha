<?php
// src/controllers/auth/auth_handler.php
// Handles login and first-admin registration with CSRF verification

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
if (!csrf_sf_is_valid('auth', is_string($submitted) ? $submitted : '')) {
    header('Location: /?page=login&error=' . urlencode('Invalid request (CSRF)'));
    exit;
}

$action = $_POST['action'] ?? '';
$emailOrUsername = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

function valid_email($e) { return filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }

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
        // Check if user has 2FA enabled
        $twofa_enabled = false;
        try {
            $st2fa = $pdo->prepare('SELECT enabled FROM user_2fa WHERE user_id = ? AND enabled = 1');
            $st2fa->execute([(int)$u['id']]);
            $twofa_enabled = (bool)$st2fa->fetchColumn();
        } catch (Throwable $e) {}
        
        if ($twofa_enabled) {
            // User has 2FA enabled, redirect to 2FA verification
            session_regenerate_id(true);
            $_SESSION['2fa_pending'] = [
                'user_id' => (int)$u['id'],
                'user_data' => ['id'=>(int)$u['id'], 'email'=>$u['email'], 'role'=>$u['role']]
            ];
            app_log('auth', 'login requires 2FA', ['user_id'=>(int)$u['id'], 'ip'=>$ip]);
            header('Location: /?page=2fa-verify');
            exit;
        }
        
        // on success (no 2FA), regenerate session and optionally clear attempts
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>(int)$u['id'], 'email'=>$u['email'], 'role'=>$u['role']];
        try { $pdo->prepare('DELETE FROM login_attempts WHERE ip=? AND attempted_at < NOW() - INTERVAL 1 DAY')->execute([$ip]); } catch (Throwable $e) {}
        // Remember-me flow is intentionally disabled. Below is the implementation
        // kept as a comment for future use.
        /*
        if (!empty($_POST['remember'])) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
            $uid = (int)$u['id'];
            $exp = time() + 60*60*24*30;
            $key = crypto_get_key();
            if ($key !== '') {
                $data = $uid . '|' . $exp;
                $hmac = base64_encode(hash_hmac('sha256', $data, $key, true));
                $val = $data . '|' . $hmac;
                setcookie('remember', $val, [
                    'expires' => $exp,
                    'path' => '/',
                    'domain' => '',
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        }
        */
        
        // Check if force password reset is required
        $forceReset = false;
        try {
            $st = $pdo->prepare('SELECT force_password_reset FROM users WHERE id = ?');
            $st->execute([(int)$u['id']]);
            $forceReset = (bool)$st->fetchColumn();
        } catch (Throwable $e) {}
        
        if ($forceReset) {
            header('Location: /?page=account&force=1');
            exit;
        }
        
        app_log('auth', 'login success', ['uid'=>(int)$u['id'], 'ip'=>$ip]);
        header('Location: /');
        exit;
    } catch (Throwable $e) {
        header('Location: /?page=login&error=' . urlencode('Login failed'));
        exit;
    }
}

header('Location: /?page=login');
exit;
