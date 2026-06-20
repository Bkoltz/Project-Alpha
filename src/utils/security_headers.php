<?php
// src/utils/security_headers.php
// Sends a consistent set of security response headers.

function send_security_headers(): void {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://js.stripe.com https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: blob:; connect-src 'self' https://api.stripe.com; frame-src https://js.stripe.com https://hooks.stripe.com; font-src 'self' https://fonts.gstatic.com; object-src 'none'; base-uri 'self'; form-action 'self';");
}
