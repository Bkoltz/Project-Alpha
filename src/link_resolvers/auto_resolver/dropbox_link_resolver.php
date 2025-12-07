<?php

class DropboxLinkResolver
{
    private $accessToken;
    private $rootPath;
    
    public function __construct(array $credentials)
    {
        $this->accessToken = $credentials['access_token'] ?? null;
        $this->rootPath = $credentials['root_path'] ?? '/';
        
        if (!$this->accessToken) {
            throw new \Exception('Dropbox access token not configured');
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
            
            // Find exact match that is a folder
            foreach ($data['matches'] as $match) {
                if (isset($match['metadata']['metadata']['.tag']) && 
                    $match['metadata']['metadata']['.tag'] === 'folder' &&
                    strtolower($match['metadata']['metadata']['name']) === strtolower($folderName)) {
                    return [
                        'success' => true,
                        'folder_id' => $match['metadata']['metadata']['path_lower']
                    ];
                }
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
