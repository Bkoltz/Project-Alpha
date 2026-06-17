<?php
require_once __DIR__ . '/../../src/utils/csrf_sf.php';

use PHPUnit\Framework\TestCase;

class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function testGenerateAndValidateToken(): void
    {
        $token = csrf_sf_token('test');
        $this->assertNotEmpty($token);
        $this->assertIsString($token);
        $this->assertTrue(csrf_sf_is_valid('test', $token));
    }

    public function testInvalidToken(): void
    {
        $this->assertFalse(csrf_sf_is_valid('test', 'bogus'));
    }
}
