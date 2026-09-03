#!/usr/bin/env php
<?php

declare(strict_types=1);

// Deliberately no autoload, database bootstrap, .env reader, or application service status.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (count($argv ?? []) !== 1) {
    fwrite(STDOUT, "{\"status\":\"invalid_arguments\"}\n");
    exit(2);
}
set_error_handler(static function (): never { throw new RuntimeException('diagnostic_failed'); });
try {
    require_once __DIR__ . '/../src/services/PortalEnvironmentReadiness.php';
    $report = \App\Services\PortalEnvironmentReadiness::report(static fn(string $key): string|false => getenv($key));
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
    // Zero means diagnostic ran, not that provisioning is ready.
    exit(0);
} catch (Throwable) {
    fwrite(STDOUT, "{\"status\":\"diagnostic_failed\"}\n");
    exit(1);
}
