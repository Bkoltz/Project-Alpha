<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../../utils/invoice_content_links.php';
require_once __DIR__ . '/../../utils/audit.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=invoice/invoices-list&error=' . urlencode('Invalid invoice.'));
    exit;
}
require_record_ownership($pdo, 'invoices', $id);

try {
    if (invoice_should_prompt_for_missing_content_links($pdo, 'invoice', $id, $appConfig)) {
        $missingLinkBehavior = invoice_missing_content_links_behavior($appConfig);
        if ($missingLinkBehavior === 'block') {
            header('Location: /?page=invoice/invoice-details&id=' . $id . '&email_err=' . urlencode(invoice_missing_content_links_message()));
            exit;
        }
        if (empty($_POST['confirm_missing_content_links'])) {
            header('Location: /?page=invoice/invoice-details&id=' . $id . '&content_link_warning=1');
            exit;
        }
    }
    $userId = (int)($_SESSION['user']['id'] ?? 0) ?: null;
    invoice_finalize($pdo, $id, $appConfig, 'manual_finalize', $userId);
    $sent = invoice_send_finalized($pdo, $id, $appConfig, 'manual_finalize_' . date('YmdHis'));
    audit_log($pdo, 'invoice.finalize', 'invoice', $id, ['sent' => $sent, 'user_id' => $userId]);
    $message = $sent ? '&finalized=1&emailed=1' : '&finalized=1&email_err=' . urlencode('Invoice finalized, but email was not sent.');
    header('Location: /?page=invoice/invoice-details&id=' . $id . $message);
} catch (Throwable $e) {
    header('Location: /?page=invoice/invoice-details&id=' . $id . '&error=' . urlencode($e->getMessage()));
}
exit;
