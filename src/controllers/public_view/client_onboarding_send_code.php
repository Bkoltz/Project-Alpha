<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
require_once __DIR__ . '/../../utils/client_onboarding.php';

$token = trim((string)($_POST['token'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));
if (!rate_limit_check($pdo, 'client_onboarding_send_code', 5, 900, false)) {
    http_response_code(429);
    echo 'Please wait before requesting another code.';
    exit;
}

$invite = client_onboarding_find_invitation($pdo, $token);
if ($invite && ($invite['status'] ?? '') === 'pending') {
    if (empty($invite['invited_email'])) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header('Location: /?page=client-onboarding&token=' . rawurlencode($token) . '&error=' . urlencode('Enter a valid email address.'));
            exit;
        }
        $pdo->prepare(
            'UPDATE client_onboarding_invitations
             SET invited_email=?
             WHERE id=? AND invited_email IS NULL AND status="pending"'
        )->execute([$email, (int)$invite['id']]);
        $invite['invited_email'] = $email;
    }

    $lastSent = !empty($invite['last_code_sent_at']) ? strtotime((string)$invite['last_code_sent_at']) : 0;
    if ($lastSent <= time() - 60) {
        if (!client_onboarding_send_code($pdo, $invite, $appConfig)) {
            header('Location: /?page=client-onboarding&token=' . rawurlencode($token) . '&error=' . urlencode('The verification email could not be sent.'));
            exit;
        }
        audit_log($pdo, 'client_onboarding.code_requested', 'client_onboarding_invitation', (int)$invite['id'], [
            'organization_id' => (int)$invite['organization_id'],
        ]);
    }
}

header('Location: /?page=client-onboarding&token=' . rawurlencode($token) . '&code_sent=1');
exit;
