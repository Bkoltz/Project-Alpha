<?php

declare(strict_types=1);

namespace App\Security;

final class AccountSessionPolicy
{
    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public static function requiresGlobalRevocation(array $before, array $after, bool $permissionsChanged): bool
    {
        foreach (['email', 'username', 'role', 'is_disabled', 'force_password_reset'] as $field) {
            if ((string)($before[$field] ?? '') !== (string)($after[$field] ?? '')) {
                return true;
            }
        }
        return $permissionsChanged;
    }
}
