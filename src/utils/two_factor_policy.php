<?php

require_once __DIR__ . '/acl.php';

function two_factor_enabled_for_user(PDO $pdo, int $userId): bool
{
    try {
        $stmt = $pdo->prepare('SELECT enabled FROM user_2fa WHERE user_id = ? AND enabled = 1 LIMIT 1');
        $stmt->execute([$userId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function two_factor_recommended_for_user(PDO $pdo, int $userId, ?int $organizationId = null): bool
{
    if ($userId <= 0) {
        return false;
    }

    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        return true;
    }

    $privilegedPermissions = [
        'settings.manage',
        'users.manage',
        'users.reset_password',
        '2fa.manage',
        'invoices.mark_paid',
        'financial.manage',
        'approvals.review',
        'workforce.manage',
        'employee_pay.view',
        'employee_pay.manage',
    ];
    foreach ($privilegedPermissions as $permission) {
        if (user_can($pdo, $userId, $permission, 0)) {
            return true;
        }
    }
    return false;
}

function two_factor_warning_needed(PDO $pdo, string $page): bool
{
    if (empty($_SESSION['user']['id']) || !empty($_SESSION['two_factor_warning_dismissed'])) {
        return false;
    }

    $quietPages = [
        '2fa-setup',
        '2fa-setup-action',
        '2fa-verify',
        '2fa-verify-action',
        '2fa-warning-dismiss',
        'logout',
        'logout-confirm',
        'session-status',
    ];
    if (in_array($page, $quietPages, true)) {
        return false;
    }

    $userId = (int)$_SESSION['user']['id'];
    return two_factor_recommended_for_user($pdo, $userId) && !two_factor_enabled_for_user($pdo, $userId);
}
