<?php

class S3LinkResolver
{
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private string $region;
    private string $rootPath;
    private string $endpoint;
    private string $publicBaseUrl;
    private string $sessionToken;
    /** @var null|callable(string,list<string>):array{response:string|false,curl_error?:string,http_code:int} */
    private $requester;
    private ?array $lastError = null;

    public function __construct(array $credentials, ?callable $requester = null)
    {
        $this->accessKey = trim((string)($credentials['access_key'] ?? ''));
        $this->secretKey = trim((string)($credentials['secret_key'] ?? ''));
        $this->bucket = trim((string)($credentials['bucket'] ?? ''));
        $this->region = trim((string)($credentials['region'] ?? 'us-east-1')) ?: 'us-east-1';
        $this->rootPath = trim(str_replace('\\', '/', (string)($credentials['root_path'] ?? '')), '/');
        $this->endpoint = $this->normalizeEndpoint((string)($credentials['endpoint'] ?? ''));
        $this->publicBaseUrl = trim((string)($credentials['public_base_url'] ?? ''));
        $this->sessionToken = trim((string)($credentials['session_token'] ?? ''));
        $this->requester = $requester;

        if ($this->accessKey === '' || $this->secretKey === '' || $this->bucket === '') {
            throw new InvalidArgumentException('S3/R2 access key, secret key, and bucket are required');
        }
        if ($this->publicBaseUrl !== '' && !preg_match('#^https?://#i', $this->publicBaseUrl)) {
            throw new InvalidArgumentException('The public Worker/base URL must start with https:// or http://');
        }
        $publicParts = $this->publicBaseUrl !== '' ? parse_url($this->publicBaseUrl) : [];
        if (
            $this->publicBaseUrl !== ''
            && !str_contains($this->publicBaseUrl, '{prefix}')
            && is_array($publicParts)
            && (isset($publicParts['query']) || isset($publicParts['fragment']))
        ) {
            throw new InvalidArgumentException('A public Worker/base URL with a query or fragment must include the {prefix} placeholder');
        }
    }

    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    private function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }

        $parts = parse_url($endpoint);
        if (
            !is_array($parts)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('Invalid S3/R2 API endpoint');
        }

        return rtrim($endpoint, '/');
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
        if ($xml !== false) {
            $codes = $xml->xpath('//*[local-name()="Code"]');
            $messages = $xml->xpath('//*[local-name()="Message"]');
            if (!empty($codes[0])) {
                $detail = ' Storage provider said: ' . (string)$codes[0];
                if (!empty($messages[0])) {
                    $detail .= ' - ' . (string)$messages[0];
                }
            }
        }

        if ($httpCode === 401 || $httpCode === 403) {
            return [
                'S3/R2 denied permission for ' . $operation . '.' . $detail,
                'Check the API token credentials and grant object read/list access to this bucket.',
            ];
        }
        if ($httpCode === 404) {
            return [
                'S3/R2 could not find the configured bucket or prefix.' . $detail,
                'Check the bucket, API endpoint, region, and root folder path in Settings > Links.',
            ];
        }

        return [
            'S3/R2 ' . $operation . ' failed with HTTP ' . $httpCode . '.' . $detail,
            'Use Test Connection and verify the endpoint, credentials, region, bucket, and root path.',
        ];
    }

    private function canonicalQuery(array $params): string
    {
        $encoded = [];
        foreach ($params as $key => $value) {
            $encoded[rawurlencode((string)$key)] = rawurlencode((string)$value);
        }
        ksort($encoded, SORT_STRING);

        $parts = [];
        foreach ($encoded as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        return implode('&', $parts);
    }

    private function encodePath(string $path): string
    {
        $segments = explode('/', trim(str_replace('\\', '/', $path), '/'));
        $encoded = implode('/', array_map('rawurlencode', array_filter($segments, static fn(string $segment): bool => $segment !== '')));
        return '/' . ($encoded !== '' ? $encoded . '/' : '');
    }

    /** @return array{scheme:string,host:string,canonical_uri:string,url:string} */
    private function requestTarget(): array
    {
        if ($this->endpoint === '') {
            $host = $this->bucket . '.s3.' . $this->region . '.amazonaws.com';
            return [
                'scheme' => 'https',
                'host' => $host,
                'canonical_uri' => '/',
                'url' => 'https://' . $host . '/',
            ];
        }

        $parts = parse_url($this->endpoint);
        if (!is_array($parts) || empty($parts['host'])) {
            throw new RuntimeException('Invalid S3/R2 API endpoint');
        }
        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $host = (string)$parts['host'] . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
        $endpointPath = trim((string)($parts['path'] ?? ''), '/');
        $hostAlreadyIncludesBucket = str_starts_with(strtolower((string)$parts['host']), strtolower($this->bucket) . '.');
        $pathParts = array_filter([
            $endpointPath,
            $hostAlreadyIncludesBucket ? '' : $this->bucket,
        ], static fn(string $part): bool => $part !== '');
        $canonicalUri = $this->encodePath(implode('/', $pathParts));

        return [
            'scheme' => $scheme,
            'host' => $host,
            'canonical_uri' => $canonicalUri,
            'url' => $scheme . '://' . $host . $canonicalUri,
        ];
    }

    private function signedGet(array $queryParams): array
    {
        $target = $this->requestTarget();
        $query = $this->canonicalQuery($queryParams);
        $requestUrl = $target['url'] . ($query !== '' ? '?' . $query : '');
        $date = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $headerValues = [
            'host' => $target['host'],
            'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD',
            'x-amz-date' => $date,
        ];
        if ($this->sessionToken !== '') {
            $headerValues['x-amz-security-token'] = $this->sessionToken;
        }
        ksort($headerValues, SORT_STRING);

        $canonicalHeaders = '';
        foreach ($headerValues as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim($value) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headerValues));
        $canonicalRequest = "GET\n{$target['canonical_uri']}\n{$query}\n{$canonicalHeaders}\n{$signedHeaders}\nUNSIGNED-PAYLOAD";

        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $stringToSign = "{$algorithm}\n{$date}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $authorization = "{$algorithm} Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $headers = [
            'Authorization: ' . $authorization,
            'x-amz-content-sha256: UNSIGNED-PAYLOAD',
            'x-amz-date: ' . $date,
        ];
        if ($this->sessionToken !== '') {
            $headers[] = 'x-amz-security-token: ' . $this->sessionToken;
        }

        if ($this->requester !== null) {
            return ($this->requester)($requestUrl, $headers);
        }

        $ch = curl_init($requestUrl);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
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

    private function safeFolderSegment(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name), '/');
        return $name !== '' && !str_contains($name, '/') ? $name : '';
    }

    private function folderPrefix(string $folderName, ?string $parentName = null): string
    {
        $name = $this->safeFolderSegment($folderName);
        $parent = $parentName !== null ? $this->safeFolderSegment($parentName) : '';
        if ($name === '' || ($parentName !== null && $parent === '')) {
            return '';
        }

        return implode('/', array_filter([
            $this->rootPath,
            $parent,
            $name,
        ], static fn(string $part): bool => $part !== '')) . '/';
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
            return $this->fail('folder search', 'S3/R2 folder search could not reach the configured endpoint: ' . (string)($result['curl_error'] ?? ''), 'Check outbound network access and the S3/R2 API endpoint.');
        }
        if ($httpCode !== 200) {
            [$message, $tip] = $this->s3ErrorMessage($httpCode, 'folder search', (string)$response);
            @error_log('[S3/R2] Search failed HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 500));
            return $this->fail('folder search', $message, $tip, $httpCode, (string)$response);
        }

        $xml = @simplexml_load_string((string)$response);
        if ($xml === false) {
            return $this->fail('folder search', 'S3/R2 returned an invalid list response.', 'Check that the endpoint, bucket, and region are correct.');
        }
        $contents = $xml->xpath('//*[local-name()="Contents"]');
        $prefixes = $xml->xpath('//*[local-name()="CommonPrefixes"]');
        if (empty($contents) && empty($prefixes)) {
            return ['success' => false, 'message' => 'Folder not found'];
        }

        return ['success' => true];
    }

    /**
     * Search for exact Dropbox-style folder paths. Parent names let department
     * lookups check root/organization/department without scanning an entire bucket.
     */
    public function searchFolder(string $folderName, array $parentNames = []): array
    {
        try {
            $this->lastError = null;
            $parents = array_values(array_unique(array_filter(array_map(
                fn($parent): string => $this->safeFolderSegment((string)$parent),
                $parentNames
            ))));
            $parentsToCheck = $parents ?: [null];
            $matches = [];

            foreach ($parentsToCheck as $parentName) {
                $prefix = $this->folderPrefix($folderName, $parentName);
                if ($prefix === '') {
                    continue;
                }
                $exists = $this->prefixExists($prefix);
                if (empty($exists['success'])) {
                    if (($exists['message'] ?? '') !== 'Folder not found') {
                        return $exists;
                    }
                    continue;
                }
                $matches[] = [
                    'folder_id' => $prefix,
                    'name' => trim($folderName),
                    'parent_name' => $parentName ?? '',
                    'path' => $prefix,
                ];
            }

            if (!$matches) {
                return ['success' => false, 'message' => 'Folder not found'];
            }

            return [
                'success' => true,
                'matches' => $matches,
                'folder_id' => $matches[0]['folder_id'],
                'name' => $matches[0]['name'],
                'parent_name' => $matches[0]['parent_name'],
                'path' => $matches[0]['path'],
            ];
        } catch (Throwable $e) {
            @error_log('[S3/R2] Search error: ' . $e->getMessage());
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
                if (($exists['message'] ?? '') === 'Folder not found') {
                    return $this->fail(
                        'root prefix check',
                        'S3/R2 connected, but the configured root prefix was not found.',
                        'Check the root folder path or upload at least one object beneath that prefix.'
                    );
                }
                return $exists;
            }
            return [
                'success' => true,
                'message' => 'Connected to ' . ($this->endpoint !== '' ? 'S3-compatible/R2 bucket' : 'S3 bucket') . ': ' . $this->bucket . '; root prefix verified: ' . $this->rootPath . '/',
            ];
        }

        $result = $this->signedGet([
            'list-type' => '2',
            'max-keys' => '1',
        ]);
        $response = $result['response'];
        $httpCode = (int)$result['http_code'];
        if ($response === false) {
            return $this->fail('connection test', 'S3/R2 connection test could not reach the configured endpoint: ' . (string)($result['curl_error'] ?? ''), 'Check outbound network access and the S3/R2 API endpoint.');
        }
        if ($httpCode !== 200) {
            [$message, $tip] = $this->s3ErrorMessage($httpCode, 'connection test', (string)$response);
            return $this->fail('connection test', $message, $tip, $httpCode, (string)$response);
        }

        $provider = $this->endpoint !== '' ? 'S3-compatible/R2 bucket' : 'S3 bucket';
        $message = 'Connected to ' . $provider . ': ' . $this->bucket;
        return ['success' => true, 'message' => $message];
    }

    /**
     * Return the customer-facing Worker/CDN URL for a verified prefix.
     * Native S3/R2 APIs do not provide browser folder pages.
     */
    public function generatePublicLink(string $folderPrefix): ?string
    {
        try {
            $folderPrefix = trim(str_replace('\\', '/', $folderPrefix), '/');
            if ($folderPrefix === '') {
                $this->fail('create folder link', 'S3/R2 folder prefix is empty.', 'Check the resolver root path and matched folder name.');
                return null;
            }
            $folderPrefix .= '/';

            if ($this->rootPath !== '' && !str_starts_with($folderPrefix, $this->rootPath . '/')) {
                $this->fail('create folder link', 'S3/R2 matched prefix is outside the configured root path.', 'Check the root folder path before running the resolver again.');
                return null;
            }
            $exists = $this->prefixExists($folderPrefix);
            if (empty($exists['success'])) {
                return null;
            }

            $encodedPrefix = $this->encodePrefixForUrl($folderPrefix);
            if ($this->publicBaseUrl !== '') {
                if (str_contains($this->publicBaseUrl, '{prefix}')) {
                    return str_replace(
                        ['{bucket}', '{prefix}'],
                        [rawurlencode($this->bucket), $encodedPrefix],
                        $this->publicBaseUrl
                    );
                }
                return rtrim(str_replace('{bucket}', rawurlencode($this->bucket), $this->publicBaseUrl), '/') . '/' . $encodedPrefix;
            }

            if ($this->endpoint !== '') {
                $this->fail(
                    'create folder link',
                    'S3/R2 storage is connected, but no public Worker/base URL is configured.',
                    'Enter the LTDS-Ops Worker or R2 custom-domain URL used by clients. PA will append the matched folder prefix.'
                );
                return null;
            }

            return 'https://' . $this->bucket . '.s3.' . $this->region . '.amazonaws.com/' . $encodedPrefix;
        } catch (Throwable $e) {
            @error_log('[S3/R2] Generate link error: ' . $e->getMessage());
            $this->fail('create folder link', $e->getMessage());
            return null;
        }
    }
}
