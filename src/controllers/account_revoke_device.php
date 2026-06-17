<?php
// src/controllers/account_revoke_device.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /?page=account');
    exit;
}

// CSRF check
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    header('Location: /?page=account&error=csrf');
    exit;
}

$userId = (int)($_SESSION['user']['id'] ?? 0);
$deviceId = (int)($_POST['device_id'] ?? 0);

if ($userId > 0 && $deviceId > 0) {
    try {
        $st = $pdo->prepare('DELETE FROM trusted_devices WHERE id = ? AND user_id = ?');
        $st->execute([$deviceId, $userId]);
    } catch (Throwable $e) {
        // Ignore DB errors and proceed to redirect
    }
}

header('Location: /?page=account&revoked=1');
exit;
