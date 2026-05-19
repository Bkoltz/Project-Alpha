<?php
// src/controllers/settings/dropbox_oauth.php
// Handles Dropbox OAuth flow for secure, permanent connection

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/logger.php';

// Require authenticated user
if (!isset($_SESSION['user'])) {
    header('Location: /?page=login');
    exit;
}

$action = $_GET['action'] ?? '';
$userId = (int)$_SESSION['user']['id'];

// CSRF check
require_once __DIR__ . '/../../utils/csrf_sf.php';

// Dropbox app credentials (stored in app_config)
$dropboxAppKey = null;
$dropboxAppSecret = null;
try {
    $st = $pdo->prepare('SELECT config_value FROM app_config WHERE config_key = ?');
    $st->execute(['dropbox_app_key']);
    $dropboxAppKey = $st->fetchColumn();
    
    $st = $pdo->prepare('SELECT config_value FROM app_config WHERE config_key = ?');
    $st->execute(['dropbox_app_secret']);
    $dropboxAppSecret = $st->fetchColumn();
} catch (Throwable $e) {}

if ($action === 'start') {
    // Start OAuth flow
    if (empty($dropboxAppKey)) {
        header('Location: /?page=settings&tab=links&error=' . urlencode('Dropbox App Key not configured. Please add it in the settings.'));
        exit;
    }
    
    // Generate state token for CSRF protection
    $state = bin2hex(random_bytes(32));
    $_SESSION['dropbox_oauth_state'] = $state;
    
    // Build authorization URL
    $redirectUri = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/?page=settings/dropbox-oauth&action=callback';
    $authUrl = 'https://www.dropbox.com/oauth2/authorize';
    $params = http_build_query([
        'client_id' => $dropboxAppKey,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'state' => $state,
        'token_access_type' => 'offline' // Request refresh token
    ]);
    
    header('Location: ' . $authUrl . '?' . $params);
    exit;
}

if ($action === 'callback') {
    // Handle OAuth callback
    $code = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';
    
    // Verify state
    if (empty($state) || !isset($_SESSION['dropbox_oauth_state']) || !hash_equals($_SESSION['dropbox_oauth_state'], $state)) {
        header('Location: /?page=settings&tab=links&error=' . urlencode('Invalid OAuth state'));
        exit;
    }
    
    unset($_SESSION['dropbox_oauth_state']);
    
    if (empty($code)) {
        $error = $_GET['error_description'] ?? 'OAuth authorization failed';
        header('Location: /?page=settings&tab=links&error=' . urlencode($error));
        exit;
    }
    
    if (empty($dropboxAppKey) || empty($dropboxAppSecret)) {
        header('Location: /?page=settings&tab=links&error=' . urlencode('Dropbox app credentials not configured'));
        exit;
    }
    
    // Exchange code for tokens
    $redirectUri = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/?page=settings/dropbox-oauth&action=callback';
    
    $ch = curl_init('https://api.dropboxapi.com/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => $dropboxAppKey,
            'client_secret' => $dropboxAppSecret,
            'redirect_uri' => $redirectUri
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        app_log('dropbox_oauth', 'Token exchange failed', ['http_code' => $httpCode, 'response' => $response]);
        header('Location: /?page=settings&tab=links&error=' . urlencode('Failed to connect to Dropbox'));
        exit;
    }
    
    $tokenData = json_decode($response, true);
    if (empty($tokenData['access_token'])) {
        header('Location: /?page=settings&tab=links&error=' . urlencode('Invalid token response from Dropbox'));
        exit;
    }
    
    // Store tokens securely
    $accessToken = $tokenData['access_token'];
    $refreshToken = $tokenData['refresh_token'] ?? null;
    $expiresIn = $tokenData['expires_in'] ?? null;
    
    try {
        // Update the Dropbox credentials in link_resolver_config
        // Store both access token and refresh token
        $credentials = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_expires_at' => $expiresIn ? date('Y-m-d H:i:s', time() + $expiresIn) : null,
            'root_path' => '/'
        ];
        
        $stmt = $pdo->prepare("
            INSERT INTO link_resolver_config (provider, is_enabled, credentials)
            VALUES ('dropbox', 1, ?)
            ON DUPLICATE KEY UPDATE 
                is_enabled = 1,
                credentials = ?
        ");
        $stmt->execute([json_encode($credentials), json_encode($credentials)]);
        
        app_log('dropbox_oauth', 'OAuth connected successfully', ['user_id' => $userId]);
        header('Location: /?page=settings&tab=links&saved=1');
        exit;
    } catch (Throwable $e) {
        app_log('dropbox_oauth', 'Failed to save tokens', ['error' => $e->getMessage()]);
        header('Location: /?page=settings&tab=links&error=' . urlencode('Failed to save credentials'));
        exit;
    }
}

if ($action === 'disconnect') {
    // Disconnect Dropbox OAuth
    try {
        // Revoke the token with Dropbox first
        $stmt = $pdo->prepare('SELECT credentials FROM link_resolver_config WHERE provider = ?');
        $stmt->execute(['dropbox']);
        $credentials = $stmt->fetchColumn();
        
        if ($credentials) {
            $creds = json_decode($credentials, true);
            if (!empty($creds['access_token'])) {
                // Revoke token with Dropbox
                $ch = curl_init('https://api.dropboxapi.com/2/auth/token/revoke');
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $creds['access_token']
                    ],
                    CURLOPT_RETURNTRANSFER => true
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        }
        
        // Clear credentials from database
        $stmt = $pdo->prepare("
            UPDATE link_resolver_config 
            SET credentials = NULL, is_enabled = 0 
            WHERE provider = 'dropbox'
        ");
        $stmt->execute();
        
        app_log('dropbox_oauth', 'Disconnected successfully', ['user_id' => $userId]);
        header('Location: /?page=settings&tab=links&saved=1');
        exit;
    } catch (Throwable $e) {
        app_log('dropbox_oauth', 'Disconnect error', ['error' => $e->getMessage()]);
        header('Location: /?page=settings&tab=links&error=' . urlencode('Failed to disconnect'));
        exit;
    }
}

header('Location: /?page=settings&tab=links');
exit;
