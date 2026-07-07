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

    public function testCleanStripeWebhookPathIsRewrittenToFrontController(): void
    {
        $root = dirname(__DIR__, 2);
        $htaccess = file_get_contents($root . '/public/.htaccess');

        $this->assertIsString($htaccess);
        $this->assertStringContainsString(
            'RewriteRule ^stripe-webhook/?$ index.php?page=stripe-webhook [QSA,L]',
            $htaccess
        );
        $this->assertStringContainsString(
            'RewriteRule ^stripe-webhook-legacy/?$ index.php?page=stripe-webhook-legacy [QSA,L]',
            $htaccess
        );
    }

    public function testApacheStartupAllowsHtaccessRouting(): void
    {
        $root = dirname(__DIR__, 2);
        $startScript = file_get_contents($root . '/docker/start.sh');

        $this->assertIsString($startScript);
        $this->assertStringContainsString('conf-available/project-alpha-routing.conf', $startScript);
        $this->assertStringContainsString('AllowOverride All', $startScript);
        $this->assertStringContainsString('a2enmod rewrite', $startScript);
    }
}
