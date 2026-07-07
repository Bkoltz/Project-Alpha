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
require_once __DIR__ . '/../../utils/stripe_reconciliation_import.php';

$redirectBase = '/?page=settings&tab=billing';

function stripe_import_redirect(string $key, string $message): void
{
    global $redirectBase;
    header('Location: ' . $redirectBase . '&' . $key . '=' . rawurlencode($message));
    exit;
}

function stripe_import_parse_date(string $value, DateTimeZone $timezone): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))) {
        return null;
    }
    return $date;
}

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId <= 0 || !user_can($pdo, $userId, 'settings.manage', 0)) {
    stripe_import_redirect('stripe_import_error', 'Permission denied');
}

if (!csrf_validate()) {
    stripe_import_redirect('stripe_import_error', 'Invalid request. Please refresh and try again.');
}

$stripe = StripeService::fromAppConfig($appConfig ?? []);
if (!$stripe) {
    stripe_import_redirect('stripe_import_error', 'Stripe is not configured.');
}

$timezoneName = trim((string)($appConfig['timezone'] ?? date_default_timezone_get() ?: 'UTC'));
try {
    $timezone = new DateTimeZone($timezoneName);
} catch (Throwable $e) {
    $timezone = new DateTimeZone('UTC');
}

$today = new DateTimeImmutable('today', $timezone);
$defaultStart = $today->modify('-30 days');
$startDateRaw = trim((string)($_POST['stripe_import_start_date'] ?? $defaultStart->format('Y-m-d')));
$endDateRaw = trim((string)($_POST['stripe_import_end_date'] ?? $today->format('Y-m-d')));

$startDate = stripe_import_parse_date($startDateRaw, $timezone);
$endDate = stripe_import_parse_date($endDateRaw, $timezone);
if (!$startDate || !$endDate) {
    stripe_import_redirect('stripe_import_error', 'Enter valid start and end dates.');
}

if ($endDate > $today) {
    $endDate = $today;
}
if ($startDate > $endDate) {
    stripe_import_redirect('stripe_import_error', 'Start date must be on or before the end date.');
}

$maxIntents = (int)($_POST['stripe_import_max_intents'] ?? 2000);
$maxIntents = max(100, min(10000, $maxIntents));

try {
    $result = stripe_reconcile_payment_intents(
        $pdo,
        $stripe,
        $appConfig ?? [],
        $startDate->setTime(0, 0, 0)->getTimestamp(),
        $endDate->setTime(23, 59, 59)->getTimestamp(),
        $maxIntents,
        true
    );

    $message = sprintf(
        'Stripe payment import complete for %s through %s: %s.',
        $startDate->format('Y-m-d'),
        $endDate->format('Y-m-d'),
        stripe_reconcile_summary($result)
    );
    stripe_import_redirect('stripe_import_result', $message);
} catch (Throwable $e) {
    @error_log('[stripe_import_payments] Error: ' . $e->getMessage());
    stripe_import_redirect('stripe_import_error', $e->getMessage());
}
