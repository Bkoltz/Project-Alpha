<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../services/EmailService.php';

$organizationId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);
$submissionId = (int)($_POST['submission_id'] ?? 0);
$decision = (string)($_POST['decision'] ?? '');
$notes = mb_substr(trim((string)($_POST['review_notes'] ?? '')), 0, 1000);
if ($userId <= 0 || $submissionId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    header('Location: /?page=client/onboarding&error=' . urlencode('Invalid review request.'));
    exit;
}

try {
    $pdo->beginTransaction();
    $ownerWhere = $organizationId > 0
        ? '(i.organization_id=? OR (i.organization_id IS NULL AND i.created_by=?))'
        : 'i.organization_id IS NULL AND i.created_by=?';
    $ownerParams = $organizationId > 0 ? [$organizationId, $userId] : [$userId];
    $stmt = $pdo->prepare(
        'SELECT s.*,i.organization_id,i.target_organization_id,i.client_id,i.invited_email,c.email AS current_client_email
         FROM client_onboarding_submissions s
         JOIN client_onboarding_invitations i ON i.id=s.invitation_id
         LEFT JOIN clients c ON c.id=i.client_id
         WHERE s.id=? AND ' . $ownerWhere . ' FOR UPDATE'
    );
    $stmt->execute(array_merge([$submissionId], $ownerParams));
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$submission || ($submission['status'] ?? '') !== 'pending') {
        throw new RuntimeException('This submission is no longer pending.');
    }

    $data = json_decode((string)$submission['proposed_data'], true);
    if (!is_array($data) || empty($data['name'])) {
        throw new RuntimeException('The submitted client data is invalid.');
    }

    $clientId = (int)($submission['client_id'] ?? 0);
    if ($decision === 'approve') {
        $emailValue = !empty($submission['invited_email'])
            ? (string)$submission['invited_email']
            : ($clientId > 0 ? ((string)($submission['current_client_email'] ?? '') ?: null) : null);
        $values = [
            $data['name'],
            $emailValue,
            $data['phone'] ?: null,
            $data['address_line1'] ?: null,
            $data['address_line2'] ?: null,
            $data['city'] ?: null,
            $data['state'] ?: null,
            $data['postal_code'] ?: null,
            $data['country'] ?: 'US',
            $data['client_type'] ?? 'unknown',
        ];
        if ($clientId > 0) {
            $update = $pdo->prepare(
                'UPDATE clients SET name=?,email=?,phone=?,address_line1=?,address_line2=?,city=?,state=?,postal_code=?,country=?,client_type=? WHERE id=?'
            );
            $update->execute(array_merge($values, [$clientId]));
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO clients
                 (name,email,phone,address_line1,address_line2,city,state,postal_code,country,client_type,organization_id,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $insert->execute(array_merge($values, [
                !empty($submission['target_organization_id']) ? (int)$submission['target_organization_id'] : null,
                $userId,
            ]));
            $clientId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE client_onboarding_invitations SET client_id=? WHERE id=?')
                ->execute([$clientId, (int)$submission['invitation_id']]);
        }
    }

    $status = $decision === 'approve' ? 'approved' : 'rejected';
    $pdo->prepare('UPDATE client_onboarding_submissions SET status=?,reviewed_by=?,reviewed_at=NOW(),review_notes=? WHERE id=?')
        ->execute([$status, $userId, $notes ?: null, $submissionId]);
    $pdo->prepare('UPDATE client_onboarding_invitations SET status=? WHERE id=?')
        ->execute([$status, (int)$submission['invitation_id']]);
    $pdo->commit();

    audit_log($pdo, 'client_onboarding.' . $status, 'client_onboarding_submission', $submissionId, [
        'organization_id' => !empty($submission['organization_id']) ? (int)$submission['organization_id'] : null,
        'client_id' => $clientId ?: null,
    ]);
    if (!empty($submission['invited_email'])) {
        EmailService::sendEmail(
            (string)$submission['invited_email'],
            'Client information ' . ($decision === 'approve' ? 'approved' : 'reviewed'),
            $decision === 'approve'
                ? '<p>Your client information has been approved. Thank you.</p>'
                : '<p>Your client information was reviewed but not applied. Please contact the business if you need assistance.</p>'
        );
    }
    header('Location: /?page=client/onboarding&reviewed=1');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @error_log('[client_onboarding] Review failed: ' . $e->getMessage());
    header('Location: /?page=client/onboarding&error=' . urlencode($e->getMessage()));
}
exit;
