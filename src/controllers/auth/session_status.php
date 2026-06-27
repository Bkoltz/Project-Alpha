<?php
// src/controllers/auth/session_status.php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$authenticated = !empty($_SESSION['user']['id']);

echo json_encode([
    'authenticated' => $authenticated,
    'user_id' => $authenticated ? (int)$_SESSION['user']['id'] : null,
]);
exit;
