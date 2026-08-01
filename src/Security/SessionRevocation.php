<?php

declare(strict_types=1);

namespace App\Security;

use PDO;

final class SessionRevocation
{
    public static function revokeUserSessions(PDO $pdo, int $userId, ?string $exceptSessionId = null): int
    {
        if ($userId < 1) {
            return 0;
        }
        $sql = 'UPDATE app_sessions
                SET user_id=NULL,payload=?,revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP(6))
                WHERE user_id=? AND revoked_at IS NULL';
        $params = ['', $userId];
        if ($exceptSessionId !== null && $exceptSessionId !== '') {
            $sql .= ' AND session_hash<>?';
            $params[] = hash('sha256', $exceptSessionId);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
