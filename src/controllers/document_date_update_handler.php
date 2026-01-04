<?php
// src/controllers/document_date_update_handler.php
// Update document_date to current timestamp

require_once __DIR__ . '/../config/db.php';

// CSRF is already verified by the router (index.php)
// No need to verify again here

$type = $_POST['type'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if ($id <= 0 || !in_array($type, ['quote', 'contract', 'invoice', 'long_term_contract', 'on_demand_contract'])) {
    header('Location: /?page=dashboard&error=Invalid%20document%20type%20or%20ID');
    exit;
}

try {
    switch ($type) {
        case 'quote':
            $pdo->prepare("UPDATE quotes SET document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$id]);
            $redirectPage = 'quote/quote-details';
            break;

        case 'contract':
            $pdo->prepare("UPDATE contracts SET document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$id]);
            $redirectPage = 'contract/contract-details';
            break;

        case 'invoice':
            $pdo->prepare("UPDATE invoices SET document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$id]);
            $redirectPage = 'invoice/invoice-details';
            break;

        case 'long_term_contract':
            // Long-term contracts don't have document_date columns yet, skip for now
            $redirectPage = 'contract/long-term-contract-details';
            break;

        case 'on_demand_contract':
            // On-demand contracts don't have document_date columns yet, skip for now
            $redirectPage = 'contract/on-demand-contract-details';
            break;

        default:
            throw new Exception('Invalid document type');
    }

    header("Location: /?page={$redirectPage}&id={$id}&date_updated=1");
    exit;

} catch (Throwable $e) {
    $errorMsg = urlencode($e->getMessage());
    $redirectPage = $_POST['redirect'] ?? 'dashboard';
    header("Location: /?page={$redirectPage}&error={$errorMsg}");
    exit;
}
