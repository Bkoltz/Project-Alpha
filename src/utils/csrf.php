<?php
// src/utils/csrf.php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function csrf_init(): void {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function csrf_token(): string {
    csrf_init();
    return (string)$_SESSION['csrf'];
}

function csrf_verify_post_or_redirect(string $page): void {
    $token = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        $err = rawurlencode('Invalid request (CSRF)');
        $redir = '/?page=' . rawurlencode($page) . '&error=' . $err;
        header('Location: ' . $redir);
        exit;
    }
}