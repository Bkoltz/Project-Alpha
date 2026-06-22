<?php
// src/utils/security_headers.php
// Sends a consistent set of security response headers.

function send_security_headers(): void {
    // Prevent clickjacking by denying framing entirely.
    header('X-Frame-Options: DENY');
    // Prevent MIME-sniffing attacks.
    header('X-Content-Type-Options: nosniff');
    // Deprecated but retained for legacy browser defense-in-depth.
    header('X-XSS-Protection: 1; mode=block');
    // Enforce HTTPS for one year, including subdomains.
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    // Leak no more referrer than scheme/host/path on cross-origin requests.
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Disable powerful browser features by default.
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=()');
    // Hardened Content Security Policy. unsafe-inline is required by the existing
    // inline scripts/styles in this legacy codebase; remove as code is migrated.
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://js.stripe.com https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: blob:; connect-src 'self' https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; font-src 'self' https://fonts.gstatic.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; upgrade-insecure-requests;");
    // Remove the X-Powered-By header to reduce fingerprinting.
    header_remove('X-Powered-By');
}
