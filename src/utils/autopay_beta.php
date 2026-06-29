<?php

/**
 * BETA / UNAVAILABLE: AutoPay is not a production Project Alpha feature.
 *
 * This guard intentionally fails closed. There are no public routes or settings
 * for AutoPay. Internal development code may only pass the guard when both the
 * explicit beta flag and a non-production application environment are present.
 */
function autopay_beta_enabled(): bool
{
    $enabled = filter_var(getenv('AUTOPAY_BETA_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    $environment = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));

    return $enabled && in_array($environment, ['development', 'dev', 'test', 'testing'], true);
}

/**
 * @throws RuntimeException Always in production and by default elsewhere.
 */
function require_autopay_beta(): void
{
    if (!autopay_beta_enabled()) {
        throw new RuntimeException('AutoPay is an unavailable beta feature and is disabled.');
    }
}
