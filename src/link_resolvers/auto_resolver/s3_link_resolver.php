<?php

class S3LinkResolver
{
    private $accessKey;
    private $secretKey;
    private $bucket;
    private $region;
    private $rootPath;
    private ?array $lastError = null;

    public function __construct(array $credentials)
    {
        $this->accessKey = $credentials['access_key'] ?? null;
        $this->secretKey = $credentials['secret_key'] ?? null;
        $this->bucket = $credentials['bucket'] ?? null;
        $this->region = $credentials['region'] ?? 'us-east-1';
        $this->rootPath = trim((string)($credentials['root_path'] ?? ''), '/');

        if (!$this->accessKey || !$this->secretKey || !$this->bucket) {
            throw new \Exception('S3 credentials not configured');
        }
    }

    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    private function fail(string $operation, string $message, ?string $tip = null, ?int $httpCode = null, ?string $response = null): array
    {
        $this->lastError = [
            'operation' => $operation,
            'message' => $message,
            'tip' => $tip,
            'http_code' => $httpCode,
            'response' => $response ? substr($response, 0, 1000) : null,
        ];
        return ['success' => false, 'message' => $message, 'tip' => $tip];
    }

    private function s3ErrorMessage(int $httpCode, string $operation, string $response): array
    {
        $detail = '';
        $xml = @simplexml_load_string($response);
        if ($xml !== false && isset($xml->Code)) {
            $detail = ' S3 said: ' . (string)$xml->Code;
            if (isset($xml->Message)) {
                $detail .= ' - ' . (string)$xml->Message;
            }
        }

        if ($httpCode === 403) {
            return [
                'S3 denied permission for ' . $operation . '.' . $detail,
                'Check the IAM policy for s3:ListBucket on the bucket and access to the configured root prefix.',
            ];
        }
        if ($httpCode === 404) {
            return [
                'S3 could not find the configured bucket or prefix.' . $detail,
                'Check the bucket name, region, and root folder path in Settings > Links.',
            ];
        }

        return [
            'S3 ' . $operation . ' failed with HTTP ' . $httpCode . '.' . $detail,
            'Use Test Connection in Settings > Links and verify the S3 credentials, region, bucket, and root path.',
        ];
    }

    private function canonicalQuery(array $params): string
    {
        ksort($params);
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
        }
        return implode('&', $parts);
    }

    private function signedGet(array $queryParams): array
    {
        $host = "{$this->bucket}.s3.{$this->region}.amazonaws.com";
        $path = '/';
        $query = $this->canonicalQuery($queryParams);
        $endpoint = "https://{$host}{$path}?{$query}";
        $date = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $canonicalHeaders = "host:{$host}\nx-amz-date:{$date}\n";
        $signedHeaders = 'host;x-amz-date';
        $canonicalRequest = "GET\n{$path}\n{$query}\n{$canonicalHeaders}\n{$signedHeaders}\nUNSIGNED-PAYLOAD";

        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $stringToSign = "{$algorithm}\n{$date}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = "{$algorithm} Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                "Authorization: {$authorization}",
                "x-amz-date: {$date}",
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [
            'response' => $response,
            'curl_error' => $curlError,
            'http_code' => (int)$httpCode,
        ];
    }

    private function folderPrefix(string $folderName): string
    {
        $name = trim(str_replace('\\', '/', $folderName), '/');
        if ($name === '' || str_contains($name, '/')) {
            return '';
        }
        return ($this->rootPath !== '' ? $this->rootPath . '/' : '') . $name . '/';
    }

    private function prefixExists(string $prefix): array
    {
        $result = $this->signedGet([
            'list-type' => '2',
            'prefix' => $prefix,
            'max-keys' => '1',
        ]);
        $response = $result['response'];
        $httpCode = (int)$result['http_code'];

        if ($response === false) {
            return $this->fail('folder search', 'S3 folder search could not reach AWS: ' . (string)$result['curl_error'], 'Check outbound network access from the PA container/server.');
        }

        if ($httpCode !== 200) {
            [$message, $tip] = $this->s3ErrorMessage($httpCode, 'folder search', (string)$response);
            @error_log('[S3] Search failed HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 500));
            return $this->fail('folder search', $message, $tip, $httpCode, (string)$response);
        }

        $xml = simplexml_load_string((string)$response);
        if ($xml === false) {
            return $this->fail('folder search', 'S3 returned an invalid list response.', 'Check that the bucket and region are correct.');
        }

        if (!isset($xml->Contents) && !isset($xml->CommonPrefixes)) {
            return ['success' => false, 'message' => 'Folder not found'];
        }

        return ['success' => true];
    }

    /**
     * Search for an exact folder prefix by name in S3.
     */
    public function searchFolder(string $folderName): array
    {
        try {
            $prefix = $this->folderPrefix($folderName);
            if ($prefix === '') {
                return ['success' => false, 'message' => 'Exact folder match not found'];
            }

            $exists = $this->prefixExists($prefix);
            if (empty($exists['success'])) {
                return $exists;
            }

            return [
                'success' => true,
                'matches' => [[
                    'folder_id' => $prefix,
                    'name' => trim($folderName),
                    'path' => $prefix,
                ]],
                'folder_id' => $prefix,
                'name' => trim($folderName),
                'path' => $prefix,
            ];
        } catch (\Throwable $e) {
            @error_log('[S3] Search error: ' . $e->getMessage());
            return $this->fail('folder search', $e->getMessage());
        }
    }

    private function encodePrefixForUrl(string $prefix): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($prefix, '/')))) . '/';
    }

    public function testConnection(): array
    {
        if ($this->rootPath !== '') {
            $exists = $this->prefixExists($this->rootPath . '/');
            if (empty($exists['success'])) {
                return $exists;
            }
            return ['success' => true, 'message' => 'Connected to S3 bucket: ' . $this->bucket . '; root path verified: ' . $this->rootPath . '/'];
        }

        $result = $this->signedGet([
            'list-type' => '2',
            'max-keys' => '1',
        ]);
        $response = $result['response'];
        $httpCode = (int)$result['http_code'];
        if ($response === false) {
            return $this->fail('connection test', 'S3 connection test could not reach AWS: ' . (string)$result['curl_error'], 'Check outbound network access from the PA container/server.');
        }
        if ($httpCode !== 200) {
            [$message, $tip] = $this->s3ErrorMessage($httpCode, 'connection test', (string)$response);
            return $this->fail('connection test', $message, $tip, $httpCode, (string)$response);
        }

        return ['success' => true, 'message' => 'Connected to S3 bucket: ' . $this->bucket];
    }

    /**
     * Return a public HTTPS URL for an exact S3 prefix after re-validating it.
     *
     * S3 has no native folder-sharing endpoint. This URL works when the bucket,
     * prefix, or fronting CDN is configured to serve that prefix publicly.
     */
    public function generatePublicLink(string $folderPrefix): ?string
    {
        try {
            $folderPrefix = trim(str_replace('\\', '/', $folderPrefix), '/');
            if ($folderPrefix === '') {
                $this->fail('create folder link', 'S3 folder prefix is empty.', 'Check the resolver root path and matched folder name.');
                return null;
            }
            $folderPrefix .= '/';

            if ($this->rootPath !== '' && !str_starts_with($folderPrefix, $this->rootPath . '/')) {
                $this->fail('create folder link', 'S3 matched prefix is outside the configured root path.', 'Check the root folder path before running the resolver again.');
                return null;
            }

            $exists = $this->prefixExists($folderPrefix);
            if (empty($exists['success'])) {
                return null;
            }

            return "https://{$this->bucket}.s3.{$this->region}.amazonaws.com/" . $this->encodePrefixForUrl($folderPrefix);
        } catch (\Throwable $e) {
            @error_log('[S3] Generate link error: ' . $e->getMessage());
            $this->fail('create folder link', $e->getMessage());
            return null;
        }
    }
}
