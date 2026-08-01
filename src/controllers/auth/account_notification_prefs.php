<?php
// src/controllers/auth/account_notification_prefs.php
// Saves the current user's notification preferences.
// Called via POST from the Notification Preferences panel on /?page=account.

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';

if (empty($_SESSION['user'])) {
    header('Location: /?page=login');
    exit;
}

// CSRF check
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    header('Location: /?page=account&notif_error=' . rawurlencode('Invalid request. Please try again.') . '#notifications');
    exit;
}

$uid = (int)($_SESSION['user']['id'] ?? 0);
if ($uid <= 0) {
    header('Location: /?page=login');
    exit;
}

// Checkbox: present = 1, absent = 0 (HTML checkbox omission behaviour).
$notifyProcessorInvoicePaid = !empty($_POST['notify_processor_invoice_paid']) ? 1 : 0;

try {
    $pdo->prepare(
        'INSERT INTO user_notification_preferences (user_id, notify_processor_invoice_paid)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE
             notify_processor_invoice_paid = VALUES(notify_processor_invoice_paid)'
    )->execute([$uid, $notifyProcessorInvoicePaid]);

    header('Location: /?page=account&notif_saved=1#notifications');
    exit;
} catch (Throwable $e) {
    @error_log('[account_notification_prefs] Error for user ' . $uid . ': ' . $e->getMessage());
    header('Location: /?page=account&notif_error=' . rawurlencode('Could not save preferences. Please try again.') . '#notifications');
    exit;
}
