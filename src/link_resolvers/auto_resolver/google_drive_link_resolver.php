<?php

class GdriveLinkResolver
{
    private $serviceAccount;
    private $accessToken;
    private $rootPath;
    private string $serviceAccountEmail = '';
    private array $metadataCache = [];
    private ?array $lastError = null;

    public function __construct(array $credentials)
    {
        $this->serviceAccount = $credentials['service_account'] ?? null;
        $this->rootPath = trim((string)($credentials['root_path'] ?? ''));

        if (!$this->serviceAccount) {
            throw new \Exception('Google Drive service account not configured');
        }

        $serviceAccountData = json_decode($this->serviceAccount, true);
        if (!$serviceAccountData) {
            throw new \Exception('Invalid service account JSON');
        }
        $this->serviceAccountEmail = (string)($serviceAccountData['client_email'] ?? '');

        $this->accessToken = $this->getAccessToken($serviceAccountData);
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

    private function googleApiErrorMessage(int $httpCode, string $operation, string $response): array
    {
        $decoded = json_decode($response, true);
        $error = is_array($decoded) && isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : [];
        $detail = '';
        if (!empty($error['message'])) {
            $detail = ' Google said: ' . (string)$error['message'];
        } elseif (!empty($error['status'])) {
            $detail = ' Google said: ' . (string)$error['status'];
        }

        if ($httpCode === 401) {
            return [
                'Google Drive authentication failed.' . $detail,
                'Check the service account JSON and make sure the private key is current.',
            ];
        }
        if ($httpCode === 403) {
            return [
                'Google Drive denied permission for ' . $operation . '.' . $detail,
                'Share the target folders with the service account and verify Drive API permissions for this project.',
            ];
        }
        if ($httpCode === 404) {
            return [
                'Google Drive could not find the configured folder.' . $detail,
                'Check the root folder ID and make sure it is shared with the service account.',
            ];
        }

        return [
            'Google Drive ' . $operation . ' failed with HTTP ' . $httpCode . '.' . $detail,
            'Use Test Connection in Settings > Links and verify the Google service account has access to the target folders.',
        ];
    }

    private function request(string $method, string $url, ?array $payload = null): array
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ];
        if ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
        curl_setopt_array($ch, $options);

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
     * Get access token using service account.
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
                'iat' => $now,
            ];

            $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
            $payload = json_encode($jwt);

            $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode((string)$header));
            $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode((string)$payload));

            $signature = '';
            openssl_sign(
                $base64UrlHeader . '.' . $base64UrlPayload,
                $signature,
                $serviceAccount['private_key'],
                OPENSSL_ALGO_SHA256
            );

            $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
            $jwtToken = $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;

            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwtToken,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($response === false) {
                throw new \Exception('Failed to get Google access token: ' . $curlError);
            }

            $data = json_decode((string)$response, true);
            if ($httpCode !== 200 || empty($data['access_token'])) {
                $message = is_array($data) && !empty($data['error_description'])
                    ? (string)$data['error_description']
                    : 'Failed to get Google access token';
                throw new \Exception($message);
            }

            return (string)$data['access_token'];
        } catch (\Throwable $e) {
            @error_log('[GDrive] Token error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Search for a folder by exact name in Google Drive.
     */
    public function searchFolder(string $folderName): array
    {
        try {
            $query = "name = '" . addslashes($folderName) . "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";

            $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
                'q' => $query,
                'fields' => 'files(id,name,parents,mimeType,trashed)',
                'pageSize' => 100,
                'includeItemsFromAllDrives' => 'true',
                'supportsAllDrives' => 'true',
            ]);

            $result = $this->request('GET', $url);
            $response = $result['response'];
            $httpCode = (int)$result['http_code'];

            if ($response === false) {
                return $this->fail('folder search', 'Google Drive folder search could not reach Google: ' . (string)$result['curl_error'], 'Check outbound network access from the PA container/server.');
            }

            if ($httpCode !== 200) {
                [$message, $tip] = $this->googleApiErrorMessage($httpCode, 'folder search', (string)$response);
                @error_log('[GDrive] Search failed HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 500));
                return $this->fail('folder search', $message, $tip, $httpCode, (string)$response);
            }

            $data = json_decode((string)$response, true);

            if (empty($data['files']) || !is_array($data['files'])) {
                return ['success' => false, 'message' => 'Folder not found'];
            }

            $matches = [];
            foreach ($data['files'] as $file) {
                if (strtolower((string)($file['name'] ?? '')) !== strtolower($folderName)) {
                    continue;
                }
                if ($this->rootPath !== '' && $this->rootPath !== '/' && !$this->isSameOrDescendantOfRoot($file, $this->rootPath)) {
                    continue;
                }

                $parentId = (string)($file['parents'][0] ?? '');
                $parentName = $parentId !== '' ? $this->getFolderName($parentId) : '';
                $matches[] = [
                    'folder_id' => (string)$file['id'],
                    'name' => (string)$file['name'],
                    'parent_name' => $parentName,
                    'path' => ($parentName !== '' ? $parentName . '/' : '') . (string)$file['name'],
                ];
            }

            if (!$matches) {
                return ['success' => false, 'message' => 'Exact folder match not found'];
            }

            return [
                'success' => true,
                'matches' => $matches,
                'folder_id' => $matches[0]['folder_id'],
                'name' => $matches[0]['name'],
                'path' => $matches[0]['path'],
                'parent_name' => $matches[0]['parent_name'],
            ];
        } catch (\Throwable $e) {
            @error_log('[GDrive] Search error: ' . $e->getMessage());
            return $this->fail('folder search', $e->getMessage());
        }
    }

    private function getFolderName(string $folderId): string
    {
        $metadata = $this->getFileMetadata($folderId);
        return (string)($metadata['name'] ?? '');
    }

    private function getFileMetadata(string $fileId): array
    {
        if (isset($this->metadataCache[$fileId])) {
            return $this->metadataCache[$fileId];
        }

        $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?' . http_build_query([
            'fields' => 'id,name,parents,mimeType,trashed,webViewLink',
            'supportsAllDrives' => 'true',
        ]);

        $result = $this->request('GET', $url);
        $response = $result['response'];
        $httpCode = (int)$result['http_code'];

        if ($response === false) {
            $this->metadataCache[$fileId] = [];
            return [];
        }

        if ($httpCode !== 200) {
            @error_log('[GDrive] Metadata lookup failed HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 500));
            $this->metadataCache[$fileId] = [];
            return [];
        }

        $metadata = json_decode((string)$response, true);
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $this->metadataCache[$fileId] = $metadata;
        return $metadata;
    }

    private function isSameOrDescendantOfRoot(array $file, string $rootId): bool
    {
        if ((string)($file['id'] ?? '') === $rootId) {
            return true;
        }
        return $this->isDescendantOfRoot($file, $rootId);
    }

    private function isDescendantOfRoot(array $file, string $rootId): bool
    {
        $parents = array_map('strval', $file['parents'] ?? []);
        $seen = [];

        while ($parents) {
            $parentId = array_shift($parents);
            if ($parentId === $rootId) {
                return true;
            }
            if (isset($seen[$parentId])) {
                continue;
            }
            $seen[$parentId] = true;

            $metadata = $this->getFileMetadata($parentId);
            foreach (array_map('strval', $metadata['parents'] ?? []) as $ancestorId) {
                if (!isset($seen[$ancestorId])) {
                    $parents[] = $ancestorId;
                }
            }
        }

        return false;
    }

    public function testConnection(): array
    {
        if ($this->rootPath !== '' && $this->rootPath !== '/') {
            $metadata = $this->getFileMetadata($this->rootPath);
            if (empty($metadata['id'])) {
                return $this->fail('root folder check', 'Google Drive connected, but the root folder ID was not found or is not accessible.', 'Share the root folder with the service account or clear the root folder ID.');
            }
            if (($metadata['mimeType'] ?? '') !== 'application/vnd.google-apps.folder' || !empty($metadata['trashed'])) {
                return $this->fail('root folder check', 'Google Drive root ID is not an active folder.', 'Use a Google Drive folder ID as the root folder ID.');
            }
            return ['success' => true, 'message' => 'Connected to Google Drive as ' . ($this->serviceAccountEmail ?: 'service account') . '; root folder verified: ' . (string)($metadata['name'] ?? $this->rootPath)];
        }

        $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'pageSize' => 1,
            'fields' => 'files(id)',
            'includeItemsFromAllDrives' => 'true',
            'supportsAllDrives' => 'true',
        ]);
        $result = $this->request('GET', $url);
        $response = $result['response'];
        $httpCode = (int)$result['http_code'];
        if ($response === false) {
            return $this->fail('connection test', 'Google Drive connection test could not reach Google: ' . (string)$result['curl_error'], 'Check outbound network access from the PA container/server.');
        }
        if ($httpCode !== 200) {
            [$message, $tip] = $this->googleApiErrorMessage($httpCode, 'connection test', (string)$response);
            return $this->fail('connection test', $message, $tip, $httpCode, (string)$response);
        }

        return ['success' => true, 'message' => 'Connected to Google Drive as ' . ($this->serviceAccountEmail ?: 'service account')];
    }

    /**
     * Create or reuse an anyone-with-link reader permission for an exact folder ID.
     */
    public function generatePublicLink(string $folderId): ?string
    {
        try {
            $metadata = $this->getFileMetadata($folderId);
            if (empty($metadata['id'])) {
                $this->fail('folder metadata', 'Google Drive could not verify the matched folder before sharing.', 'Make sure the folder is still shared with the service account.');
                return null;
            }
            if (($metadata['mimeType'] ?? '') !== 'application/vnd.google-apps.folder' || !empty($metadata['trashed'])) {
                $this->fail('folder metadata', 'Google Drive matched item is not an active folder.', 'Check the folder name and root folder ID in Settings > Links.');
                return null;
            }
            if ($this->rootPath !== '' && $this->rootPath !== '/' && !$this->isSameOrDescendantOfRoot($metadata, $this->rootPath)) {
                $this->fail('folder metadata', 'Google Drive matched folder is outside the configured root folder.', 'Check the root folder ID before running the resolver again.');
                return null;
            }

            $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($folderId) . '/permissions?' . http_build_query([
                'supportsAllDrives' => 'true',
                'fields' => 'id',
            ]);
            $result = $this->request('POST', $url, [
                'role' => 'reader',
                'type' => 'anyone',
                'allowFileDiscovery' => false,
            ]);
            $response = $result['response'];
            $httpCode = (int)$result['http_code'];

            if ($response === false) {
                $this->fail('create share permission', 'Google Drive permission creation could not reach Google: ' . (string)$result['curl_error'], 'Check outbound network access from the PA container/server.');
                return null;
            }

            if (!in_array($httpCode, [200, 201, 409], true)) {
                [$message, $tip] = $this->googleApiErrorMessage($httpCode, 'create share permission', (string)$response);
                @error_log('[GDrive] Create permission failed HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 500));
                $this->fail('create share permission', $message, $tip, $httpCode, (string)$response);
                return null;
            }

            return (string)($metadata['webViewLink'] ?? ('https://drive.google.com/drive/folders/' . rawurlencode($folderId)));
        } catch (\Throwable $e) {
            @error_log('[GDrive] Generate link error: ' . $e->getMessage());
            $this->fail('create share permission', $e->getMessage());
            return null;
        }
    }
}
