<?php
// src/utils/password_policy.php
// SOC 2 password complexity policy.
// Requirements: min 8 chars, at least one uppercase, one lowercase, one digit, one special character.

/**
 * Validate a password against the complexity policy.
 *
 * @return string|null Error message if invalid, null if the password passes.
 */
function password_policy_error(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must contain at least one uppercase letter';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must contain at least one lowercase letter';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must contain at least one special character';
    }
    return null;
}
