<?php
// src/controllers/auth/two_factor_warning_dismiss.php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['user'])) {
    header('Location: /?page=login');
    exit;
}

$_SESSION['two_factor_warning_dismissed'] = 1;

$returnTo = (string)($_POST['return_to'] ?? '/');
if ($returnTo === '' || !str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
    $returnTo = '/';
}

header('Location: ' . $returnTo);
exit;
