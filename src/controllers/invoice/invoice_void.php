<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../../utils/audit.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=invoice/invoices-list&error=' . urlencode('Invalid invoice.'));
    exit;
}
require_record_ownership($pdo, 'invoices', $id);

$reason = trim((string)($_POST['reason'] ?? ''));
$userId = (int)($_SESSION['user']['id'] ?? 0) ?: null;
$redirectTo = trim((string)($_POST['redirect_to'] ?? ''));
$redirectBase = '/?page=invoice/invoice-details&id=' . $id;
if ($redirectTo !== ''
    && str_starts_with($redirectTo, '/')
    && !str_starts_with($redirectTo, '//')
    && !str_contains($redirectTo, "\r")
    && !str_contains($redirectTo, "\n")) {
    $redirectBase = $redirectTo;
}
$appendResult = static function (string $url, string $key, string $value): string {
    return $url . (str_contains($url, '?') ? '&' : '?') . rawurlencode($key) . '=' . rawurlencode($value);
};

try {
    $result = invoice_void($pdo, $id, $appConfig, $reason, $userId);
    audit_log($pdo, 'invoice.void', 'invoice', $id, [
        'reason' => $result['reason'],
        'previous_status' => $result['previous_status'],
        'user_id' => $userId,
    ]);
    header('Location: ' . $appendResult($redirectBase, 'voided', '1'));
} catch (Throwable $e) {
    header('Location: ' . $appendResult($redirectBase, 'error', $e->getMessage()));
}
exit;
