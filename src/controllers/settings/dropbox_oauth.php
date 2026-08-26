<?php
// src/controllers/settings/dropbox_oauth.php
// Handles Dropbox OAuth flow for secure, permanent connection

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/logger.php';
require_once __DIR__ . '/../../utils/link_provider_config.php';
require_once __DIR__ . '/../../utils/resolver_link_policy.php';
require_once __DIR__ . '/../../utils/request_security.php';

// Require authenticated user
if (!isset($_SESSION['user'])) {
    header('Location: /?page=login');
    exit;
}

$action = $_GET['action'] ?? '';
$userId = (int)$_SESSION['user']['id'];

// CSRF check
require_once __DIR__ . '/../../utils/csrf_sf.php';


function dropbox_oauth_redirect_uri(?string $configuredHost = null): string
{
    $host = trim((string)($configuredHost ?? ''));
    $scheme = request_is_https() ? 'https' : 'http';
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
    // Start OAuth flow from an authenticated, CSRF-protected form submission.
    $csrf = (string)($_POST['csrf'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
        || $csrf === ''
        || !hash_equals(csrf_token(), $csrf)) {
        http_response_code(405);
        header('Allow: POST');
        exit('Invalid Dropbox connection request.');
    }
    if (empty($dropboxAppKey)) {
        header('Location: /?page=settings&tab=links&error=' . urlencode('Dropbox App Key not configured. Please add it in the settings.'));
        exit;
    }

    App\Security\SessionPolicy::rotateAuthenticatedId();

    // State stays bound to this authenticated session and expires after ten minutes.
    $state = bin2hex(random_bytes(32));
    $_SESSION['dropbox_oauth'] = ['state' => $state, 'created_at' => time()];
    
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
    
    // Verify single-use, session-bound state and its ten-minute lifetime.
    $pending = $_SESSION['dropbox_oauth'] ?? null;
    unset($_SESSION['dropbox_oauth']);
    $expectedState = is_array($pending) ? (string)($pending['state'] ?? '') : '';
    $stateAge = is_array($pending) ? time() - (int)($pending['created_at'] ?? 0) : PHP_INT_MAX;
    if (empty($state) || $expectedState === '' || $stateAge < 0 || $stateAge > 600 || !hash_equals($expectedState, $state)) {
        app_log('dropbox_oauth', 'Invalid OAuth state', [
            'has_session_state' => is_array($pending),
            'state_age' => $stateAge,
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
    if (PHP_VERSION_ID < 80500) {
        curl_close($ch);
    }
    
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
    $submitted = (string)($_POST['csrf'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
        || $submitted === ''
        || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(405);
        header('Allow: POST');
        exit('Invalid Dropbox disconnect request.');
    }
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
                if (PHP_VERSION_ID < 80500) {
                    curl_close($ch);
                }
            }
        }
        
        // Explicit disconnect revokes credentials; disabling auto-generation
        // through settings keeps them. Both remove cached resolver URLs.
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            UPDATE link_resolver_config 
            SET credentials = NULL, is_enabled = 0 
            WHERE provider = 'dropbox'
        ");
        $stmt->execute();
        pa_remove_disabled_resolver_links($pdo, 'dropbox');
        $pdo->commit();
        
        app_log('dropbox_oauth', 'Disconnected successfully', ['user_id' => $userId]);
        header('Location: /?page=settings&tab=links&saved=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        app_log('dropbox_oauth', 'Disconnect error', ['error' => $e->getMessage()]);
        header('Location: /?page=settings&tab=links&error=' . urlencode('Failed to disconnect'));
        exit;
    }
}

header('Location: /?page=settings&tab=links');
exit;
