<?php
// src/utils/client_ip.php
// Determine the real client IP when the app runs behind a reverse proxy.
// Only trusts forwarding headers when the immediate connection (REMOTE_ADDR)
// comes from a configured or private/trusted proxy network.

function is_trusted_proxy(string $ip): bool {
    if ($ip === '') { return false; }

    // Explicitly configured trusted proxies (comma/space separated CIDRs or IPs)
    $configured = getenv('TRUSTED_PROXIES');
    if ($configured !== false && $configured !== '') {
        foreach (preg_split('/[,\s]+/', $configured, -1, PREG_SPLIT_NO_EMPTY) as $trusted) {
            if (ip_in_cidr($ip, $trusted)) {
                return true;
            }
        }
    }

    // Default trusted private/reserved ranges
    $privateRanges = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        'fc00::/7',
        'fe80::/10',
        '::1',
    ];
    foreach ($privateRanges as $range) {
        if (ip_in_cidr($ip, $range)) {
            return true;
        }
    }

    return false;
}

function ip_in_cidr(string $ip, string $cidr): bool {
    if (str_contains($ip, ':')) {
        return ipv6_in_cidr($ip, $cidr);
    }
    if (!str_contains($cidr, '/')) {
        return $ip === $cidr;
    }
    [$subnet, $mask] = explode('/', $cidr, 2);
    $mask = (int)$mask;
    if ($mask < 0 || $mask > 32) { return false; }
    $ipBin = ip2long($ip);
    $subnetBin = ip2long($subnet);
    if ($ipBin === false || $subnetBin === false) { return false; }
    $maskBin = $mask === 0 ? 0 : (~0 << (32 - $mask)) & 0xFFFFFFFF;
    return ($ipBin & $maskBin) === ($subnetBin & $maskBin);
}

function ipv6_in_cidr(string $ip, string $cidr): bool {
    if (!str_contains($cidr, '/')) {
        // Allow compressed/normalized comparison
        return inet_pton($ip) === inet_pton($cidr);
    }
    [$subnet, $mask] = explode('/', $cidr, 2);
    $mask = (int)$mask;
    if ($mask < 0 || $mask > 128) { return false; }
    $ipBin = inet_pton($ip);
    $subnetBin = inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false) { return false; }
    // Compare only the first $mask bits
    $bytes = (int)floor($mask / 8);
    $remainder = $mask % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
        return false;
    }
    if ($remainder > 0 && isset($ipBin[$bytes]) && isset($subnetBin[$bytes])) {
        $shift = 8 - $remainder;
        return (ord($ipBin[$bytes]) >> $shift) === (ord($subnetBin[$bytes]) >> $shift);
    }
    return true;
}

/**
 * Return the best-effort client IP.
 * - If REMOTE_ADDR is not a trusted proxy, return REMOTE_ADDR.
 * - If it is trusted, inspect X-Forwarded-For / X-Real-Ip / Forwarded / CF-Connecting-IP.
 * - X-Forwarded-For is parsed left-to-right and the first public/non-trusted IP is returned.
 *   (Trusted hops on the right are skipped.)
 */
function get_client_ip(): string {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_trusted_proxy($remoteAddr)) {
        return $remoteAddr !== '' ? $remoteAddr : 'unknown';
    }

    // Priority headers
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
    ];

    foreach ($headers as $header) {
        $value = $_SERVER[$header] ?? '';
        if ($value === '') { continue; }

        if ($header === 'HTTP_FORWARDED') {
            // Parse "for=1.2.3.4" style
            if (preg_match_all('/for=\s*"?([^";\s,]+)"?/i', $value, $matches)) {
                foreach ($matches[1] as $candidate) {
                    $candidate = trim($candidate);
                    if (filter_var($candidate, FILTER_VALIDATE_IP) && !is_trusted_proxy($candidate)) {
                        return $candidate;
                    }
                }
            }
            continue;
        }

        // X-Forwarded-For and friends can be comma-separated
        $ips = array_map('trim', explode(',', $value));
        foreach ($ips as $candidate) {
            if (str_starts_with($candidate, '[') && str_ends_with($candidate, ']')) {
                $candidate = substr($candidate, 1, -1);
            }
            if (filter_var($candidate, FILTER_VALIDATE_IP) && !is_trusted_proxy($candidate)) {
                return $candidate;
            }
        }
        // If every IP in the header is a trusted proxy, fall through and eventually use the leftmost
        if (!empty($ips)) {
            $first = trim(str_replace(['[', ']'], '', $ips[0]));
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
    }

    return $remoteAddr !== '' ? $remoteAddr : 'unknown';
}
