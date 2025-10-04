<?php
// src/controllers/auth_handler.php
// Handles login and first-admin registration with CSRF verification

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

// CSRF check
$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
    header('Location: /?page=login&error=' . urlencode('Invalid request (CSRF)'));
    exit;
}

$action = $_POST['action'] ?? '';
$email = trim((string)($_POST['email'] ?? ''));
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
    if (!valid_email($email)) {
        header('Location: /?page=login&error=' . urlencode('Enter a valid email'));
        exit;
    }
    if (strlen($password) < 8) {
        header('Location: /?page=login&error=' . urlencode('Password must be at least 8 characters'));
        exit;
    }
    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $st = $pdo->prepare('INSERT INTO users (email, password_hash, role) VALUES (?,?,?)');
        $st->execute([$email, $hash, 'admin']);
        $uid = (int)$pdo->lastInsertId();
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>$uid, 'email'=>$email, 'role'=>'admin'];
        header('Location: /');
        exit;
    } catch (Throwable $e) {
        header('Location: /?page=login&error=' . urlencode('Failed to create admin'));
        exit;
    }
}

if ($action === 'login') {
    if (!valid_email($email)) {
        // record attempt
        try { $pdo->prepare('INSERT INTO login_attempts (ip, email) VALUES (?,?)')->execute([$ip, $email ?: null]); } catch (Throwable $e) {}
        header('Location: /?page=login&error=' . urlencode('Invalid credentials'));
        exit;
    }
    try {
        $st = $pdo->prepare('SELECT id, email, password_hash, role FROM users WHERE email=?');
        $st->execute([$email]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            try { $pdo->prepare('INSERT INTO login_attempts (ip, email) VALUES (?,?)')->execute([$ip, $email ?: null]); } catch (Throwable $e) {}
            header('Location: /?page=login&error=' . urlencode('Invalid credentials'));
            exit;
        }
        // on success, regenerate session and optionally clear attempts
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>(int)$u['id'], 'email'=>$u['email'], 'role'=>$u['role']];
        try { $pdo->prepare('DELETE FROM login_attempts WHERE ip=? AND attempted_at < NOW() - INTERVAL 1 DAY')->execute([$ip]); } catch (Throwable $e) {}
        header('Location: /');
        exit;
    } catch (Throwable $e) {
        header('Location: /?page=login&error=' . urlencode('Login failed'));
        exit;
    }
}

header('Location: /?page=login');
exit;
