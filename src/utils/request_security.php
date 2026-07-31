<?php

declare(strict_types=1);

require_once __DIR__ . '/client_ip.php';

/**
 * Determine whether the browser-facing request is HTTPS.
 *
 * Forwarding headers are accepted only from the configured/trusted immediate
 * proxy. Ambiguous or malformed values fail closed instead of making cookies
 * Secure based on attacker-controlled headers.
 *
 * @param array<string,mixed>|null $server
 */
function request_is_https(?array $server = null): bool
{
    $server ??= $_SERVER;
    $directHttps = strtolower(trim((string)($server['HTTPS'] ?? '')));
    if (in_array($directHttps, ['on', '1', 'true'], true) || (int)($server['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    $remoteAddress = trim((string)($server['REMOTE_ADDR'] ?? ''));
    if (!is_trusted_proxy($remoteAddress)) {
        return false;
    }

    $forwarded = trim((string)($server['HTTP_FORWARDED'] ?? ''));
    if ($forwarded !== '' && !str_contains($forwarded, ',')) {
        if (preg_match('/(?:^|;)\s*proto=(?:"([^"]+)"|([^;\s]+))/i', $forwarded, $match) === 1) {
            $proto = strtolower((string)($match[1] !== '' ? $match[1] : $match[2]));
            return $proto === 'https';
        }
    }

    foreach (['HTTP_X_FORWARDED_PROTO', 'HTTP_X_SCHEME'] as $header) {
        $value = strtolower(trim((string)($server[$header] ?? '')));
        if ($value !== '') {
            return !str_contains($value, ',') && $value === 'https';
        }
    }

    $cfVisitor = trim((string)($server['HTTP_CF_VISITOR'] ?? ''));
    if ($cfVisitor !== '') {
        $decoded = json_decode($cfVisitor, true);
        return is_array($decoded) && strtolower((string)($decoded['scheme'] ?? '')) === 'https';
    }

    return false;
}
