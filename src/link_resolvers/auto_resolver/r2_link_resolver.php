<?php

require_once __DIR__ . '/s3_link_resolver.php';

/**
 * Cloudflare R2 adapter for the shared S3-compatible resolver engine.
 */
class R2LinkResolver extends S3LinkResolver
{
    public function __construct(array $credentials, ?callable $requester = null)
    {
        $accountId = trim((string)($credentials['account_id'] ?? ''));
        $endpoint = trim((string)($credentials['endpoint'] ?? ''));
        if ($endpoint === '') {
            if ($accountId === '') {
                throw new InvalidArgumentException('Cloudflare Account ID is required for R2');
            }
            if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $accountId)) {
                throw new InvalidArgumentException('Cloudflare Account ID format is invalid');
            }
            $endpoint = 'https://' . $accountId . '.r2.cloudflarestorage.com';
        }

        $credentials['endpoint'] = $endpoint;
        $credentials['region'] = 'auto';
        parent::__construct($credentials, $requester);
    }
}
