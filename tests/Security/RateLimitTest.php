<?php
require_once __DIR__ . '/../../src/utils/rate_limiter.php';

use PHPUnit\Framework\TestCase;

/**
 * Tests rate_limit_check() against the real MySQL backend.
 *
 * rate_limiter.php relies on MySQL-specific DATE_SUB(NOW(), INTERVAL ? SECOND)
 * syntax, so it can only be meaningfully exercised against MySQL (not SQLite).
 * When MySQL is unavailable (e.g. running outside the Docker network) the test
 * skips cleanly instead of producing a false failure.
 */
class RateLimitTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $key;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST') ?: 'db';
        $db   = getenv('MYSQL_DATABASE') ?: 'project_alpha';
        $user = getenv('MYSQL_USER') ?: 'root';
        $pass = getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: 'rootpass';
        $dsn  = "mysql:host={$host};dbname={$db};charset=utf8mb4";

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
            ]);
            // Ensure the table exists (created by migration 017).
            $this->pdo->query('SELECT 1 FROM rate_limits LIMIT 1');
        } catch (Throwable $e) {
            $this->markTestSkipped('MySQL rate_limits backend unavailable: ' . $e->getMessage());
        }

        // Unique IP + key per run so prior rows never pollute the window.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(1, 254);
        $this->key = 'phpunit_' . bin2hex(random_bytes(4));
    }

    public function testRateLimitFirstCallAllowed(): void
    {
        $result = rate_limit_check($this->pdo, $this->key, 10, 60);
        $this->assertTrue($result, 'First call should be allowed');
    }

    public function testRateLimitBlocksAfterMaxAttempts(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue(
                rate_limit_check($this->pdo, $this->key, 10, 60),
                "Call #" . ($i + 1) . " should be allowed (under limit)"
            );
        }
        $this->assertFalse(
            rate_limit_check($this->pdo, $this->key, 10, 60),
            'Call #11 should be blocked (limit exceeded)'
        );
    }
}
