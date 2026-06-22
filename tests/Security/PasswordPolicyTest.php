<?php
require_once __DIR__ . '/../../src/utils/password_policy.php';

use PHPUnit\Framework\TestCase;

class PasswordPolicyTest extends TestCase
{
    public function testValidPassword(): void
    {
        $this->assertNull(password_policy_error('Valid1!Pass'));
    }

    public function testTooShort(): void
    {
        $this->assertNotNull(password_policy_error('Short1!'));
    }

    public function testMissingUppercase(): void
    {
        $this->assertNotNull(password_policy_error('valid1!pass'));
    }

    public function testMissingSpecial(): void
    {
        $this->assertNotNull(password_policy_error('Valid1Pass'));
    }
}
