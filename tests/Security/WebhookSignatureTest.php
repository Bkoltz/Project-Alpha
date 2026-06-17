<?php
require_once __DIR__ . '/../../src/utils/crypto.php';

use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private function buildStripeSignature(string $payload, string $secret): string
    {
        $timestamp = time();
        $signedPayload = $timestamp . '.' . $payload;
        $signature = hash_hmac('sha256', $signedPayload, $secret);
        return 't=' . $timestamp . ',v1=' . $signature;
    }

    public function testSignatureHeaderFormat(): void
    {
        $header = $this->buildStripeSignature('{"id":1}', 'whsec_test_secret');
        $this->assertStringContainsString('t=', $header);
        $this->assertStringContainsString('v1=', $header);
    }

    public function testCryptoRoundTrip(): void
    {
        $key = base64_encode(random_bytes(32));
        putenv('APP_ENCRYPTION_KEY=' . $key);

        $plaintext = 'sensitive webhook payload';
        $encrypted = crypto_encrypt($plaintext);
        $this->assertNotNull($encrypted);
        $this->assertStringStartsWith('enc::', $encrypted);

        $decrypted = crypto_decrypt($encrypted);
        $this->assertSame($plaintext, $decrypted);
    }
}
