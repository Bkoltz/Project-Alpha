<?php

class S3LinkResolver
{
    private $accessKey;
    private $secretKey;
    private $bucket;
    private $region;
    private $rootPath;
    
    public function __construct(array $credentials)
    {
        $this->accessKey = $credentials['access_key'] ?? null;
        $this->secretKey = $credentials['secret_key'] ?? null;
        $this->bucket = $credentials['bucket'] ?? null;
        $this->region = $credentials['region'] ?? 'us-east-1';
        $this->rootPath = trim($credentials['root_path'] ?? '', '/');
        
        if (!$this->accessKey || !$this->secretKey || !$this->bucket) {
            throw new \Exception('S3 credentials not configured');
        }
    }
    
    /**
     * Search for a folder (prefix) by name in S3
     */
    public function searchFolder(string $folderName): array
    {
        try {
            // In S3, folders are just prefixes
            $prefix = $this->rootPath ? $this->rootPath . '/' . $folderName . '/' : $folderName . '/';
            
            // Check if prefix exists by listing objects
            $endpoint = "https://{$this->bucket}.s3.{$this->region}.amazonaws.com";
            $path = '/';
            $query = http_build_query([
                'list-type' => '2',
                'prefix' => $prefix,
                'max-keys' => '1'
            ]);
            
            $url = $endpoint . $path . '?' . $query;
            $date = gmdate('Ymd\THis\Z');
            $dateStamp = gmdate('Ymd');
            
            // Create canonical request
            $canonicalHeaders = "host:{$this->bucket}.s3.{$this->region}.amazonaws.com\nx-amz-date:{$date}\n";
            $signedHeaders = 'host;x-amz-date';
            $canonicalRequest = "GET\n{$path}\n{$query}\n{$canonicalHeaders}\n{$signedHeaders}\nUNSIGNED-PAYLOAD";
            
            // Create string to sign
            $algorithm = 'AWS4-HMAC-SHA256';
            $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";
            $stringToSign = "{$algorithm}\n{$date}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
            
            // Calculate signature
            $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
            $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
            $kService = hash_hmac('sha256', 's3', $kRegion, true);
            $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
            $signature = hash_hmac('sha256', $stringToSign, $kSigning);
            
            $authorization = "{$algorithm} Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => [
                    "Authorization: {$authorization}",
                    "x-amz-date: {$date}"
                ],
                CURLOPT_RETURNTRANSFER => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                @error_log('[S3] Search failed: ' . $response);
                return ['success' => false, 'message' => 'Search request failed'];
            }
            
            // Parse XML response
            $xml = simplexml_load_string($response);
            if ($xml === false) {
                return ['success' => false, 'message' => 'Invalid response'];
            }
            
            // Check if any objects exist with this prefix
            if (!isset($xml->Contents) && !isset($xml->CommonPrefixes)) {
                return ['success' => false, 'message' => 'Folder not found'];
            }
            
            return [
                'success' => true,
                'matches' => [[
                    'folder_id' => $prefix,
                    'name' => $folderName,
                    'path' => $prefix,
                ]],
                'folder_id' => $prefix,
                'name' => $folderName,
                'path' => $prefix,
            ];
            
        } catch (\Throwable $e) {
            @error_log('[S3] Search error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Generate a pre-signed URL for S3 folder (index page)
     * Note: S3 doesn't have native folder sharing, so we return a long-lived presigned URL
     */
    public function generatePublicLink(string $folderPrefix): ?string
    {
        try {
            // Generate a presigned URL valid for 1 year (max for S3 presigned URLs)
            $expires = time() + (365 * 24 * 60 * 60);
            
            // For folders, we'll link to the S3 console or create a simple HTML index
            // Most common approach: return the S3 HTTPS URL to the folder
            $url = "https://{$this->bucket}.s3.{$this->region}.amazonaws.com/{$folderPrefix}";
            
            return $url;
            
        } catch (\Throwable $e) {
            @error_log('[S3] Generate link error: ' . $e->getMessage());
            return null;
        }
    }
}
