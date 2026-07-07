<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../services/StripeService.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/stripe_payment_accounting.php';

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId <= 0 || !user_can($pdo, $userId, 'settings.manage', 0)) {
    header('Location: /?page=settings&tab=billing&stripe_net_error=' . rawurlencode('Permission denied'));
    exit;
}

if (!csrf_validate()) {
    header('Location: /?page=settings&tab=billing&stripe_net_error=' . rawurlencode('Invalid request. Please refresh and try again.'));
    exit;
}

$stripe = StripeService::fromAppConfig($appConfig ?? []);
if (!$stripe) {
    header('Location: /?page=settings&tab=billing&stripe_net_error=' . rawurlencode('Stripe is not configured.'));
    exit;
}

$limit = (int)($_POST['limit'] ?? 100);
$limit = max(1, min(500, $limit));

try {
    $result = stripe_backfill_net_income($pdo, $stripe, $appConfig ?? [], $limit);
    $message = sprintf(
        'Stripe net income backfill complete: %d actual, %d estimated, %d unknown, %d failed.',
        (int)$result['updated'],
        (int)$result['estimated'],
        (int)$result['unknown'],
        (int)$result['failed']
    );
    header('Location: /?page=settings&tab=billing&stripe_net_backfill=' . rawurlencode($message));
    exit;
} catch (Throwable $e) {
    @error_log('[stripe_net_backfill] Error: ' . $e->getMessage());
    header('Location: /?page=settings&tab=billing&stripe_net_error=' . rawurlencode($e->getMessage()));
    exit;
}
