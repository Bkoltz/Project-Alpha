<?php

declare(strict_types=1);

/**
 * Reset an active administrator through an operator-only CLI workflow.
 * Returns the one-time plaintext password; callers must print it only after
 * this function has committed successfully and must never log it.
 *
 * @throws RuntimeException
 */
function recover_admin_account(PDO $pdo, string $identifier, bool $resetTotp = false): string
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        throw new RuntimeException('An administrator username or email is required.');
    }

    $temporaryPassword = 'PA-' . bin2hex(random_bytes(18)) . '!Aa9';
    $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
    if (!is_string($passwordHash) || $passwordHash === '') {
        throw new RuntimeException('Could not hash the temporary password.');
    }

    try {
        $pdo->beginTransaction();

        $lookup = $pdo->prepare(
            'SELECT id, role, is_disabled, deleted_at
             FROM users
             WHERE email = ? OR username = ?
             ORDER BY id
             LIMIT 2
             FOR UPDATE'
        );
        $lookup->execute([$identifier, $identifier]);
        $matches = $lookup->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) !== 1) {
            throw new RuntimeException(count($matches) === 0 ? 'Administrator account not found.' : 'Administrator identifier is ambiguous.');
        }

        $user = $matches[0];
        $userId = (int)$user['id'];
        if ((string)$user['role'] !== 'admin') {
            throw new RuntimeException('Recovery is restricted to administrator accounts.');
        }
        if ((int)$user['is_disabled'] !== 0 || !empty($user['deleted_at'])) {
            throw new RuntimeException('The administrator account is inactive.');
        }

        $update = $pdo->prepare(
            'UPDATE users
             SET password_hash = ?, force_password_reset = 1,
                 auth_version = auth_version + 1,
                 totp_reenroll_required = CASE WHEN ? = 1 THEN 1 ELSE totp_reenroll_required END
             WHERE id = ?'
        );
        $update->execute([$passwordHash, $resetTotp ? 1 : 0, $userId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Administrator recovery update failed.');
        }

        $pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0')->execute([$userId]);
        $pdo->prepare('DELETE FROM trusted_devices WHERE user_id = ?')->execute([$userId]);

        if ($resetTotp) {
            $pdo->prepare('DELETE FROM user_2fa WHERE user_id = ?')->execute([$userId]);
        }

        $audit = $pdo->prepare(
            'INSERT INTO system_audit (user_id, action, entity_type, entity_id, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, NULL, ?)'
        );
        $audit->execute([$userId, 'admin.recovery_password_issued', 'user', $userId, json_encode(['source' => 'docker_cli']), 'admin-recovery.php']);
        if ($audit->rowCount() !== 1) {
            throw new RuntimeException('Could not record the recovery audit event.');
        }

        if ($resetTotp) {
            $audit->execute([$userId, 'admin.recovery_totp_reset', 'user', $userId, json_encode(['source' => 'docker_cli']), 'admin-recovery.php']);
            if ($audit->rowCount() !== 1) {
                throw new RuntimeException('Could not record the TOTP recovery audit event.');
            }
        }

        $pdo->commit();
        return $temporaryPassword;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error instanceof RuntimeException) {
            throw $error;
        }
        throw new RuntimeException('Administrator recovery failed.', 0, $error);
    }
}
