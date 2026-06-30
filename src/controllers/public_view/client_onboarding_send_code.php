<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/rate_limiter.php';
require_once __DIR__ . '/../../utils/client_onboarding.php';

$token = trim((string)($_POST['token'] ?? ''));
if (!rate_limit_check($pdo, 'client_onboarding_send_code', 5, 900, false)) {
    http_response_code(429);
    echo 'Please wait before requesting another code.';
    exit;
}

$invite = client_onboarding_find_invitation($pdo, $token);
if ($invite && ($invite['status'] ?? '') === 'pending') {
    $lastSent = !empty($invite['last_code_sent_at']) ? strtotime((string)$invite['last_code_sent_at']) : 0;
    if ($lastSent <= time() - 60) {
        client_onboarding_send_code($pdo, $invite, $appConfig);
        audit_log($pdo, 'client_onboarding.code_requested', 'client_onboarding_invitation', (int)$invite['id'], [
            'organization_id' => (int)$invite['organization_id'],
        ]);
    }
}

header('Location: /?page=client-onboarding&token=' . rawurlencode($token) . '&code_sent=1');
exit;
