<?php

class GdriveLinkResolver
{
    private $serviceAccount;
    private $accessToken;
    private $rootPath;
    
    public function __construct(array $credentials)
    {
        $this->serviceAccount = $credentials['service_account'] ?? null;
        $this->rootPath = $credentials['root_path'] ?? '';
        
        if (!$this->serviceAccount) {
            throw new \Exception('Google Drive service account not configured');
        }
        
        // Parse service account JSON
        $serviceAccountData = json_decode($this->serviceAccount, true);
        if (!$serviceAccountData) {
            throw new \Exception('Invalid service account JSON');
        }
        
        // Get access token
        $this->accessToken = $this->getAccessToken($serviceAccountData);
    }
    
    /**
     * Get access token using service account
     */
    private function getAccessToken(array $serviceAccount): string
    {
        try {
            $now = time();
            $jwt = [
                'iss' => $serviceAccount['client_email'],
                'scope' => 'https://www.googleapis.com/auth/drive',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now
            ];
            
            // Create JWT
            $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
            $payload = json_encode($jwt);
            
            $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
            $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
            
            $signature = '';
            openssl_sign(
                $base64UrlHeader . '.' . $base64UrlPayload,
                $signature,
                $serviceAccount['private_key'],
                OPENSSL_ALGO_SHA256
            );
            
            $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
            $jwtToken = $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;
            
            // Exchange JWT for access token
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwtToken
                ]),
                CURLOPT_RETURNTRANSFER => true
            ]);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            $data = json_decode($response, true);
            if (empty($data['access_token'])) {
                throw new \Exception('Failed to get access token');
            }
            
            return $data['access_token'];
            
        } catch (\Throwable $e) {
            @error_log('[GDrive] Token error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Search for a folder by name in Google Drive
     */
    public function searchFolder(string $folderName): array
    {
        try {
            $query = "name = '" . addslashes($folderName) . "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
            
            if ($this->rootPath) {
                $query .= " and '" . addslashes($this->rootPath) . "' in parents";
            }
            
            $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
                'q' => $query,
                'fields' => 'files(id,name)',
                'pageSize' => 10
            ]);
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->accessToken
                ],
                CURLOPT_RETURNTRANSFER => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                @error_log('[GDrive] Search failed: ' . $response);
                return ['success' => false, 'message' => 'Search request failed'];
            }
            
            $data = json_decode($response, true);
            
            if (empty($data['files'])) {
                return ['success' => false, 'message' => 'Folder not found'];
            }
            
            return [
                'success' => true,
                'folder_id' => $data['files'][0]['id']
            ];
            
        } catch (\Throwable $e) {
            @error_log('[GDrive] Search error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Generate a public shared link for a folder
     */
    public function generatePublicLink(string $folderId): ?string
    {
        try {
            // Create permission for anyone with link
            $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$folderId}/permissions");
            
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'role' => 'reader',
                    'type' => 'anyone'
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
            
            // 200 = success, 409 = permission already exists
            if ($httpCode !== 200 && $httpCode !== 409) {
                @error_log('[GDrive] Create permission failed: ' . $response);
            }
            
            // Return the shareable link
            return "https://drive.google.com/drive/folders/{$folderId}";
            
        } catch (\Throwable $e) {
            @error_log('[GDrive] Generate link error: ' . $e->getMessage());
            return null;
        }
    }
}
