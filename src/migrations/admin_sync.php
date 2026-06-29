<?php

declare(strict_types=1);

require_once __DIR__ . '/migration_lib.php';

/**
 * Reconcile the Compose-controlled administrator without exposing credentials.
 * Returns created, updated, unchanged, or skipped for operator-safe logging.
 */
function sync_compose_admin(PDO $pdo, array $environment): string
{
    $email = trim((string) ($environment['ADMIN_EMAIL'] ?? 'admin@project-alpha.local'));
    $username = trim((string) ($environment['ADMIN_USERNAME'] ?? 'admin'));
    $password = (string) ($environment['ADMIN_PASSWORD'] ?? '');

    if ($email === '' || $username === '') {
        throw new RuntimeException('ADMIN_EMAIL and ADMIN_USERNAME cannot be empty.');
    }

    $find = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ? LIMIT 1');
    $find->execute([$email]);
    $admin = $find->fetch(PDO::FETCH_ASSOC);

    if (!$admin && $password === '') {
        throw new RuntimeException('ADMIN_PASSWORD is required for a fresh installation.');
    }

    $status = 'skipped';
    if (!$admin) {
        $insert = $pdo->prepare(
            "INSERT INTO users (email, password_hash, username, role, force_password_reset)
             VALUES (?, ?, ?, 'admin', 0)"
        );
        $insert->execute([$email, password_hash($password, PASSWORD_DEFAULT), $username]);
        $adminId = (int) $pdo->lastInsertId();
        $status = 'created';
    } else {
        $adminId = (int) $admin['id'];
        if ($password !== '' && !password_verify($password, (string) $admin['password_hash'])) {
            $update = $pdo->prepare(
                "UPDATE users
                 SET password_hash = ?, username = ?, role = 'admin', force_password_reset = 0, deleted_at = NULL
                 WHERE id = ?"
            );
            $update->execute([password_hash($password, PASSWORD_DEFAULT), $username, $adminId]);
            $status = 'updated';
        } elseif ($password !== '') {
            $status = 'unchanged';
        }
    }

    $organizationId = (int) $pdo->query('SELECT id FROM organizations ORDER BY id LIMIT 1')->fetchColumn();
    if ($organizationId < 1) {
        throw new RuntimeException('No organization exists for the administrator membership.');
    }
    $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'owner' AND is_system = 1 LIMIT 1");
    $roleStmt->execute();
    $roleId = $roleStmt->fetchColumn();

    $membership = $pdo->prepare(
        "INSERT INTO user_organizations (user_id, organization_id, role, role_id, is_default)
         VALUES (?, ?, 'owner', ?, 1)
         ON DUPLICATE KEY UPDATE role = 'owner', role_id = VALUES(role_id), is_default = 1"
    );
    $membership->execute([$adminId, $organizationId, $roleId !== false ? (int) $roleId : null]);

    return $status;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $status = sync_compose_admin(migration_connection(), $_ENV + getenv());
        fwrite(STDOUT, "Administrator synchronization: $status.\n");
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, 'Administrator synchronization failed: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
