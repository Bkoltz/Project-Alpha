<?php

declare(strict_types=1);

/**
 * Lock administrator rows and refuse a transition that would leave PA without
 * an active administrator. Call this from inside the caller's transaction.
 *
 * @throws DomainException
 */
function assert_not_removing_final_active_admin(PDO $pdo, int $userId, bool $willRemainActiveAdmin): void
{
    $target = $pdo->prepare('SELECT role, is_disabled, deleted_at FROM users WHERE id = ? FOR UPDATE');
    $target->execute([$userId]);
    $user = $target->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new DomainException('User not found.');
    }

    $isActiveAdmin = (string)$user['role'] === 'admin'
        && (int)$user['is_disabled'] === 0
        && empty($user['deleted_at']);
    if (!$isActiveAdmin || $willRemainActiveAdmin) {
        return;
    }

    $active = $pdo->query(
        "SELECT id FROM users
         WHERE role = 'admin' AND is_disabled = 0 AND deleted_at IS NULL
         ORDER BY id FOR UPDATE"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (count($active) <= 1) {
        throw new DomainException('The final active administrator cannot be deleted, disabled, or demoted.');
    }
}
