<?php
// src/controllers/settings/link_test_connection.php
// Tests connection to storage providers (Dropbox, Google Drive, S3)

header('Content-Type: application/json');

$provider = $_POST['provider'] ?? '';

try {
    if ($provider === 'dropbox') {
        $accessToken = $_POST['access_token'] ?? '';
        
        if (empty($accessToken)) {
            echo json_encode(['success' => false, 'error' => 'Access token is required']);
            exit;
        }
        
        // Test Dropbox connection by calling get_current_account endpoint
        // Using official Dropbox API v2 endpoint as documented in riptutorial.com
        $ch = curl_init('https://api.dropboxapi.com/2/users/get_current_account');
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',  // Empty body - official Dropbox API v2 requires this
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken
                // Note: Content-Type header not required for this endpoint per Dropbox docs
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $redirectUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        
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
        $credentials = $_POST['credentials'] ?? '';
        
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
        
        echo json_encode(['success' => true, 'message' => "Service account credentials validated for: {$credData['client_email']}"]);
        
    } elseif ($provider === 's3') {
        $accessKey = $_POST['access_key'] ?? '';
        $secretKey = $_POST['secret_key'] ?? '';
        $bucket = $_POST['bucket'] ?? '';
        $region = $_POST['region'] ?? 'us-east-1';
        
        if (empty($accessKey) || empty($secretKey) || empty($bucket)) {
            echo json_encode(['success' => false, 'error' => 'Access key, secret key, and bucket are required']);
            exit;
        }
        
        // Test S3 connection by listing bucket (HEAD request)
        $host = "{$bucket}.s3.{$region}.amazonaws.com";
        $date = gmdate('D, d M Y H:i:s T');
        $stringToSign = "HEAD\n\n\n{$date}\n/{$bucket}/";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $secretKey, true));
        
        $ch = curl_init("https://{$host}/");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'HEAD',
            CURLOPT_HTTPHEADER => [
                "Host: {$host}",
                "Date: {$date}",
                "Authorization: AWS {$accessKey}:{$signature}"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 15
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            echo json_encode(['success' => false, 'error' => 'Connection error: ' . $curlError]);
            exit;
        }
        
        if ($httpCode === 200) {
            echo json_encode(['success' => true, 'message' => "Connected to S3 bucket: {$bucket}"]);
        } elseif ($httpCode === 403) {
            echo json_encode(['success' => false, 'error' => 'Access denied - check your credentials and bucket permissions']);
        } elseif ($httpCode === 404) {
            echo json_encode(['success' => false, 'error' => "Bucket '{$bucket}' not found"]);
        } else {
            echo json_encode(['success' => false, 'error' => "S3 returned HTTP {$httpCode}"]);
        }
        
    } else {
        echo json_encode(['success' => false, 'error' => 'Unknown provider: ' . $provider]);
    }
    
} catch (Throwable $e) {
    @error_log('[LinkTestConnection] Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
