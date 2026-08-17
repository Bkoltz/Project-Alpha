<?php

declare(strict_types=1);

use App\Services\ExternalOpsIntegrationService;
use App\Services\ExternalOpsOutboxSender;
use App\Services\ExternalOpsConfigService;
use App\Services\PortalAuthorityService;
use App\Services\PortalProjectionService;
use App\Services\PortalProjectionMutationService;
use App\Services\PortalProjectionDeliveryConfigService;
use App\Services\PortalProjectionOutboxSender;

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/external_ops.php';

$actorUserId = (int)($_SESSION['user']['id'] ?? 0);
if ($actorUserId < 1 || !user_can($pdo, $actorUserId, 'settings.manage', 0)) {
    http_response_code(403);
    exit('Permission denied');
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST' || !csrf_validate()) {
    header('Location: /?page=settings&tab=external-ops&saved=0&error=' . rawurlencode('Invalid request'));
    exit;
}

try {
    $action = (string)($_POST['action'] ?? '');
    $config = pa_external_ops_delivery_config($pdo);
    if ($action === 'save-config') {
        $config = (new ExternalOpsConfigService())->save($pdo, $_POST);
        audit_log($pdo, 'external_ops.configured', 'settings', null, [
            'enabled' => !empty($config['enabled']),
            'application_key' => (string)$config['application_key'],
            'webhook_host' => (string)(parse_url((string)$config['webhook_url'], PHP_URL_HOST) ?: ''),
        ]);
    } elseif ($action === 'save-portal-profile') {
        $profileId=(new PortalAuthorityService())->saveProfile($pdo,$_POST,$actorUserId);
        audit_log($pdo,'portal.integration_profile.saved','portal_integration_profile',$profileId,['application_key'=>(string)($_POST['application_key']??''),'enabled'=>!empty($_POST['enabled'])]);
        $message='Portal integration profile saved.';
    } elseif ($action === 'save-portal-runtime') {
        $runtime=(new PortalProjectionDeliveryConfigService())->saveRuntime($pdo,$_POST);audit_log($pdo,'portal.runtime.saved','settings',null,$runtime);$message='Portal projection runtime gates saved.';
    } elseif ($action === 'save-portal-delivery') {
        $profileId=(int)($_POST['profile_id']??0);(new PortalProjectionDeliveryConfigService())->saveProfile($pdo,$profileId,$_POST,$actorUserId);audit_log($pdo,'portal.delivery.saved','portal_integration_profile',$profileId,['enabled'=>!empty($_POST['delivery_enabled']),'key_id'=>(string)($_POST['delivery_key_id']??'')]);$message='Encrypted portal delivery settings saved.';
    } elseif ($action === 'send-portal-now') {
        $summary=(new PortalProjectionOutboxSender())->deliverDue($pdo,5,null,20);audit_log($pdo,'portal.delivery.run','settings',null,$summary);$message=sprintf('Portal delivery processed %d records; %d delivered, %d retrying, %d dead-lettered.',$summary['processed'],$summary['delivered'],$summary['failed'],$summary['dead_lettered']);
    } elseif ($action === 'save-portal-workspace') {
        $workspaceId=(new PortalAuthorityService())->saveWorkspace($pdo,(int)($_POST['profile_id']??0),(string)($_POST['root_type']??''),(string)($_POST['root_public_id']??''),(string)($_POST['display_name']??''),$actorUserId);
        audit_log($pdo,'portal.workspace.saved','portal_workspace',null,['workspace_public_id'=>$workspaceId]);$message='Portal workspace saved and explicitly linked to the selected profile.';
    } elseif ($action === 'set-portal-workspace-link') {
        $active=(string)($_POST['link_state']??'')==='link';
        (new PortalAuthorityService())->setWorkspaceLink($pdo,(int)($_POST['profile_id']??0),(string)($_POST['workspace_public_id']??''),$active,$actorUserId);
        audit_log($pdo,$active?'portal.workspace.linked':'portal.workspace.unlinked','portal_workspace',null,['profile_id'=>(int)($_POST['profile_id']??0),'workspace_public_id'=>(string)($_POST['workspace_public_id']??'')]);
        $message=$active?'Workspace linked to profile.':'Workspace unlinked; projection and command authorization now fail closed for this profile.';
    } elseif ($action === 'save-portal-principal') {
        if(!user_can($pdo,$actorUserId,'users.manage',0))throw new DomainException('User-management permission is required to manage portal authority.');
        $principalId=(new PortalAuthorityService())->createPrincipal($pdo,(string)($_POST['email']??''),(string)($_POST['display_name']??''),$actorUserId);
        audit_log($pdo,'portal.principal.saved','portal_principal',$principalId);$message='Portal principal saved. It remains authorization intent; identity verification occurs in the consuming portal.';
    } elseif ($action === 'appoint-portal-manager') {
        if(!user_can($pdo,$actorUserId,'users.manage',0))throw new DomainException('User-management permission is required to manage portal authority.');
        (new PortalAuthorityService())->appointManager($pdo,(int)($_POST['profile_id']??0),(string)($_POST['workspace_public_id']??''),(int)($_POST['principal_id']??0),(string)($_POST['scope_type']??''),(string)($_POST['scope_public_id']??''),!empty($_POST['replace_principal_id'])?(int)$_POST['replace_principal_id']:null,$actorUserId,!empty($_POST['viewer_share_create']));$message='Portal manager authority saved and projection changes were queued atomically.';
    } elseif ($action === 'offboard-portal-manager') {
        if(!user_can($pdo,$actorUserId,'users.manage',0))throw new DomainException('User-management permission is required to manage portal authority.');
        (new PortalAuthorityService())->offboardManager($pdo,(int)($_POST['profile_id']??0),(string)($_POST['workspace_public_id']??''),(int)($_POST['principal_id']??0),(string)($_POST['scope_type']??''),(string)($_POST['scope_public_id']??''),$actorUserId);$message='Portal manager was offboarded. If this was the final manager, the scope is now visibly locked until a replacement is appointed.';
    } elseif ($action === 'save-viewer-share-entitlement') {
        if(!user_can($pdo,$actorUserId,'users.manage',0))throw new DomainException('User-management permission is required to manage portal authority.');
        (new PortalAuthorityService())->saveViewerShareEntitlement($pdo,(int)($_POST['profile_id']??0),(string)($_POST['workspace_public_id']??''),(int)($_POST['principal_id']??0),(string)($_POST['scope_type']??''),(string)($_POST['scope_public_id']??''),(string)($_POST['effect']??''),(string)($_POST['entitlement_state']??'')==='active',$actorUserId);$message='Scoped model-viewer share authority saved and projection changes were queued atomically.';
    } elseif ($action === 'queue-portal-snapshot') {
        $profileId=(int)($_POST['profile_id']??0);$profileStmt=$pdo->prepare('SELECT * FROM portal_integration_profiles WHERE id=? AND enabled=1 AND portal_projection_enabled=1');$profileStmt->execute([$profileId]);$profile=$profileStmt->fetch(PDO::FETCH_ASSOC);if(!$profile)throw new DomainException('Enable portal projection before queuing a snapshot.');
        $pdo->beginTransaction();try{$summary=(new PortalProjectionService())->queueWorkspaceSnapshot($pdo,$profile,(string)($_POST['workspace_public_id']??''));audit_log($pdo,'portal.snapshot.queued','portal_workspace',null,$summary);$pdo->commit();}catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}$message='Complete portal snapshot queued.';
    } elseif ($action === 'queue-catalog-snapshot') {
        $profileId=(int)($_POST['profile_id']??0);$pdo->beginTransaction();try{$summaries=(new PortalProjectionMutationService())->queueCatalog($pdo,$profileId);if($summaries===[])throw new DomainException('Enable catalog projection and configure its receiver route before queuing a snapshot.');audit_log($pdo,'portal.catalog_snapshot.queued','portal_integration_profile',$profileId,$summaries[0]);$pdo->commit();}catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}$message='Complete Service Library snapshot queued.';
    } elseif (empty($config['configured_enabled'])) {
        throw new DomainException('Enable and save outbound delivery before managing synchronized records.');
    } elseif (in_array($action, ['grant-access','revoke-access','save-entitlement','resend-access'], true)) {
        if (!user_can($pdo, $actorUserId, 'users.manage', 0)) {
            throw new DomainException('User-management permission is required to change external operations access.');
        }
        $managedUserId = (int)($_POST['user_id'] ?? 0);
        $service = new ExternalOpsIntegrationService();
        $displayLabel = trim((string)($config['label'] ?? 'External operations')) ?: 'External operations';
        if ($action === 'resend-access') {
            $result = $service->resendAccountAccess($pdo, $managedUserId, (string)$config['application_key'], $actorUserId);
            if ($result === null || empty($result['event_id'])) {
                throw new DomainException('Only an active account with granted external operations access can be resent.');
            }
            audit_log($pdo, 'external_application.access_resent', 'user', $managedUserId, [
                'application_key' => (string)$config['application_key'],
                'event_id' => (string)$result['event_id'],
            ]);
            $message = $displayLabel . ' access was queued for resend.';
        } else {
            $grant = $action === 'grant-access' || ($action === 'save-entitlement' && !empty($_POST['enabled']));
            $result = $grant
                ? $service->grantAccountAccess($pdo, $managedUserId, (string)$config['application_key'], $actorUserId, (string)$config['label'])
                : $service->revokeAccountAccess($pdo, $managedUserId, (string)$config['application_key'], $actorUserId, (string)$config['label']);
            $message = $grant
                ? ($result['changed'] ? $displayLabel . ' access was granted.' : $displayLabel . ' access was already granted.')
                : ($result['changed'] ? $displayLabel . ' access was revoked.' : $displayLabel . ' access was already revoked.');
        }
    } elseif ($action === 'send-now') {
        if (empty($config['delivery_ready'])) {
            throw new DomainException('Outbound delivery is paused. Complete the required delivery settings or disable it until the receiver is ready.');
        }
        (new ExternalOpsOutboxSender())->deliverDue($pdo, $config, 50);
    } else {
        throw new DomainException('Unknown integration action.');
    }

    $returnTo = trim((string)($_POST['return_to'] ?? ''));
    $location = $returnTo === 'account-edit' && !empty($_POST['user_id'])
        ? '/?page=account-edit&id=' . (int)$_POST['user_id'] . '&success=' . rawurlencode($message ?? 'Saved')
        : '/?page=settings&tab=external-ops&saved=1' . (isset($message) ? '&message=' . rawurlencode($message) : '');
    header('Location: ' . $location);
} catch (Throwable $error) {
    $diagnostic=substr(hash('sha256',get_class($error).':'.$error->getMessage()),0,12);error_log('[external_ops_settings] failed code='.$diagnostic);
    $returnTo = trim((string)($_POST['return_to'] ?? ''));
    $location = $returnTo === 'account-edit' && !empty($_POST['user_id'])
        ? '/?page=account-edit&id=' . (int)$_POST['user_id'] . '&error=' . rawurlencode($error->getMessage())
        : '/?page=settings&tab=external-ops&saved=0&error=' . rawurlencode($error instanceof DomainException?$error->getMessage():'The integration action failed. Diagnostic code '.$diagnostic);
    header('Location: ' . $location);
}
exit;
