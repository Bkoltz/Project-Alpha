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

function two_factor_required_for_user(PDO $pdo, int $userId, ?int $activeOrgId = null): bool
{
    if ($userId <= 0) {
        return false;
    }

    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        return true;
    }

    $activeOrgId = $activeOrgId ?: get_active_org_id();
    $privilegedPermissions = [
        'settings.manage',
        'users.manage',
        'users.reset_password',
        '2fa.manage',
        'invoices.mark_paid',
        'financial.manage',
    ];
    foreach ($privilegedPermissions as $permission) {
        if (user_can($pdo, $userId, $permission, $activeOrgId)) {
            return true;
        }
    }
    return false;
}

function two_factor_enforce_required(PDO $pdo, string $page): void
{
    if (empty($_SESSION['user']['id'])) {
        return;
    }

    $allowed = [
        '2fa-setup',
        '2fa-setup-action',
        '2fa-verify',
        '2fa-verify-action',
        'account',
        'account-update',
        'logout',
        'logout-confirm',
        'session-status',
    ];
    if (in_array($page, $allowed, true)) {
        return;
    }

    $userId = (int)$_SESSION['user']['id'];
    if (two_factor_required_for_user($pdo, $userId) && !two_factor_enabled_for_user($pdo, $userId)) {
        header('Location: /?page=2fa-setup&required=1');
        exit;
    }
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
    return two_factor_required_for_user($pdo, $userId) && !two_factor_enabled_for_user($pdo, $userId);
}
