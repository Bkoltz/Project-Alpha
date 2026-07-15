<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/EmailService.php';
require_once __DIR__ . '/../../services/EmailProviderManager.php';
require_once __DIR__ . '/../../utils/api_response.php';

$actorId = (int)($_SESSION['user']['id'] ?? 0);
if ($actorId <= 0 || !in_array((string)($_SESSION['user']['role'] ?? ''), ['admin','owner'], true)) {
    api_json_failure(403, 'permission_denied', 'Only an installation administrator can manage outgoing email providers.');
}
$action = trim((string)($_POST['action'] ?? ''));
$manager = new EmailProviderManager($pdo, $appConfig);

try {
    if ($action === 'activate') {
        $manager->activate((int)($_POST['connection_id'] ?? 0), $actorId ?: null);
        api_json_success(['active' => true]);
    } elseif ($action === 'disconnect') {
        $manager->disconnect((int)($_POST['connection_id'] ?? 0), $actorId ?: null);
        api_json_success(['disconnected' => true]);
    } elseif ($action === 'test') {
        $email = trim((string)($_SESSION['user']['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_json_failure(422, 'invalid_recipient', 'Your account needs a valid email address before sending a test.');
        }
        [$sent, $error] = EmailService::sendEmail(
            $email,
            'Project Alpha outgoing email test',
            '<p>Your active outgoing email provider is working.</p>',
            ['document_type' => 'notification']
        );
        if (!$sent) {
            api_json_failure(503, 'email_provider_error', $error);
        }
        api_json_success(['sent_to' => $email]);
    } else {
        api_json_failure(422, 'invalid_action', 'Choose a valid email provider action.');
    }
} catch (RuntimeException $error) {
    api_json_failure(409, 'email_provider_conflict', $error->getMessage());
} catch (Throwable $error) {
    @error_log('[email-provider-handler] ' . $error->getMessage());
    api_json_failure(500, 'email_provider_error', 'The email provider action could not be completed.');
}
