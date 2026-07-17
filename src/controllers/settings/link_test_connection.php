<?php
// src/controllers/settings/link_test_connection.php
// Tests connection to storage providers (Dropbox, Google Drive, Amazon S3, Cloudflare R2)

require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/link_provider_config.php';
require_once __DIR__ . '/../../link_resolvers/auto_resolver/google_drive_link_resolver.php';
require_once __DIR__ . '/../../link_resolvers/auto_resolver/s3_link_resolver.php';
require_once __DIR__ . '/../../link_resolvers/auto_resolver/r2_link_resolver.php';

header('Content-Type: application/json');

// Require authenticated session (this endpoint can probe credentials)
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// CSRF check (JSON-friendly)
if (!csrf_validate()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$provider = $_POST['provider'] ?? '';

try {
    if ($provider === 'dropbox') {
        $accessToken = $_POST['access_token'] ?? '';
        $rootPath = trim((string)($_POST['root_path'] ?? '/'));
        if ($accessToken === '') {
            $row = pa_link_provider_best_row($pdo, 'dropbox');
            $credentials = $row ? pa_link_provider_credentials_from_row($row) : [];
            if ($credentials) {
                $accessToken = (string)($credentials['access_token'] ?? '');
                if ($rootPath === '') {
                    $rootPath = (string)($credentials['root_path'] ?? '/');
                }
            }
        }
        
        if (empty($accessToken)) {
            echo json_encode(['success' => false, 'error' => 'Dropbox is not connected yet. Use Connect Dropbox or provide a legacy access token.']);
            exit;
        }
        
        // Test Dropbox connection by calling get_current_account endpoint
        // Dropbox API v2 RPC endpoints require Content-Type: application/json and null body
        $ch = curl_init('https://api.dropboxapi.com/2/users/get_current_account');
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'null',  // JSON null - required for RPC endpoints with no parameters
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'  // Required for Dropbox API v2 RPC calls
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $redirectUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        
        // Debug: log full response for troubleshooting
        if ($httpCode !== 200) {
            @error_log('[Dropbox] Response HTTP ' . $httpCode . ' URL: ' . $redirectUrl . ' Body: ' . substr($response, 0, 500));
        }
        
        if ($curlError) {
            echo json_encode(['success' => false, 'error' => 'Connection error: ' . $curlError]);
            exit;
        }
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $accountName = $data['name']['display_name'] ?? 'Unknown';
            $normalizedRoot = $rootPath === '' ? '/' : $rootPath;
            if ($normalizedRoot !== '/') {
                $metadataPath = '/' . ltrim($normalizedRoot, '/');
                $ch = curl_init('https://api.dropboxapi.com/2/files/get_metadata');
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['path' => $metadataPath]),
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $accessToken,
                        'Content-Type: application/json'
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15
                ]);
                $metadataResponse = curl_exec($ch);
                $metadataHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $metadataCurlError = curl_error($ch);

                if ($metadataCurlError) {
                    echo json_encode(['success' => false, 'error' => 'Connected to Dropbox, but root path check failed: ' . $metadataCurlError]);
                    exit;
                }
                if ($metadataHttpCode !== 200) {
                    $metadataError = json_decode((string)$metadataResponse, true);
                    $summary = is_array($metadataError) ? (string)($metadataError['error_summary'] ?? '') : '';
                    echo json_encode(['success' => false, 'error' => 'Connected to Dropbox, but the root folder path was not found or is not accessible' . ($summary !== '' ? ': ' . $summary : '')]);
                    exit;
                }
            }

            echo json_encode(['success' => true, 'message' => "Connected to Dropbox account: {$accountName}"]);
        } elseif ($httpCode === 401) {
            echo json_encode(['success' => false, 'error' => 'Invalid or expired access token']);
        } else {
            // If response is HTML, we need to handle it differently
            if (stripos($response, '<!doctype') !== false || stripos($response, '<html') !== false) {
                @error_log('[Dropbox] Received HTML instead of JSON: ' . substr($response, 0, 500));
                echo json_encode(['success' => false, 'error' => "Received HTML response from Dropbox. HTTP {$httpCode}"]);
            } else {
                $errorData = json_decode($response, true);
                $errorMsg = $errorData['error_summary'] ?? "HTTP {$httpCode}";
                echo json_encode(['success' => false, 'error' => $errorMsg]);
            }
        }
        
    } elseif ($provider === 'gdrive') {
        $credentials = trim((string)($_POST['credentials'] ?? ''));
        $rootPath = trim((string)($_POST['root_path'] ?? ''));
        if ($credentials === '') {
            $row = pa_link_provider_best_row($pdo, 'gdrive');
            $stored = $row ? pa_link_provider_credentials_from_row($row) : [];
            $credentials = (string)($stored['service_account'] ?? '');
        }
        
        if (empty($credentials)) {
            echo json_encode(['success' => false, 'error' => 'Service account credentials are required']);
            exit;
        }
        
        // Validate JSON format
        $credData = json_decode($credentials, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['success' => false, 'error' => 'Invalid JSON format for service account credentials']);
            exit;
        }
        
        // Check required fields
        if (empty($credData['client_email']) || empty($credData['private_key'])) {
            echo json_encode(['success' => false, 'error' => 'Service account JSON must contain client_email and private_key']);
            exit;
        }

        $resolver = new GdriveLinkResolver([
            'service_account' => $credentials,
            'root_path' => $rootPath,
        ]);
        $result = $resolver->testConnection();
        echo json_encode([
            'success' => !empty($result['success']),
            'message' => $result['message'] ?? null,
            'error' => empty($result['success']) ? ($result['message'] ?? 'Google Drive connection failed') : null,
            'tip' => $result['tip'] ?? null,
        ]);
        
    } elseif ($provider === 's3') {
        $accessKey = trim((string)($_POST['access_key'] ?? ''));
        $secretKey = trim((string)($_POST['secret_key'] ?? ''));
        $bucket = trim((string)($_POST['bucket'] ?? ''));
        $region = trim((string)($_POST['region'] ?? 'us-east-1')) ?: 'us-east-1';
        $publicBaseUrl = trim((string)($_POST['public_base_url'] ?? ''));
        $rootPath = trim((string)($_POST['root_path'] ?? ''), '/');

        $row = pa_link_provider_best_row($pdo, 's3');
        $stored = $row ? pa_link_provider_credentials_from_row($row) : [];
        if ($accessKey === '') {
            $accessKey = (string)($stored['access_key'] ?? '');
        }
        if ($secretKey === '') {
            $secretKey = (string)($stored['secret_key'] ?? '');
        }
        
        if (empty($accessKey) || empty($secretKey) || empty($bucket)) {
            echo json_encode(['success' => false, 'error' => 'Access key, secret key, and bucket are required']);
            exit;
        }

        $resolver = new S3LinkResolver([
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
            'bucket' => $bucket,
            'region' => $region,
            'public_base_url' => $publicBaseUrl,
            'root_path' => $rootPath,
            'session_token' => (string)($stored['session_token'] ?? ''),
        ]);
        $result = $resolver->testConnection();
        echo json_encode([
            'success' => !empty($result['success']),
            'message' => $result['message'] ?? null,
            'error' => empty($result['success']) ? ($result['message'] ?? 'S3 connection failed') : null,
            'tip' => $result['tip'] ?? null,
        ]);

    } elseif ($provider === 'r2') {
        $accountId = trim((string)($_POST['account_id'] ?? ''));
        $accessKey = trim((string)($_POST['access_key'] ?? ''));
        $secretKey = trim((string)($_POST['secret_key'] ?? ''));
        $bucket = trim((string)($_POST['bucket'] ?? ''));
        $endpoint = trim((string)($_POST['endpoint'] ?? ''));
        $publicBaseUrl = trim((string)($_POST['public_base_url'] ?? ''));
        $rootPath = trim((string)($_POST['root_path'] ?? ''), '/');

        $row = pa_link_provider_best_row($pdo, 'r2');
        $stored = $row ? pa_link_provider_credentials_from_row($row) : [];
        if ($accessKey === '') {
            $accessKey = (string)($stored['access_key'] ?? '');
        }
        if ($secretKey === '') {
            $secretKey = (string)($stored['secret_key'] ?? '');
        }

        if (($accountId === '' && $endpoint === '') || $accessKey === '' || $secretKey === '' || $bucket === '') {
            echo json_encode(['success' => false, 'error' => 'Account ID (or endpoint), access key, secret key, and bucket are required']);
            exit;
        }

        $resolver = new R2LinkResolver([
            'account_id' => $accountId,
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
            'bucket' => $bucket,
            'endpoint' => $endpoint,
            'public_base_url' => $publicBaseUrl,
            'root_path' => $rootPath,
            'session_token' => (string)($stored['session_token'] ?? ''),
        ]);
        $result = $resolver->testConnection();
        echo json_encode([
            'success' => !empty($result['success']),
            'message' => $result['message'] ?? null,
            'error' => empty($result['success']) ? ($result['message'] ?? 'Cloudflare R2 connection failed') : null,
            'tip' => $result['tip'] ?? null,
        ]);
        
    } else {
        echo json_encode(['success' => false, 'error' => 'Unknown provider: ' . $provider]);
    }
    
} catch (Throwable $e) {
    @error_log('[LinkTestConnection] Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
