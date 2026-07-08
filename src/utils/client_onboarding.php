<?php

require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/crypto.php';

function client_onboarding_base_url(array $appConfig): string
{
    $host = trim((string)($appConfig['app_host'] ?? ''));
    if ($host !== '') {
        return rtrim(preg_match('#^https?://#i', $host) ? $host : 'https://' . $host, '/');
    }
    $requestHost = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    return ($https ? 'https' : 'http') . '://' . $requestHost;
}

function client_onboarding_find_invitation(PDO $pdo, string $token, bool $lock = false): ?array
{
    client_onboarding_revoke_stale($pdo);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $sql = 'SELECT * FROM client_onboarding_invitations WHERE token_hash=? LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([hash('sha256', $token)]);
    $invite = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invite) {
        return null;
    }
    if (($invite['status'] ?? '') === 'pending' && strtotime((string)$invite['expires_at']) < time()) {
        $pdo->prepare('UPDATE client_onboarding_invitations SET status="expired" WHERE id=? AND status="pending"')
            ->execute([(int)$invite['id']]);
        $invite['status'] = 'expired';
    }
    return $invite;
}

function client_onboarding_revoke_stale(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $pdo->prepare(
            'UPDATE client_onboarding_invitations
             SET status="revoked", consumed_at=NOW()
             WHERE status IN ("pending","verified")
               AND (expires_at < NOW() OR created_at < DATE_SUB(NOW(), INTERVAL 14 DAY))'
        )->execute();
    } catch (Throwable $e) {
        @error_log('[client_onboarding] Stale revocation failed: ' . $e->getMessage());
    }

    $done = true;
}

function client_onboarding_store_token(string $token): ?string
{
    return crypto_encrypt($token);
}

function client_onboarding_token_from_invitation(array $invitation): string
{
    $encrypted = trim((string)($invitation['token_enc'] ?? ''));
    if ($encrypted === '') {
        return '';
    }
    return crypto_decrypt($encrypted) ?: '';
}

function client_onboarding_link_for_invitation(array $appConfig, array $invitation): string
{
    $token = client_onboarding_token_from_invitation($invitation);
    if ($token === '') {
        return '';
    }
    return client_onboarding_base_url($appConfig) . '/?page=client-onboarding&token=' . rawurlencode($token);
}

function client_onboarding_clean_text(mixed $value, int $maxLength): string
{
    $value = str_replace("\0", '', (string)$value);
    $value = strip_tags($value);
    $value = preg_replace('/[^\P{C}\t\r\n]/u', '', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    $value = trim($value);
    return mb_substr($value, 0, $maxLength);
}
