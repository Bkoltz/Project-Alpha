<?php

declare(strict_types=1);

/**
 * Parse the API-key field as a fail-closed list of exact IP addresses.
 * CIDR ranges and partially valid lists are intentionally rejected.
 */
function api_parse_exact_ip_allowlist(mixed $value): array
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return [];
    }
    $tokens = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($tokens) || $tokens === []) {
        return [];
    }
    $ips = [];
    foreach ($tokens as $token) {
        $ip = trim((string)$token);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return [];
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return [];
        }
        $ips[bin2hex($packed)] = $ip;
    }
    return array_values($ips);
}

function api_ip_matches_exact_allowlist(string $ip, array $allowlist): bool
{
    $candidate = inet_pton($ip);
    if ($candidate === false) {
        return false;
    }
    foreach ($allowlist as $allowed) {
        $packed = inet_pton((string)$allowed);
        if ($packed !== false && hash_equals($packed, $candidate)) {
            return true;
        }
    }
    return false;
}
