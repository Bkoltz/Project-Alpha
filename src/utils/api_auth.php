<?php
// src/utils/api_auth.php
if (!defined('PA_STATELESS_API_NO_SESSION') && session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/api_keys_schema.php';
require_once __DIR__ . '/api_scopes.php';
require_once __DIR__ . '/api_ip_allowlist.php';

function api_json_error(int $code, string $msg): void {
    header('Content-Type: application/json');
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function api_get_token(): ?string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($hdr && stripos($hdr, 'bearer ') === 0) {
        return trim(substr($hdr, 7));
    }
    $alt = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($alt) return trim($alt);
    return null;
}

require_once __DIR__ . '/client_ip.php';

function api_get_client_ip(): string {
    return get_client_ip();
}

function api_require_key(
    array $requiredScopes = [],
    bool $allowFullScope = true
) {
    global $pdo;
    $token = api_get_token();
    if (!$token) api_json_error(401, 'Missing API key');
    $hash = hash('sha256', $token);
    try {
        pa_ensure_api_keys_schema($pdo);
        $st = $pdo->prepare('SELECT * FROM api_keys WHERE key_hash=? AND (revoked_at IS NULL)');
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) api_json_error(401, 'Invalid API key');
        // Optional IP allowlist
        $ip = get_client_ip();
        if (trim((string)($row['allowed_ips'] ?? '')) !== '') {
            $ips = api_parse_exact_ip_allowlist($row['allowed_ips']);
            if ($ips === [] || !api_ip_matches_exact_allowlist($ip, $ips)) api_json_error(403, 'IP not allowed');
        }
        // Scope check (simple CSV/JSON list in column)
        if ($requiredScopes) {
            foreach ($requiredScopes as $need) {
                if (!api_key_has_scope($row['scopes'] ?? '', (string)$need, $allowFullScope)) {
                    api_json_error(403, 'Insufficient API scope: ' . (string)$need);
                }
            }
        }
        // Rate limit per key (per-minute)
        $limit = (int)(getenv('API_RATE_LIMIT_PER_MIN') ?: 60);
        if ($limit < 1) $limit = 60;
        $since = date('Y-m-d H:i:s', time() - 60);
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM api_usage WHERE api_key_id=? AND used_at>=?');
        $cnt->execute([(int)$row['id'], $since]);
        if ((int)$cnt->fetchColumn() >= $limit) api_json_error(429, 'Rate limit exceeded');
        // Record usage and touch last_used
        try {
            $pdo->prepare('INSERT INTO api_usage (api_key_id) VALUES (?)')->execute([(int)$row['id']]);
            $pdo->prepare('UPDATE api_keys SET last_used_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
        } catch (Throwable $e) {}
        $GLOBALS['pa_api_key'] = $row;
        // API keys are explicit service principals. They never synthesize an
        // administrator user session, so future write-capable APIs cannot
        // accidentally inherit interactive-admin authorization.
        $servicePrincipal = [
            'type' => 'api_key',
            'api_key_id' => (int)$row['id'],
            'name' => (string)($row['name'] ?? $row['key_prefix'] ?? 'external'),
            'scopes' => api_normalize_scopes($row['scopes'] ?? ''),
        ];
        $GLOBALS['pa_service_principal'] = $servicePrincipal;
        return $row;
    } catch (Throwable $e) {
        api_json_error(500, 'API auth error');
    }
}
