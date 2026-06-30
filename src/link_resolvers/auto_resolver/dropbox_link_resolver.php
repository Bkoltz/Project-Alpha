<?php
// src/link_resolvers/auto_resolver/dropbox_link_resolver.php
// Updated to support OAuth with automatic token refresh

require_once __DIR__ . '/../../utils/link_provider_config.php';

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

    private function dropboxErrorSummary(array $value): string
    {
        if (!empty($value['error_summary']) && is_string($value['error_summary'])) {
            return (string)$value['error_summary'];
        }
        if (!empty($value['.tag']) && is_string($value['.tag'])) {
            $parts = [(string)$value['.tag']];
            foreach ($value as $key => $child) {
                if ($key === '.tag') {
                    continue;
                }
                if (is_array($child)) {
                    $childSummary = $this->dropboxErrorSummary($child);
                    if ($childSummary !== '') {
                        $parts[] = $childSummary;
                        break;
                    }
                } elseif (is_string($child) && $child !== '') {
                    $parts[] = $child;
                    break;
                }
            }
            return implode(':', $parts);
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $childSummary = $this->dropboxErrorSummary($child);
                if ($childSummary !== '') {
                    return $childSummary;
                }
            }
        }
        return '';
    }

    private function dropboxApiErrorMessage(int $httpCode, string $operation, string $response): array
    {
        $decoded = json_decode($response, true);
        $summary = is_array($decoded) ? $this->dropboxErrorSummary($decoded) : '';
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
        if ($httpCode === 400 && $summary !== '') {
            return [
                'Dropbox rejected the ' . $operation . ' request.' . $detail,
                'If this folder already has a link, PA will reuse the exact-path link when Dropbox exposes it. Otherwise check Dropbox sharing policy for this folder.',
            ];
        }

        return [
            'Dropbox ' . $operation . ' failed with HTTP ' . $httpCode . '.' . $detail,
            'Use Test Connection in Settings > Links and review the Dropbox app credentials/scopes.',
        ];
    }

    private function normalizeDropboxPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || $path === '/') {
            return '/';
        }
        $path = '/' . ltrim($path, '/');
        return strtolower(rtrim($path, '/'));
    }

    private function sharedLinkMatchesPath(array $link, string $folderPath): bool
    {
        $actualPath = (string)($link['path_lower'] ?? $link['path'] ?? $link['path_display'] ?? '');
        if ($actualPath === '') {
            return false;
        }
        return $this->normalizeDropboxPath($actualPath) === $this->normalizeDropboxPath($folderPath);
    }

    private function exactSharedLinkUrl(array $links, string $folderPath): ?string
    {
        foreach ($links as $link) {
            if (!is_array($link) || empty($link['url'])) {
                continue;
            }
            if ($this->sharedLinkMatchesPath($link, $folderPath)) {
                return (string)$link['url'];
            }
        }
        return null;
    }

    private function findExactSharedLinkInError(array $value, string $folderPath): ?string
    {
        if (!empty($value['url']) && $this->sharedLinkMatchesPath($value, $folderPath)) {
            return (string)$value['url'];
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $url = $this->findExactSharedLinkInError($child, $folderPath);
                if ($url !== null) {
                    return $url;
                }
            }
        }
        return null;
    }

    private function createSharedLink(string $folderPath, bool $withRequestedVisibility): array
    {
        $payload = ['path' => $folderPath];
        if ($withRequestedVisibility) {
            $payload['settings'] = [
                'requested_visibility' => 'public',
            ];
        }

        $ch = curl_init('https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true
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

    private function createLegacySharedLink(string $folderPath): array
    {
        $ch = curl_init('https://api.dropboxapi.com/2/sharing/create_shared_link');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'path' => $folderPath,
                'short_url' => false,
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

        return [
            'response' => $response,
            'curl_error' => $curlError,
            'http_code' => (int)$httpCode,
        ];
    }

    private function listSharedLinks(string $folderPath, bool $directOnly): array
    {
        $ch = curl_init('https://api.dropboxapi.com/2/sharing/list_shared_links');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'path' => $folderPath,
                'direct_only' => $directOnly,
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

        return [
            'response' => $response,
            'curl_error' => $curlError,
            'http_code' => (int)$httpCode,
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
            $row = pa_link_provider_best_row($this->pdo, 'dropbox');
            $credentials = $row ? pa_link_provider_credentials_from_row($row) : [];
            $credentials['access_token'] = $this->accessToken;
            $credentials['refresh_token'] = $this->refreshToken;
            $credentials['token_expires_at'] = $this->tokenExpiresAt;
            $credentials['root_path'] = $this->rootPath ?: ($credentials['root_path'] ?? '/');
            pa_link_provider_save(
                $this->pdo,
                'dropbox',
                $row ? (int)($row['is_enabled'] ?? 1) : 1,
                $credentials,
                $row ? (int)($row['default_expiration_days'] ?? 365) : 365
            );
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
            $folderPath = $this->normalizeDropboxPath($folderPath);

            // First, try to get existing shared link
            $lookup = $this->listSharedLinks($folderPath, true);
            $response = $lookup['response'];
            $curlError = (string)$lookup['curl_error'];
            $httpCode = (int)$lookup['http_code'];

            if ($response === false) {
                $this->fail('list shared links', 'Dropbox shared-link lookup could not reach Dropbox: ' . $curlError, 'Check outbound network access from the PA container/server.');
                return null;
            }
            
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                if (!empty($data['links'])) {
                    $exactUrl = $this->exactSharedLinkUrl((array)$data['links'], $folderPath);
                    if ($exactUrl !== null) {
                        return $exactUrl;
                    }
                    @error_log('[Dropbox] Ignored shared link result because it did not match exact folder path: ' . $folderPath);
                }
            } elseif ($httpCode !== 409) {
                [$message, $tip] = $this->dropboxApiErrorMessage($httpCode, 'shared-link lookup', (string)$response);
                @error_log('[Dropbox] List shared links failed HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 500));
                $this->fail('list shared links', $message, $tip, $httpCode, (string)$response);
                return null;
            }

            // If Dropbox did not expose a direct-only link, search all shared
            // links visible for this path and still require an exact path match.
            $lookup = $this->listSharedLinks($folderPath, false);
            $response = $lookup['response'];
            $httpCode = (int)$lookup['http_code'];
            if ($response !== false && $httpCode === 200) {
                $data = json_decode((string)$response, true);
                if (!empty($data['links'])) {
                    $exactUrl = $this->exactSharedLinkUrl((array)$data['links'], $folderPath);
                    if ($exactUrl !== null) {
                        return $exactUrl;
                    }
                }
            }
            
            // Create a new shared link. Some Dropbox team policies reject an
            // explicit public visibility request with HTTP 400, but still allow
            // a policy-default link, so retry once without requested_visibility.
            $create = $this->createSharedLink($folderPath, true);
            $response = $create['response'];
            $curlError = (string)$create['curl_error'];
            $httpCode = (int)$create['http_code'];

            if ($httpCode === 400 && $response !== false) {
                $retry = $this->createSharedLink($folderPath, false);
                $response = $retry['response'];
                $curlError = (string)$retry['curl_error'];
                $httpCode = (int)$retry['http_code'];

                if ($httpCode === 400 && $response !== false) {
                    $legacyRetry = $this->createLegacySharedLink($folderPath);
                    $response = $legacyRetry['response'];
                    $curlError = (string)$legacyRetry['curl_error'];
                    $httpCode = (int)$legacyRetry['http_code'];
                }
            }

            if ($response === false) {
                $this->fail('create shared link', 'Dropbox shared-link creation could not reach Dropbox: ' . $curlError, 'Check outbound network access from the PA container/server.');
                return null;
            }
            
            if ($httpCode !== 200) {
                $data = json_decode((string)$response, true);
                if (in_array($httpCode, [400, 409], true) && is_array($data)) {
                    $exactUrl = $this->findExactSharedLinkInError($data, $folderPath);
                    if ($exactUrl !== null) {
                        return $exactUrl;
                    }
                }
                [$message, $tip] = $this->dropboxApiErrorMessage($httpCode, 'shared-link creation', (string)$response);
                @error_log('[Dropbox] Create link failed HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 500));
                $this->fail('create shared link', $message, $tip, $httpCode, (string)$response);
                return null;
            }
            
            $data = json_decode($response, true);
            if (is_array($data) && !$this->sharedLinkMatchesPath($data, $folderPath)) {
                $this->fail(
                    'create shared link',
                    'Dropbox returned a shared link for a different folder than the exact resolver match.',
                    'The resolver refused to attach this link to avoid sharing a parent folder. Check the Dropbox root path and existing shared links.'
                );
                return null;
            }
            return $data['url'] ?? null;
            
        } catch (\Throwable $e) {
            @error_log('[Dropbox] Generate link error: ' . $e->getMessage());
            return null;
        }
    }
}
