<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/crypto.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../services/EmailService.php';
require_once __DIR__ . '/../../services/EmailProviderManager.php';

if (!in_array((string)($_SESSION['user']['role'] ?? ''), ['admin','owner'], true)) {
    http_response_code(403);
    exit('Only an installation administrator can connect Google email.');
}

function gmail_oauth_redirect_uri(array $appConfig): string
{
    $host = trim((string)($appConfig['app_host'] ?? ''));
    if ($host !== '' && !preg_match('#^https?://#i', $host)) {
        $host = 'https://' . $host;
    }
    if ($host === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https' ? 'https' : 'http';
        $host = $scheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return rtrim($host, '/') . '/?page=settings/gmail-oauth&action=callback';
}

function gmail_oauth_post(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'], CURLOPT_POSTFIELDS => http_build_query($fields)]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($body === false ? '' : $body, true)];
}

function gmail_oauth_userinfo(string $accessToken): array
{
    $ch = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($body === false ? '' : $body, true)];
}

$action = (string)($_GET['action'] ?? 'connect');
$clientId = trim((string)($appConfig['google_oauth_client_id'] ?? ''));
$clientSecret = crypto_decrypt((string)($appConfig['google_oauth_client_secret_enc'] ?? ''));
$return = '/?page=settings&tab=system';
if ($clientId === '' || !is_string($clientSecret) || $clientSecret === '') {
    header('Location: ' . $return . '&email_err=' . rawurlencode('Save the Google OAuth client ID and secret first.'));
    exit;
}

if ($action === 'connect') {
    $csrf = (string)($_GET['csrf'] ?? '');
    if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
        header('Location: ' . $return . '&email_err=' . rawurlencode('Invalid Google connection request.'));
        exit;
    }
    $state = bin2hex(random_bytes(24));
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $_SESSION['gmail_oauth'] = ['state' => $state, 'verifier' => $verifier, 'created_at' => time()];
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $query = http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => gmail_oauth_redirect_uri($appConfig),
        'response_type' => 'code',
        'scope' => 'openid email https://www.googleapis.com/auth/gmail.send',
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query);
    exit;
}

$pending = $_SESSION['gmail_oauth'] ?? null;
unset($_SESSION['gmail_oauth']);
if (!is_array($pending) || time() - (int)($pending['created_at'] ?? 0) > 600
    || !hash_equals((string)($pending['state'] ?? ''), (string)($_GET['state'] ?? '')) || empty($_GET['code'])) {
    header('Location: ' . $return . '&email_err=' . rawurlencode('Google authorization expired or was rejected. Try connecting again.'));
    exit;
}

[$status, $tokens] = gmail_oauth_post('https://oauth2.googleapis.com/token', [
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'code' => (string)$_GET['code'],
    'code_verifier' => (string)$pending['verifier'],
    'redirect_uri' => gmail_oauth_redirect_uri($appConfig),
    'grant_type' => 'authorization_code',
]);
if ($status < 200 || $status >= 300 || !is_array($tokens) || empty($tokens['refresh_token'])) {
    header('Location: ' . $return . '&email_err=' . rawurlencode('Google did not return an offline refresh token. Reconnect and grant email permission.'));
    exit;
}

[$userinfoStatus, $userinfo] = gmail_oauth_userinfo((string)($tokens['access_token'] ?? ''));
$email = trim((string)($userinfo['email'] ?? ''));
if ($userinfoStatus < 200 || $userinfoStatus >= 300 || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($userinfo['email_verified'])) {
    header('Location: ' . $return . '&email_err=' . rawurlencode('Google did not provide a verified sender email.'));
    exit;
}

try {
    $manager = new EmailProviderManager($pdo, $appConfig);
    $id = $manager->upsertGmail([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => (string)$tokens['refresh_token'],
        'access_token' => (string)($tokens['access_token'] ?? ''),
        'access_token_expires_at' => time() + (int)($tokens['expires_in'] ?? 3600),
    ], $email, EmailService::getFromName($appConfig), (int)($_SESSION['user']['id'] ?? 0));
    $manager->activate($id, (int)($_SESSION['user']['id'] ?? 0));
    header('Location: ' . $return . '&email_connected=1');
} catch (Throwable $error) {
    @error_log('[gmail-oauth] ' . $error->getMessage());
    header('Location: ' . $return . '&email_err=' . rawurlencode('Google connected, but the encrypted credentials could not be saved.'));
}
exit;
