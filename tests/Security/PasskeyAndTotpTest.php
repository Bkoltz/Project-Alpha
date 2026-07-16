<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/src/utils/two_factor_auth.php';
require_once dirname(__DIR__, 2) . '/src/utils/passkey_auth.php';

use App\Utils\PasskeyException;
use App\Utils\PasskeyService;
use App\Utils\TwoFactorAuth;
use PHPUnit\Framework\TestCase;

final class PasskeyAndTotpTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        putenv('WEBAUTHN_ORIGIN');
        putenv('WEBAUTHN_RP_ID');
    }

    protected function tearDown(): void
    {
        putenv('WEBAUTHN_ORIGIN');
        putenv('WEBAUTHN_RP_ID');
    }

    public function testTotpEnrollmentProducesLocalSvgForCanonicalOtpUri(): void
    {
        $uri = TwoFactorAuth::getOtpAuthUri('JBSWY3DPEHPK3PXP', 'person@example.test', 'Project Alpha');
        self::assertStringStartsWith('otpauth://totp/Project%20Alpha%3Aperson%40example.test?', $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertStringContainsString('issuer=Project+Alpha', $uri);

        $svg = TwoFactorAuth::getQrCodeSvg($uri, 220);
        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('viewBox="0 0 220 220"', $svg);
        self::assertStringNotContainsString('http://api.', $svg);
        self::assertStringStartsWith('data:image/svg+xml;base64,', TwoFactorAuth::getQrCodeDataUri($uri, 220));
    }

    public function testQrRendererRejectsNonAuthenticatorUris(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TwoFactorAuth::getQrCodeSvg('https://example.test/secret');
    }

    public function testPasskeysRequireExplicitCanonicalOriginAndNeverRequestHost(): void
    {
        $pdo = new PDO('sqlite::memory:');
        try {
            new PasskeyService($pdo, []);
            self::fail('Missing canonical origin should fail closed.');
        } catch (PasskeyException $e) {
            self::assertSame('passkey_not_configured', $e->errorCode);
        }

        $service = new PasskeyService($pdo, ['app_host' => 'https://pa.example.test', 'brand_name' => 'PA']);
        self::assertInstanceOf(PasskeyService::class, $service);

        $source = file_get_contents($this->root . '/src/utils/passkey_auth.php');
        self::assertIsString($source);
        self::assertStringNotContainsString("HTTP_HOST", $source);
        self::assertStringContainsString("setAllowedOrigins([\$this->origin]", $source);
    }

    public function testApplicationDomainFallbackDerivesTheBrowserFacingOrigin(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $bareDomain = new PasskeyService($pdo, ['app_host' => 'pa.example.test']);
        $proxiedPath = new PasskeyService($pdo, ['app_host' => 'https://pa.example.test/project-alpha']);
        $localDevelopment = new PasskeyService($pdo, ['app_host' => 'localhost:1627']);
        $secureLocalDevelopment = new PasskeyService($pdo, ['app_host' => 'https://localhost:1627']);

        $origin = new ReflectionProperty(PasskeyService::class, 'origin');
        self::assertSame('https://pa.example.test', $origin->getValue($bareDomain));
        self::assertSame('https://pa.example.test', $origin->getValue($proxiedPath));
        self::assertSame('http://localhost:1627', $origin->getValue($localDevelopment));
        self::assertSame('https://localhost:1627', $origin->getValue($secureLocalDevelopment));
    }

    public function testExplicitWebauthnOriginRemainsStrict(): void
    {
        putenv('WEBAUTHN_ORIGIN=https://pa.example.test/project-alpha');

        try {
            new PasskeyService(new PDO('sqlite::memory:'), ['app_host' => 'pa.example.test']);
            self::fail('An explicit WebAuthn origin with a path must fail closed.');
        } catch (PasskeyException $e) {
            self::assertSame('passkey_origin_invalid', $e->errorCode);
        }
    }

    public function testPasskeySchemaAndCeremonyGuardrailsArePresent(): void
    {
        $migration = $this->read('database/migrations/0046_passkeys_and_totp_recovery.sql');
        foreach (['passkey_credentials', 'passkey_challenges', 'challenge_hash BINARY(32)', 'session_hash BINARY(32)', 'consumed_at', 'revoked_at', 'DROP COLUMN backup_codes'] as $expected) {
            self::assertStringContainsString($expected, $migration);
        }

        $service = $this->read('src/utils/passkey_auth.php');
        self::assertStringContainsString('RESIDENT_KEY_REQUIREMENT_REQUIRED', $service);
        self::assertStringContainsString('USER_VERIFICATION_REQUIREMENT_REQUIRED', $service);
        self::assertStringContainsString('ATTESTATION_CONVEYANCE_PREFERENCE_NONE', $service);
        self::assertStringContainsString("hash('sha256', session_id(), true)", $service);
        self::assertStringContainsString('FOR UPDATE', $service);
        self::assertStringContainsString('signature_counter=?', $service);
    }

    public function testBackupCodeApplicationPathsAreRemoved(): void
    {
        $paths = [
            'src/utils/two_factor_auth.php',
            'src/controllers/auth/two_factor_setup.php',
            'src/controllers/auth/two_factor_verify.php',
            'src/views/pages/auth/two_factor_setup.php',
            'src/views/pages/auth/two_factor_verify.php',
        ];
        foreach ($paths as $path) {
            $contents = strtolower($this->read($path));
            self::assertStringNotContainsString('generatebackupcodes', $contents, $path);
            self::assertStringNotContainsString('verifybackupcode', $contents, $path);
            self::assertStringNotContainsString('use_backup', $contents, $path);
            self::assertStringNotContainsString('regenerate_backup', $contents, $path);
        }
    }

    public function testBrowserFlowSerializesRequiredWebauthnFields(): void
    {
        $javascript = $this->read('public/assets/passkeys.js');
        foreach (['clientDataJSON', 'attestationObject', 'authenticatorData', 'signature', 'userHandle', 'isConditionalMediationAvailable'] as $field) {
            self::assertStringContainsString($field, $javascript);
        }
        self::assertStringContainsString("credentials: 'same-origin'", $javascript);
    }

    private function read(string $relative): string
    {
        $contents = file_get_contents($this->root . '/' . $relative);
        self::assertIsString($contents, $relative);
        return $contents;
    }
}
