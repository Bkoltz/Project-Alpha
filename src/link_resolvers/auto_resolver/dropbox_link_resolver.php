<?php
// src/link_resolvers/auto_resolver/dropbox_link_resolver.php
// Updated to support OAuth with automatic token refresh

class DropboxLinkResolver
{
    private $accessToken;
    private $refreshToken;
    private $appKey;
    private $appSecret;
    private $rootPath;
    private $tokenExpiresAt;
    private $pdo; // Database connection for updating tokens
    
    public function __construct(array $credentials, ?PDO $pdo = null)
    {
        $this->accessToken = $credentials['access_token'] ?? null;
        $this->refreshToken = $credentials['refresh_token'] ?? null;
        $this->appKey = $credentials['app_key'] ?? null;
        $this->appSecret = $credentials['app_secret'] ?? null;
        $this->rootPath = $credentials['root_path'] ?? '/';
        $this->tokenExpiresAt = $credentials['token_expires_at'] ?? null;
        $this->pdo = $pdo;
        
        if (!$this->accessToken) {
            throw new \Exception('Dropbox access token not configured');
        }
        
        // Refresh token if it's about to expire (within 5 minutes)
        if ($this->refreshToken && $this->tokenExpiresAt) {
            $expiresTimestamp = strtotime($this->tokenExpiresAt);
            if ($expiresTimestamp && $expiresTimestamp - time() < 300) {
                $this->refreshAccessToken();
            }
        }
    }
    
    /**
     * Refresh the access token using the refresh token
     */
    private function refreshAccessToken(): void
    {
        if (empty($this->refreshToken) || empty($this->appKey) || empty($this->appSecret)) {
            return;
        }
        
        try {
            $ch = curl_init('https://api.dropboxapi.com/oauth2/token');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'refresh_token' => $this->refreshToken,
                    'grant_type' => 'refresh_token',
                    'client_id' => $this->appKey,
                    'client_secret' => $this->appSecret
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                if (!empty($data['access_token'])) {
                    $this->accessToken = $data['access_token'];
                    
                    // Update expiration if provided
                    if (!empty($data['expires_in'])) {
                        $this->tokenExpiresAt = date('Y-m-d H:i:s', time() + $data['expires_in']);
                    }
                    
                    // Update the database with the new token
                    if ($this->pdo) {
                        $this->updateStoredToken();
                    }
                }
            }
        } catch (\Throwable $e) {
            @error_log('[Dropbox] Token refresh failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Update the stored access token in the database
     */
    private function updateStoredToken(): void
    {
        if (!$this->pdo) return;
        
        try {
            $stmt = $this->pdo->prepare("SELECT credentials FROM link_resolver_config WHERE provider = 'dropbox'");
            $stmt->execute();
            $existing = $stmt->fetchColumn();
            
            if ($existing) {
                $credentials = json_decode($existing, true) ?: [];
                $credentials['access_token'] = $this->accessToken;
                $credentials['token_expires_at'] = $this->tokenExpiresAt;
                
                $stmt = $this->pdo->prepare("UPDATE link_resolver_config SET credentials = ? WHERE provider = 'dropbox'");
                $stmt->execute([json_encode($credentials)]);
            }
        } catch (\Throwable $e) {
            @error_log('[Dropbox] Failed to update stored token: ' . $e->getMessage());
        }
    }
    
    /**
     * Search for a folder by name in Dropbox
     */
    public function searchFolder(string $folderName): array
    {
        try {
            $ch = curl_init('https://api.dropboxapi.com/2/files/search_v2');
            
            $searchData = [
                'query' => $folderName,
                'options' => [
                    'path' => $this->rootPath,
                    'file_status' => 'active',
                    'filename_only' => true
                ],
                'match_field_options' => [
                    'include_highlights' => false
                ]
            ];
            
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($searchData),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->accessToken,
                    'Content-Type: application/json'
                ],
                CURLOPT_RETURNTRANSFER => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                @error_log('[Dropbox] Search failed: ' . $response);
                return ['success' => false, 'message' => 'Search request failed'];
            }
            
            $data = json_decode($response, true);
            
            if (empty($data['matches'])) {
                return ['success' => false, 'message' => 'Folder not found'];
            }
            
            $matches = [];
            foreach ($data['matches'] as $match) {
                $metadata = $match['metadata']['metadata'] ?? [];
                if (
                    isset($metadata['.tag'], $metadata['name'], $metadata['path_lower']) &&
                    $metadata['.tag'] === 'folder' &&
                    strtolower((string)$metadata['name']) === strtolower($folderName)
                ) {
                    $matches[] = [
                        'folder_id' => (string)$metadata['path_lower'],
                        'name' => (string)$metadata['name'],
                        'path' => (string)$metadata['path_lower'],
                    ];
                }
            }

            if ($matches) {
                return [
                    'success' => true,
                    'matches' => $matches,
                    'folder_id' => $matches[0]['folder_id'],
                    'name' => $matches[0]['name'],
                    'path' => $matches[0]['path'],
                ];
            }
            
            return ['success' => false, 'message' => 'Exact folder match not found'];
            
        } catch (\Throwable $e) {
            @error_log('[Dropbox] Search error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Generate a public shared link for a folder
     */
    public function generatePublicLink(string $folderPath): ?string
    {
        try {
            // First, try to get existing shared link
            $ch = curl_init('https://api.dropboxapi.com/2/sharing/list_shared_links');
            
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['path' => $folderPath]),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->accessToken,
                    'Content-Type: application/json'
                ],
                CURLOPT_RETURNTRANSFER => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                if (!empty($data['links'])) {
                    return $data['links'][0]['url'];
                }
            }
            
            // Create new shared link
            $ch = curl_init('https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings');
            
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'path' => $folderPath,
                    'settings' => [
                        'requested_visibility' => 'public'
                    ]
                ]),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->accessToken,
                    'Content-Type: application/json'
                ],
                CURLOPT_RETURNTRANSFER => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                @error_log('[Dropbox] Create link failed: ' . $response);
                return null;
            }
            
            $data = json_decode($response, true);
            return $data['url'] ?? null;
            
        } catch (\Throwable $e) {
            @error_log('[Dropbox] Generate link error: ' . $e->getMessage());
            return null;
        }
    }
}
