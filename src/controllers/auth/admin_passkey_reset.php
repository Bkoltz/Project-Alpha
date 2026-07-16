<?php

declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';

$actorId = (int)($_SESSION['user']['id'] ?? 0);
if ($actorId < 1 || !user_can($pdo, $actorId, '2fa.manage')) {
    header('Location: /?page=login');
    exit;
}
$token = (string)($_POST['csrf'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $token)) {
    header('Location: /?page=accounts&error=' . urlencode('Invalid request'));
    exit;
}
$userId = (int)($_POST['user_id'] ?? 0);
if ($userId < 1) {
    header('Location: /?page=accounts&error=' . urlencode('Invalid user ID'));
    exit;
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('UPDATE passkey_credentials SET revoked_at=UTC_TIMESTAMP(),revoked_by=? WHERE user_id=? AND revoked_at IS NULL');
    $stmt->execute([$actorId, $userId]);
    $revoked = $stmt->rowCount();
    $pdo->prepare('UPDATE users SET auth_version=auth_version+1 WHERE id=?')->execute([$userId]);
    audit_log($pdo, 'auth.passkeys_admin_reset', 'user', $userId, ['revoked_count' => $revoked], $actorId);
    $pdo->commit();
    header('Location: /?page=account-edit&id=' . $userId . '&success=passkeys_reset');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    header('Location: /?page=account-edit&id=' . $userId . '&error=' . urlencode('Failed to reset passkeys'));
}
exit;
