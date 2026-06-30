<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
require_once __DIR__ . '/../../utils/client_onboarding.php';

$token = trim((string)($_POST['token'] ?? ''));
$code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
if (!rate_limit_check($pdo, 'client_onboarding_verify', 10, 900, false)) {
    http_response_code(429);
    echo 'Please wait before trying again.';
    exit;
}

try {
    $pdo->beginTransaction();
    $invite = client_onboarding_find_invitation($pdo, $token, true);
    $valid = $invite
        && ($invite['status'] ?? '') === 'pending'
        && !empty($invite['verification_code_hash'])
        && !empty($invite['code_expires_at'])
        && strtotime((string)$invite['code_expires_at']) >= time()
        && (int)$invite['verification_attempts'] < 5
        && strlen($code) === 6
        && password_verify($code, (string)$invite['verification_code_hash']);

    if (!$valid) {
        if ($invite && ($invite['status'] ?? '') === 'pending') {
            $attempts = (int)$invite['verification_attempts'] + 1;
            $statusSql = $attempts >= 5 ? ',status="revoked",consumed_at=NOW()' : '';
            $pdo->prepare('UPDATE client_onboarding_invitations SET verification_attempts=?' . $statusSql . ' WHERE id=?')
                ->execute([$attempts, (int)$invite['id']]);
        }
        $pdo->commit();
        header('Location: /?page=client-onboarding&token=' . rawurlencode($token) . '&code_sent=1&error=' . urlencode('Invalid or expired verification code.'));
        exit;
    }

    $pdo->prepare(
        'UPDATE client_onboarding_invitations
         SET status="verified",email_verified_at=NOW(),consumed_at=NOW(),verification_code_hash=NULL,code_expires_at=NULL
         WHERE id=?'
    )->execute([(int)$invite['id']]);
    $pdo->commit();

    session_regenerate_id(true);
    $_SESSION['client_onboarding_invitation_id'] = (int)$invite['id'];
    audit_log($pdo, 'client_onboarding.email_verified', 'client_onboarding_invitation', (int)$invite['id'], [
        'organization_id' => (int)$invite['organization_id'],
    ]);
    header('Location: /?page=client-onboarding&verified=1');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @error_log('[client_onboarding] Verification failed: ' . $e->getMessage());
    header('Location: /?page=client-onboarding&error=' . urlencode('The invitation could not be verified.'));
}
exit;
