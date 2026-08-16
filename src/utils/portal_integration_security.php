<?php

declare(strict_types=1);

/** Secrets are deployment configuration. No application key has a built-in value. */
function portal_integration_hmac_secret(string $applicationKey, string $capability): string
{
    $raw = getenv('PORTAL_INTEGRATION_HMAC_SECRETS_JSON');
    if (!is_string($raw) || trim($raw) === '') return '';
    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return '';
    }
    if (!is_array($decoded) || !isset($decoded[$applicationKey]) || !is_array($decoded[$applicationKey])) return '';
    $secret = $decoded[$applicationKey][$capability] ?? '';
    return is_string($secret) ? $secret : '';
}

function portal_integration_flag_enabled(string $name): bool
{
    return getenv($name) === 'true';
}

function portal_integration_failure(int $status, string $code): never
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    http_response_code($status);
    echo json_encode(['code' => $code], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
