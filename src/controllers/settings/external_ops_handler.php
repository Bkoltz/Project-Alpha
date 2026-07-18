<?php

declare(strict_types=1);

use App\Services\ExternalOpsIntegrationService;
use App\Services\ExternalOpsOutboxSender;
use App\Services\ExternalOpsConfigService;
use App\Services\OperationsPlanningService;

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
    } elseif (empty($config['enabled'])) {
        throw new DomainException('Enable and save the custom integration before managing synchronized records.');
    } elseif ($action === 'save-entitlement') {
        $managedUserId = (int)($_POST['user_id'] ?? 0);
        (new ExternalOpsIntegrationService())->saveAccountAccess(
            $pdo,
            $managedUserId,
            (string)$config['application_key'],
            !empty($_POST['enabled']),
            $actorUserId,
            !empty($_POST['business_unit_scope_present']) ? (array)($_POST['business_unit_ids'] ?? []) : null
        );
    } elseif ($action === 'send-now') {
        (new ExternalOpsOutboxSender())->deliverDue($pdo, $config, 50);
    } elseif ($action === 'save-operation') {
        (new OperationsPlanningService())->saveOperation(
            $pdo,
            $_POST,
            (array)($_POST['assigned_user_ids'] ?? []),
            $actorUserId
        );
    } elseif ($action === 'save-task') {
        (new OperationsPlanningService())->saveTask($pdo, $_POST, $actorUserId);
    } else {
        throw new DomainException('Unknown integration action.');
    }

    header('Location: /?page=settings&tab=external-ops&saved=1');
} catch (Throwable $error) {
    error_log('[external_ops_settings] ' . $error->getMessage());
    header('Location: /?page=settings&tab=external-ops&saved=0&error=' . rawurlencode($error->getMessage()));
}
exit;
