<?php

declare(strict_types=1);

use App\Services\ExternalOpsIntegrationService;
use App\Services\ExternalOpsOutboxSender;
use App\Services\ExternalOpsConfigService;

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
            $result = $service->resyncAccountAccess($pdo, $managedUserId, (string)$config['application_key'], $actorUserId);
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
    error_log('[external_ops_settings] ' . $error->getMessage());
    $returnTo = trim((string)($_POST['return_to'] ?? ''));
    $location = $returnTo === 'account-edit' && !empty($_POST['user_id'])
        ? '/?page=account-edit&id=' . (int)$_POST['user_id'] . '&error=' . rawurlencode($error->getMessage())
        : '/?page=settings&tab=external-ops&saved=0&error=' . rawurlencode($error->getMessage());
    header('Location: ' . $location);
}
exit;
