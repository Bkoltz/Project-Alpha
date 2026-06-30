<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/client_onboarding.php';

$organizationId = get_active_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($organizationId <= 0 || $userId <= 0) {
    header('Location: /?page=client/onboarding&error=' . urlencode('Select an organization before creating an invitation.'));
    exit;
}

$action = (string)($_POST['action'] ?? 'create');
if ($action === 'revoke') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('UPDATE client_onboarding_invitations SET status="revoked",consumed_at=NOW() WHERE id=? AND organization_id=? AND status IN ("pending","verified")');
    $stmt->execute([$id, $organizationId]);
    audit_log($pdo, 'client_onboarding.revoke', 'client_onboarding_invitation', $id, ['organization_id' => $organizationId]);
    header('Location: /?page=client/onboarding&revoked=1');
    exit;
}

$email = strtolower(trim((string)($_POST['email'] ?? '')));
$clientId = max(0, (int)($_POST['client_id'] ?? 0));
$targetOrganizationId = max(0, (int)($_POST['target_organization_id'] ?? 0));
$delivery = ($_POST['delivery'] ?? 'link') === 'email' ? 'email' : 'link';
$expiresHours = max(1, min(168, (int)($_POST['expires_hours'] ?? 48)));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: /?page=client/onboarding&error=' . urlencode('Enter a valid email address.'));
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
$pdo->prepare(
    'UPDATE client_onboarding_invitations SET status="revoked",consumed_at=NOW()
     WHERE organization_id=? AND invited_email=? AND status IN ("pending","verified")'
)->execute([$organizationId, $email]);
$stmt = $pdo->prepare(
    'INSERT INTO client_onboarding_invitations
     (organization_id,target_organization_id,client_id,invited_email,token_hash,expires_at,created_by)
     VALUES (?,?,?,?,?,?,?)'
);
$stmt->execute([
    $organizationId,
    $targetOrganizationId ?: null,
    $clientId ?: null,
    $email,
    hash('sha256', $token),
    $expiresAt,
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
    'organization_id' => $organizationId,
    'target_organization_id' => $targetOrganizationId ?: null,
    'client_id' => $clientId ?: null,
    'delivery' => $delivery,
    'email_sent' => $emailSent,
]);
header('Location: /?page=client/onboarding&created=1' . ($delivery === 'email' && !$emailSent ? '&email_error=1' : ''));
exit;
