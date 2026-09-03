<?php

declare(strict_types=1);

use App\Services\PortalEnvironmentReadiness;
use PHPUnit\Framework\TestCase;

final class PortalEnvironmentReadinessTest extends TestCase
{
    public function testAbsentEnvironmentDoesNotClaimDatabaseOrPortalFailure(): void
    {
        $report = PortalEnvironmentReadiness::report(static fn(string $key): false => false);
        self::assertFalse($report['receiver_override_present']);
        self::assertFalse($report['direct_signing_secret_present']);
        self::assertSame('unknown_not_checked', $report['portal_activation_status']);
        self::assertSame('unknown_not_checked', $report['database_configuration_status']);
    }

    public function testOnlyFixedKeysAndBooleansOrFixedCodesAreEmitted(): void
    {
        $private = 'PRIVATE_CLIENT_NAME_EMAIL_URL_KEY_SENTINEL';
        $env = [
            'EXTERNAL_OPS_CLIENT_PORTAL_BASE_URL' => 'https://private-receiver.example',
            'EXTERNAL_OPS_CLIENT_PORTAL_SIGNING_KEY_ID' => $private,
            'EXTERNAL_OPS_CLIENT_PORTAL_SIGNING_SECRET' => str_repeat($private, 2),
            'PORTAL_INTEGRATION_HMAC_SECRETS_JSON' => json_encode([$private => ['portal' => $private]]),
        ];
        $empty = PortalEnvironmentReadiness::report(static fn(string $key): false => false);
        $report = PortalEnvironmentReadiness::report(static fn(string $key): string|false => $env[$key] ?? false);
        self::assertSame(array_keys($empty), array_keys($report));
        foreach ($report as $value) {
            self::assertTrue(is_bool($value) || in_array($value, ['environment_only', 'unknown_not_checked'], true));
        }
        self::assertStringNotContainsString($private, json_encode($report));
        self::assertStringNotContainsString('private-receiver', json_encode($report));
        self::assertTrue($report['receiver_override_https_valid']);
        self::assertTrue($report['direct_signing_secret_length_valid']);
        self::assertSame('unknown_not_checked', $report['receiver_key_match_status']);
    }

    public function testMalformedValuesAreRedactedAndRejected(): void
    {
        $env = [
            'EXTERNAL_OPS_CLIENT_PORTAL_BASE_URL' => 'https://secret:password@example.test/?token=hidden',
            'EXTERNAL_OPS_CLIENT_PORTAL_SIGNING_KEY_ID' => "invalid\nprivate",
            'EXTERNAL_OPS_CLIENT_PORTAL_SIGNING_SECRET' => str_repeat('s', 1001),
            'PORTAL_INTEGRATION_HMAC_SECRETS_JSON' => '{private-invalid',
        ];
        $report = PortalEnvironmentReadiness::report(static fn(string $key): string|false => $env[$key] ?? false);
        self::assertFalse($report['receiver_override_https_valid']);
        self::assertFalse($report['direct_signing_key_shape_valid']);
        self::assertFalse($report['direct_signing_secret_length_valid']);
        self::assertFalse($report['signing_map_json_object_valid']);
        self::assertStringNotContainsString('private', json_encode($report));
    }

    public function testSigningMapValidityIsNotClaimedForListOrScalar(): void
    {
        foreach (['[]', 'null', '42', '"private"'] as $json) {
            $report = PortalEnvironmentReadiness::report(static fn(string $key): string|false => $key === 'PORTAL_INTEGRATION_HMAC_SECRETS_JSON' ? $json : false);
            self::assertFalse($report['signing_map_json_object_valid']);
        }
    }

    public function testCliHasNoAppBootstrapDatabaseOrNetworkDependency(): void
    {
        $cli = file_get_contents(dirname(__DIR__, 2) . '/bin/portal-readiness.php');
        self::assertStringNotContainsString('vendor/autoload', $cli);
        self::assertStringNotContainsString('config/db.php', $cli);
        self::assertStringNotContainsString('getMessage', $cli);
        $service = file_get_contents(dirname(__DIR__, 2) . '/src/services/PortalEnvironmentReadiness.php');
        foreach (['PDO', 'curl_', 'file_get_contents', 'file_put_contents', 'putenv', 'shell_exec'] as $unsafe) {
            self::assertStringNotContainsString($unsafe, $service);
        }
    }

    public function testDiagnosticIsIncludedInTheCronImage(): void
    {
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 2) . '/Dockerfile');
        $cron = explode('FROM php:8.5-cli AS cron', $dockerfile, 2)[1] ?? '';
        self::assertStringContainsString('COPY ./bin/portal-readiness.php /var/www/bin/portal-readiness.php', $cron);
        self::assertStringContainsString('COPY ./src/ /var/www/src/', $cron);
    }

    public function testCliRejectsArgumentsWithoutEchoingThem(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/portal-readiness.php', 'private-secret@example.test'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(2, proc_close($process));
        self::assertSame("{\"status\":\"invalid_arguments\"}\n", $stdout);
        self::assertSame('', $stderr);
    }
}
