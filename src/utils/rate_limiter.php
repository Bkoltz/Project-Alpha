<?php
/**
 * Rate limiting helper for public link access.
 *
 * Tracks requests per IP/key combination in a sliding window. On DB failure
 * it degrades gracefully and allows the request through so public links are
 * never hard-broken by transient DB issues.
 *
 * @param PDO $pdo Database connection
 * @param string $key Logical action key (e.g. 'public_doc')
 * @param int $maxAttempts Maximum allowed attempts in the window
 * @param int $windowSeconds Window length in seconds
 * @return bool True if the request is allowed, false if rate limited
 */
function rate_limit_check(PDO $pdo, string $key, int $maxAttempts = 10, int $windowSeconds = 60): bool
{
    $identifier = ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') . ':' . $key;
    $ipAddress  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    try {
        // Prune old entries outside the current window
        $cleanup = $pdo->prepare('DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)');
        $cleanup->execute([$windowSeconds]);

        // Count recent attempts for this identifier
        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM rate_limits WHERE identifier = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $countStmt->execute([$identifier, $windowSeconds]);
        $attempts = (int) $countStmt->fetchColumn();

        if ($attempts >= $maxAttempts) {
            return false;
        }

        // Record this attempt
        $insertStmt = $pdo->prepare(
            'INSERT INTO rate_limits (identifier, ip_address, created_at) VALUES (?, ?, NOW())'
        );
        $insertStmt->execute([$identifier, $ipAddress]);

        return true;
    } catch (Throwable $e) {
        // Graceful degradation: allow request if rate-limit data is unavailable
        return true;
    }
}
