<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/client_onboarding.php';

client_onboarding_revoke_stale($pdo);

$organizationId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId <= 0) {
    header('Location: /?page=client/onboarding&error=' . urlencode('Sign in before creating an invitation.'));
    exit;
}
$ownerOrganizationId = $organizationId > 0 ? $organizationId : null;

$action = (string)($_POST['action'] ?? 'create');
if ($action === 'revoke') {
    $id = (int)($_POST['id'] ?? 0);
    if ($ownerOrganizationId !== null) {
        $stmt = $pdo->prepare('
            UPDATE client_onboarding_invitations
            SET status="revoked", consumed_at=NOW()
            WHERE id=? AND (organization_id=? OR (organization_id IS NULL AND created_by=?)) AND status IN ("pending","verified")
        ');
        $stmt->execute([$id, $ownerOrganizationId, $userId]);
    } else {
        $stmt = $pdo->prepare('
            UPDATE client_onboarding_invitations
            SET status="revoked", consumed_at=NOW()
            WHERE id=? AND organization_id IS NULL AND created_by=? AND status IN ("pending","verified")
        ');
        $stmt->execute([$id, $userId]);
    }
    audit_log($pdo, 'client_onboarding.revoke', 'client_onboarding_invitation', $id, ['organization_id' => $ownerOrganizationId]);
    header('Location: /?page=client/onboarding&revoked=1');
    exit;
}

if ($action === 'regenerate_link') {
    $id = (int)($_POST['id'] ?? 0);
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+14 days'));

    if ($ownerOrganizationId !== null) {
        $stmt = $pdo->prepare('
            UPDATE client_onboarding_invitations
            SET token_hash=?, token_enc=?, status="pending", expires_at=?, consumed_at=NULL,
                verification_code_hash=NULL, code_expires_at=NULL, verification_attempts=0, email_verified_at=NULL
            WHERE id=? AND (organization_id=? OR (organization_id IS NULL AND created_by=?)) AND status IN ("pending","verified","expired")
        ');
        $stmt->execute([hash('sha256', $token), client_onboarding_store_token($token), $expiresAt, $id, $ownerOrganizationId, $userId]);
    } else {
        $stmt = $pdo->prepare('
            UPDATE client_onboarding_invitations
            SET token_hash=?, token_enc=?, status="pending", expires_at=?, consumed_at=NULL,
                verification_code_hash=NULL, code_expires_at=NULL, verification_attempts=0, email_verified_at=NULL
            WHERE id=? AND organization_id IS NULL AND created_by=? AND status IN ("pending","verified","expired")
        ');
        $stmt->execute([hash('sha256', $token), client_onboarding_store_token($token), $expiresAt, $id, $userId]);
    }

    if ($stmt->rowCount() <= 0) {
        header('Location: /?page=client/onboarding&error=' . urlencode('That onboarding invitation cannot be regenerated. Create a new link instead.'));
        exit;
    }

    $_SESSION['client_onboarding_link'] = client_onboarding_base_url($appConfig) . '/?page=client-onboarding&token=' . rawurlencode($token);
    audit_log($pdo, 'client_onboarding.regenerate_link', 'client_onboarding_invitation', $id, [
        'organization_id' => $ownerOrganizationId,
    ]);
    header('Location: /?page=client/onboarding&regenerated=1');
    exit;
}

$email = strtolower(trim((string)($_POST['email'] ?? '')));
$clientId = max(0, (int)($_POST['client_id'] ?? 0));
$targetOrganizationId = max(0, (int)($_POST['target_organization_id'] ?? 0));
$delivery = ($_POST['delivery'] ?? 'link') === 'email' ? 'email' : 'link';
$expiresHours = max(1, min(336, (int)($_POST['expires_hours'] ?? 336)));
$notifyOnSubmit = !empty($_POST['notify_on_submit']) ? 1 : 0;
try {
    $pdo->prepare(
        'INSERT INTO app_config (organization_id, config_key, config_value)
         VALUES (0, "notify_client_onboarding_submit", ?)
         ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
    )->execute([(string)$notifyOnSubmit]);
    $appConfig['notify_client_onboarding_submit'] = $notifyOnSubmit;
} catch (Throwable $e) {
    @error_log('[client_onboarding] Failed to sync notify_client_onboarding_submit setting: ' . $e->getMessage());
}

if ($delivery === 'email' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: /?page=client/onboarding&error=' . urlencode('Enter a valid email address.'));
    exit;
}
if ($delivery === 'link' && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: /?page=client/onboarding&error=' . urlencode('Enter a valid email address or leave email blank when generating a link only.'));
    exit;
}
if ($clientId > 0) {
    $client = $pdo->prepare('SELECT id FROM clients WHERE id=? AND archived=0');
    $client->execute([$clientId]);
    if (!$client->fetchColumn()) {
        header('Location: /?page=client/onboarding&error=' . urlencode('The selected client is unavailable.'));
        exit;
    }
}
if ($targetOrganizationId > 0) {
    $target = $pdo->prepare('SELECT id FROM organizations WHERE id=?');
    $target->execute([$targetOrganizationId]);
    if (!$target->fetchColumn()) {
        header('Location: /?page=client/onboarding&error=' . urlencode('The selected client organization is unavailable.'));
        exit;
    }
}

$token = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', strtotime('+' . $expiresHours . ' hours'));
if ($email !== '') {
    if ($ownerOrganizationId !== null) {
        $pdo->prepare(
            'UPDATE client_onboarding_invitations SET status="revoked",consumed_at=NOW()
             WHERE (organization_id=? OR (organization_id IS NULL AND created_by=?)) AND invited_email=? AND status IN ("pending","verified")'
        )->execute([$ownerOrganizationId, $userId, $email]);
    } else {
        $pdo->prepare(
            'UPDATE client_onboarding_invitations SET status="revoked",consumed_at=NOW()
             WHERE organization_id IS NULL AND created_by=? AND invited_email=? AND status IN ("pending","verified")'
        )->execute([$userId, $email]);
    }
}
$stmt = $pdo->prepare(
    'INSERT INTO client_onboarding_invitations
     (organization_id,target_organization_id,client_id,invited_email,token_hash,token_enc,expires_at,notify_on_submit,created_by)
     VALUES (?,?,?,?,?,?,?,?,?)'
);
$stmt->execute([
    $ownerOrganizationId,
    $targetOrganizationId ?: null,
    $clientId ?: null,
    $email !== '' ? $email : null,
    hash('sha256', $token),
    client_onboarding_store_token($token),
    $expiresAt,
    $notifyOnSubmit,
    $userId,
]);
$invitationId = (int)$pdo->lastInsertId();
$link = client_onboarding_base_url($appConfig) . '/?page=client-onboarding&token=' . rawurlencode($token);

$emailSent = false;
if ($delivery === 'email') {
    [$emailSent, $error] = EmailService::sendEmail(
        $email,
        'Complete your client information',
        '<p>You have been invited to provide your client information securely.</p><p><a href="' . htmlspecialchars($link) . '">Open the onboarding form</a></p><p>This invitation expires ' . htmlspecialchars($expiresAt) . '.</p>'
    );
    if ($emailSent) {
        $pdo->prepare('UPDATE client_onboarding_invitations SET sent_at=NOW() WHERE id=?')->execute([$invitationId]);
    } else {
        @error_log('[client_onboarding] Invitation email failed: ' . $error);
    }
}

$_SESSION['client_onboarding_link'] = $link;
audit_log($pdo, 'client_onboarding.create', 'client_onboarding_invitation', $invitationId, [
    'organization_id' => $ownerOrganizationId,
    'target_organization_id' => $targetOrganizationId ?: null,
    'client_id' => $clientId ?: null,
    'delivery' => $delivery,
    'email_provided' => $email !== '',
    'email_sent' => $emailSent,
    'notify_on_submit' => $notifyOnSubmit === 1,
]);
header('Location: /?page=client/onboarding&created=1' . ($delivery === 'email' && !$emailSent ? '&email_error=1' : ''));
exit;
