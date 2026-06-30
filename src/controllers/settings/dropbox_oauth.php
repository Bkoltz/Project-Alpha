<?php
// src/controllers/settings/dropbox_oauth.php
// Handles Dropbox OAuth flow for secure, permanent connection

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/logger.php';
require_once __DIR__ . '/../../utils/link_provider_config.php';

// Require authenticated user
if (!isset($_SESSION['user'])) {
    header('Location: /?page=login');
    exit;
}

$action = $_GET['action'] ?? '';
$userId = (int)$_SESSION['user']['id'];

// CSRF check
require_once __DIR__ . '/../../utils/csrf_sf.php';

function dropbox_oauth_is_secure_request(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos((string)$_SERVER['HTTP_CF_VISITOR'], 'https') !== false)
        || (!empty($_SERVER['HTTP_X_SCHEME']) && strtolower((string)$_SERVER['HTTP_X_SCHEME']) === 'https');
}

function dropbox_oauth_state_cookie_options(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'domain' => '',
        'secure' => dropbox_oauth_is_secure_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function dropbox_oauth_redirect_uri(?string $configuredHost = null): string
{
    $host = trim((string)($configuredHost ?? ''));
    $scheme = dropbox_oauth_is_secure_request() ? 'https' : 'http';
    if ($host !== '') {
        if (preg_match('#^https?://#i', $host)) {
            $parts = parse_url($host);
            $scheme = strtolower((string)($parts['scheme'] ?? 'https')) === 'http' ? 'http' : 'https';
            $host = (string)($parts['host'] ?? '');
            if (!empty($parts['port'])) {
                $host .= ':' . (int)$parts['port'];
            }
        } else {
            $host = preg_replace('#/.*$#', '', $host) ?? $host;
            $scheme = 'https';
        }
    }
    if ($host === '') {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    }
    return $scheme . '://' . $host . '/?page=settings/dropbox-oauth&action=callback';
}

// Dropbox app credentials (stored in app_config)
$dropboxAppKey = null;
$dropboxAppSecret = null;
$dropboxRedirectHost = null;
try {
    $st = $pdo->prepare('SELECT config_value FROM app_config WHERE config_key = ?');
    $st->execute(['dropbox_app_key']);
    $dropboxAppKey = $st->fetchColumn();
    
    $st = $pdo->prepare('SELECT config_value FROM app_config WHERE config_key = ?');
    $st->execute(['dropbox_app_secret']);
    $dropboxAppSecret = $st->fetchColumn();

    $st = $pdo->prepare('SELECT config_value FROM app_config WHERE config_key = ?');
    $st->execute(['app_host']);
    $dropboxRedirectHost = $st->fetchColumn() ?: null;
} catch (Throwable $e) {}

if ($action === 'start') {
    // Start OAuth flow
    if (empty($dropboxAppKey)) {
        header('Location: /?page=settings&tab=links&error=' . urlencode('Dropbox App Key not configured. Please add it in the settings.'));
        exit;
    }

    session_regenerate_id(false);
    
    // Generate state token for CSRF protection
    $state = bin2hex(random_bytes(32));
    $_SESSION['dropbox_oauth_state'] = $state;
    setcookie('pa_dropbox_oauth_state', $state, dropbox_oauth_state_cookie_options(time() + 600));
    
    // Build authorization URL
    $redirectUri = dropbox_oauth_redirect_uri(is_string($dropboxRedirectHost) ? $dropboxRedirectHost : null);
    app_log('dropbox_oauth', 'Starting OAuth authorization', [
        'user_id' => $userId,
        'redirect_uri' => $redirectUri,
        'host' => $_SERVER['HTTP_HOST'] ?? null,
    ]);
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
    $hadSessionState = isset($_SESSION['dropbox_oauth_state']);
    $hadCookieState = isset($_COOKIE['pa_dropbox_oauth_state']);
    $expectedState = (string)($_SESSION['dropbox_oauth_state'] ?? ($_COOKIE['pa_dropbox_oauth_state'] ?? ''));
    unset($_SESSION['dropbox_oauth_state']);
    setcookie('pa_dropbox_oauth_state', '', dropbox_oauth_state_cookie_options(time() - 3600));
    if (empty($state) || $expectedState === '' || !hash_equals($expectedState, $state)) {
        app_log('dropbox_oauth', 'Invalid OAuth state', [
            'has_session_state' => $hadSessionState,
            'has_cookie_state' => $hadCookieState,
        ]);
        header('Location: /?page=settings&tab=links&error=' . urlencode('Invalid OAuth state'));
        exit;
    }
    
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
    $redirectUri = dropbox_oauth_redirect_uri(is_string($dropboxRedirectHost) ? $dropboxRedirectHost : null);
    
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
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false || $httpCode !== 200) {
        app_log('dropbox_oauth', 'Token exchange failed', [
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'redirect_uri' => $redirectUri,
            'response' => is_string($response) ? substr($response, 0, 1000) : null,
        ]);
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
        $existingRow = pa_link_provider_best_row($pdo, 'dropbox');
        $existingCredentials = $existingRow ? pa_link_provider_credentials_from_row($existingRow) : [];
        // Update the Dropbox credentials in link_resolver_config
        // Store both access token and refresh token
        $credentials = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_expires_at' => $expiresIn ? date('Y-m-d H:i:s', time() + $expiresIn) : null,
            'root_path' => $existingCredentials['root_path'] ?? '/'
        ];
        pa_link_provider_save(
            $pdo,
            'dropbox',
            1,
            $credentials,
            $existingRow ? (int)($existingRow['default_expiration_days'] ?? 365) : 365
        );
        
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
