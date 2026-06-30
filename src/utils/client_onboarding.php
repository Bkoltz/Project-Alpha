<?php

require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/audit.php';

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

function client_onboarding_mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($domain === '') {
        return 'your email address';
    }
    $visible = mb_substr($local, 0, min(2, mb_strlen($local)));
    return $visible . str_repeat('*', max(3, mb_strlen($local) - mb_strlen($visible))) . '@' . $domain;
}

function client_onboarding_send_code(PDO $pdo, array $invite, array $appConfig): bool
{
    $code = (string)random_int(100000, 999999);
    $pdo->prepare(
        'UPDATE client_onboarding_invitations
         SET verification_code_hash=?,code_expires_at=DATE_ADD(NOW(),INTERVAL 15 MINUTE),
             verification_attempts=0,last_code_sent_at=NOW()
         WHERE id=? AND status="pending"'
    )->execute([password_hash($code, PASSWORD_DEFAULT), (int)$invite['id']]);

    $brand = htmlspecialchars((string)($appConfig['brand_name'] ?? 'Project Alpha'));
    [$ok, $error] = EmailService::sendEmail(
        (string)$invite['invited_email'],
        'Your client onboarding verification code',
        '<p>Your verification code for ' . $brand . ' is:</p><p style="font-size:28px;font-weight:700;letter-spacing:4px">' . $code . '</p><p>This code expires in 15 minutes.</p>'
    );
    if (!$ok) {
        @error_log('[client_onboarding] Verification email failed: ' . $error);
    }
    return $ok;
}

function client_onboarding_clean_text(mixed $value, int $maxLength): string
{
    $value = trim((string)$value);
    return mb_substr($value, 0, $maxLength);
}
