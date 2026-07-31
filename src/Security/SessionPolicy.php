<?php

declare(strict_types=1);

namespace App\Security;

final class SessionPolicy
{
    public const IDLE_SECONDS = 900;
    public const ABSOLUTE_SECONDS = 604800;

    public static function completeAuthentication(string $method, ?int $now = null): void
    {
        $now ??= time();
        $_SESSION['authn'] = [
            'method' => $method,
            'authenticated_at' => $now,
            'absolute_expires_at' => $now + self::ABSOLUTE_SECONDS,
        ];
        $_SESSION['last_activity'] = $now;
    }

    public static function authenticationDeadline(?int $now = null): int
    {
        $now ??= time();
        $authenticatedAt = (int)($_SESSION['authn']['authenticated_at'] ?? 0);
        $deadline = (int)($_SESSION['authn']['absolute_expires_at'] ?? 0);
        if ($deadline > 0) {
            return $deadline;
        }
        if ($authenticatedAt > 0) {
            return $authenticatedAt + self::ABSOLUTE_SECONDS;
        }
        return $now + self::ABSOLUTE_SECONDS;
    }

    /**
     * Background/status traffic is deliberately passive. Normal navigations
     * and user-triggered browser actions remain active by default.
     *
     * @param array<string,mixed> $query
     */
    public static function isIntentionalActivity(string $page, array $query = []): bool
    {
        if ($page === 'session-status') {
            return false;
        }
        if ($page === 'financial/mileage-tracking-api'
            && in_array((string)($query['action'] ?? ''), ['status', 'points'], true)) {
            return false;
        }
        return true;
    }

    /** Preserve the authentication anchor and invalidate the old identifier. */
    public static function rotateAuthenticatedId(): bool
    {
        $hasAuthenticationAnchor = (int)($_SESSION['authn']['absolute_expires_at'] ?? 0) > 0
            || (int)($_SESSION['authn']['authenticated_at'] ?? 0) > 0;
        $deadline = $hasAuthenticationAnchor ? self::authenticationDeadline() : null;
        $result = session_regenerate_id(true);
        if ($result && $deadline !== null) {
            $_SESSION['authn']['absolute_expires_at'] = $deadline;
        }
        return $result;
    }
}
