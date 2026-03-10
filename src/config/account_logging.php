<?php 
// Handle logout early
if ($page === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
    }
    // Clear remember-me cookie
    setcookie('remember', '', time() - 3600, '/', '', $secure, true);
    session_destroy();
    header('Location: /?page=login');
    exit;
}

// Attempt remember-me auto login before enforcing auth (temporarily disabled)
if (false && empty($_SESSION['user']) && isset($_COOKIE['remember'])) {
    require_once __DIR__ . '/../src/utils/crypto.php';
    require_once __DIR__ . '/../src/config/db.php';
    $raw = (string)$_COOKIE['remember'];
    $parts = explode('|', $raw);
    if (count($parts) === 3) {
        [$uidStr, $expStr, $hmacB64] = $parts;
        $uid = (int)$uidStr;
        $exp = (int)$expStr;
        $key = crypto_get_key();
        if ($uid > 0 && $exp > time() && $key !== '') {
            $data = $uid . '|' . $exp;
            $calc = base64_encode(hash_hmac('sha256', $data, $key, true));
            if (hash_equals($calc, $hmacB64)) {
                try {
                    $st = $pdo->prepare('SELECT id, email, role FROM users WHERE id=?');
                    $st->execute([$uid]);
                    $u = $st->fetch(PDO::FETCH_ASSOC);
                    if ($u) {
                        $_SESSION['user'] = ['id' => (int)$u['id'], 'email' => $u['email'], 'role' => $u['role']];
                    }
                } catch (Throwable $e) { /* ignore */
                }
            }
        }
    }
}
?>