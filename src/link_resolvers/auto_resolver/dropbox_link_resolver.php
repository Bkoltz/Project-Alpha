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
    private ?array $lastError = null;
    
    public function __construct(array $credentials, ?PDO $pdo = null)
    {
        $this->accessToken = $credentials['access_token'] ?? null;
        $this->refreshToken = $credentials['refresh_token'] ?? null;
        $this->appKey = $credentials['app_key'] ?? null;
        $this->appSecret = $credentials['app_secret'] ?? null;
        $this->rootPath = $credentials['root_path'] ?? '/';
        $this->tokenExpiresAt = $credentials['token_expires_at'] ?? null;
        $this->pdo = $pdo;

        if ((!$this->appKey || !$this->appSecret) && $this->pdo) {
            $this->loadAppCredentials();
        }
        
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

    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    private function loadAppCredentials(): void
    {
        try {
            $stmt = $this->pdo->prepare("SELECT config_key, config_value FROM app_config WHERE config_key IN ('dropbox_app_key', 'dropbox_app_secret')");
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ((string)$row['config_key'] === 'dropbox_app_key' && !$this->appKey) {
                    $this->appKey = (string)$row['config_value'];
                }
                if ((string)$row['config_key'] === 'dropbox_app_secret' && !$this->appSecret) {
                    $this->appSecret = (string)$row['config_value'];
                }
            }
        } catch (\Throwable $e) {
            @error_log('[Dropbox] Failed to load app credentials for token refresh: ' . $e->getMessage());
        }
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

    private function dropboxApiErrorMessage(int $httpCode, string $operation, string $response): array
    {
        $decoded = json_decode($response, true);
        $summary = is_array($decoded) && !empty($decoded['error_summary']) ? (string)$decoded['error_summary'] : '';
        $detail = $summary !== '' ? ' Dropbox said: ' . $summary : '';

        if ($httpCode === 401) {
            return [
                'Dropbox authentication failed.' . $detail,
                'Reconnect Dropbox in Settings > Links, then test the connection again.',
            ];
        }
        if ($httpCode === 403) {
            return [
                'Dropbox denied permission for ' . $operation . '.' . $detail,
                'Check the Dropbox app scopes. Folder search needs files.metadata.read; shared links need sharing.read and sharing.write. Reconnect Dropbox after changing scopes.',
            ];
        }
        if ($httpCode === 409) {
            return [
                'Dropbox could not find or access the configured folder path.' . $detail,
                'Check the resolver root path and make sure the connected Dropbox account can see that folder.',
            ];
        }

        return [
            'Dropbox ' . $operation . ' failed with HTTP ' . $httpCode . '.' . $detail,
            'Use Test Connection in Settings > Links and review the Dropbox app credentials/scopes.',
        ];
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
            $curlError = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                return $this->fail('folder search', 'Dropbox folder search could not reach Dropbox: ' . $curlError, 'Check outbound network access from the PA container/server.');
            }
            
            if ($httpCode !== 200) {
                [$message, $tip] = $this->dropboxApiErrorMessage($httpCode, 'folder search', (string)$response);
                @error_log('[Dropbox] Search failed HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 500));
                return $this->fail('folder search', $message, $tip, $httpCode, (string)$response);
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
            $curlError = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                $this->fail('list shared links', 'Dropbox shared-link lookup could not reach Dropbox: ' . $curlError, 'Check outbound network access from the PA container/server.');
                return null;
            }
            
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                if (!empty($data['links'])) {
                    return $data['links'][0]['url'];
                }
            } elseif ($httpCode !== 409) {
                [$message, $tip] = $this->dropboxApiErrorMessage($httpCode, 'shared-link lookup', (string)$response);
                @error_log('[Dropbox] List shared links failed HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 500));
                $this->fail('list shared links', $message, $tip, $httpCode, (string)$response);
                return null;
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
            $curlError = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                $this->fail('create shared link', 'Dropbox shared-link creation could not reach Dropbox: ' . $curlError, 'Check outbound network access from the PA container/server.');
                return null;
            }
            
            if ($httpCode !== 200) {
                [$message, $tip] = $this->dropboxApiErrorMessage($httpCode, 'shared-link creation', (string)$response);
                @error_log('[Dropbox] Create link failed HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 500));
                $this->fail('create shared link', $message, $tip, $httpCode, (string)$response);
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
