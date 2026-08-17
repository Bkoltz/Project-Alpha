<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/client_onboarding.php';
require_once __DIR__ . '/../../utils/portal_projection_hooks.php';

$organizationId = request_client_org_id();
$userId = (int)($_SESSION['user']['id'] ?? 0);
$submissionId = (int)($_POST['submission_id'] ?? 0);
$decision = (string)($_POST['decision'] ?? '');
$resolution = (string)($_POST['resolution'] ?? '');
$matchClientId = max(0, (int)($_POST['match_client_id'] ?? 0));
$matchOrganizationId = max(0, (int)($_POST['match_organization_id'] ?? 0));
$organizationResolution = (string)($_POST['organization_resolution'] ?? '');
$mergeFields = array_values(array_filter(array_map('strval', (array)($_POST['merge_fields'] ?? []))));
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
    $projection = new \App\Services\PortalProjectionMutationService();
    $portalBeforeScopes = [];
    $portalClientIds = array_values(array_unique(array_filter([$clientId, $matchClientId])));
    sort($portalClientIds, SORT_NUMERIC);
    foreach ($portalClientIds as $portalClientId) {
        $portalBeforeScopes = array_merge($portalBeforeScopes, $projection->lockedClientScopes($pdo, (int)$portalClientId));
    }
    if ($decision === 'approve') {
        $emailValue = client_onboarding_submitted_email($data, $submission);
        if ($emailValue !== '' && !filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
            $emailValue = '';
        }
        $targetOrganizationId = !empty($submission['target_organization_id']) ? (int)$submission['target_organization_id'] : null;
        $submittedOrganizationName = client_onboarding_clean_text($data['organization_name'] ?? '', 150);
        if ($organizationResolution === 'match' && $matchOrganizationId > 0) {
            $orgStmt = $pdo->prepare('SELECT id FROM organizations WHERE id=?');
            $orgStmt->execute([$matchOrganizationId]);
            if (!$orgStmt->fetchColumn()) {
                throw new RuntimeException('The selected organization match is unavailable.');
            }
            $targetOrganizationId = $matchOrganizationId;
        } elseif ($organizationResolution === 'create' && $submittedOrganizationName !== '') {
            $orgInsert = $pdo->prepare('INSERT INTO organizations (name,source_version) VALUES (?,?)');
            try {
                $orgInsert->execute([$submittedOrganizationName,portal_projection_source_version()]);
                $targetOrganizationId = (int)$pdo->lastInsertId();
            } catch (Throwable $orgError) {
                $orgLookup = $pdo->prepare('SELECT id FROM organizations WHERE name=?');
                $orgLookup->execute([$submittedOrganizationName]);
                $targetOrganizationId = (int)($orgLookup->fetchColumn() ?: 0) ?: $targetOrganizationId;
            }
        }

        $values = [
            $data['name'],
            $emailValue !== '' ? $emailValue : null,
            $data['phone'] ?: null,
            $data['address_line1'] ?: null,
            $data['address_line2'] ?: null,
            $data['city'] ?: null,
            $data['state'] ?: null,
            $data['postal_code'] ?: null,
            $data['country'] ?: 'US',
            $data['client_type'] ?? 'unknown',
        ];
        if ($resolution === '') {
            $resolution = $clientId > 0 ? 'update_invited' : 'create';
        }
        if (in_array($resolution, ['keep_existing', 'merge_existing'], true)) {
            if ($matchClientId <= 0) {
                throw new RuntimeException('Choose an existing client match before approving this way.');
            }
            $existingStmt = $pdo->prepare('SELECT * FROM clients WHERE id=? AND archived=0 FOR UPDATE');
            $existingStmt->execute([$matchClientId]);
            $existingClient = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existingClient) {
                throw new RuntimeException('The selected client match is unavailable.');
            }
            $clientId = $matchClientId;
            if ($resolution === 'merge_existing') {
                $fields = ['name','email','phone','address_line1','address_line2','city','state','postal_code','country','client_type'];
                $merged = [];
                foreach ($fields as $field) {
                    $sourceData = $data;
                    if ($field === 'email') {
                        $sourceData['email'] = $emailValue;
                    }
                    $merged[$field] = client_onboarding_merge_value($sourceData, $existingClient, $field, $mergeFields);
                }
                $update = $pdo->prepare(
                    'UPDATE clients SET name=?,email=?,phone=?,address_line1=?,address_line2=?,city=?,state=?,postal_code=?,country=?,client_type=?,organization_id=COALESCE(?, organization_id),source_version=? WHERE id=?'
                );
                $update->execute([
                    $merged['name'] ?: $existingClient['name'],
                    $merged['email'],
                    $merged['phone'],
                    $merged['address_line1'],
                    $merged['address_line2'],
                    $merged['city'],
                    $merged['state'],
                    $merged['postal_code'],
                    $merged['country'] ?: 'US',
                    $merged['client_type'] ?: 'unknown',
                    $targetOrganizationId,
                    portal_projection_source_version(),
                    $clientId,
                ]);
            }
            $pdo->prepare('UPDATE client_onboarding_invitations SET client_id=?,target_organization_id=COALESCE(?, target_organization_id) WHERE id=?')
                ->execute([$clientId, $targetOrganizationId, (int)$submission['invitation_id']]);
        } elseif ($clientId > 0) {
            $update = $pdo->prepare(
                'UPDATE clients SET name=?,email=?,phone=?,address_line1=?,address_line2=?,city=?,state=?,postal_code=?,country=?,client_type=?,organization_id=COALESCE(?, organization_id),source_version=? WHERE id=?'
            );
            $update->execute(array_merge($values, [$targetOrganizationId, portal_projection_source_version(), $clientId]));
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO clients
                 (name,email,phone,address_line1,address_line2,city,state,postal_code,country,client_type,organization_id,source_version,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $insert->execute(array_merge($values, [
                $targetOrganizationId,
                portal_projection_source_version(),
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
    if ($decision === 'approve' && $clientId > 0) {
        $projection->afterMutation($pdo, array_merge($portalBeforeScopes, $projection->clientScopes($pdo, $clientId)));
    }
    $pdo->commit();

    audit_log($pdo, 'client_onboarding.' . $status, 'client_onboarding_submission', $submissionId, [
        'organization_id' => !empty($submission['organization_id']) ? (int)$submission['organization_id'] : null,
        'client_id' => $clientId ?: null,
        'resolution' => $resolution ?: null,
    ]);
    header('Location: /?page=client/onboarding&reviewed=1');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @error_log('[client_onboarding] Review failed: ' . $e->getMessage());
    header('Location: /?page=client/onboarding&error=' . urlencode($e->getMessage()));
}
exit;
