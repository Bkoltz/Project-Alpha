#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/migrations/migration_lib.php';
require_once __DIR__ . '/../src/utils/admin_recovery.php';

$arguments = array_slice($argv ?? [], 1);
$resetTotp = false;
$identifiers = [];
foreach ($arguments as $argument) {
    if ($argument === '--reset-totp') {
        $resetTotp = true;
    } elseif (str_starts_with($argument, '--')) {
        fwrite(STDERR, "Unknown option: {$argument}\n");
        exit(2);
    } else {
        $identifiers[] = $argument;
    }
}

if (count($identifiers) !== 1) {
    fwrite(STDERR, "Usage: php bin/admin-recovery.php <admin-username-or-email> [--reset-totp]\n");
    exit(2);
}

try {
    $password = recover_admin_account(migration_connection(), $identifiers[0], $resetTotp);
    fwrite(STDOUT, "Temporary password: {$password}\n");
    fwrite(STDOUT, "The administrator must change this password at the next login.\n");
    if ($resetTotp) {
        fwrite(STDOUT, "TOTP was reset and must be enrolled again after the password change.\n");
    }
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Recovery failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
