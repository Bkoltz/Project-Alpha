<?php
// src/controllers/document_reenable_handler.php
// Re-enable (un-void) voided/cancelled documents and restore related documents

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../services/ProjectContractEligibilityGuardService.php';

// CSRF is already verified by the router (index.php)
// No need to verify again here

$type = $_POST['type'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if ($id <= 0 || !in_array($type, ['quote', 'contract', 'invoice', 'long_term_contract', 'on_demand_contract'])) {
    header('Location: /?page=dashboard&error=Invalid%20document%20type%20or%20ID');
    exit;
}

$contractTypes = ['contract', 'long_term_contract', 'on_demand_contract'];
$preReadProjectId = null;
if (in_array($type, $contractTypes, true)) {
    $preRead = $pdo->prepare('SELECT project_id FROM contracts WHERE id=?');
    $preRead->execute([$id]);
    $projectValue = $preRead->fetchColumn();
    if ($projectValue === false) {
        header('Location: /?page=dashboard&error=Contract%20not%20found');
        exit;
    }
    $preReadProjectId = $projectValue !== null ? (int)$projectValue : null;
}

$pdo->beginTransaction();
try {
    $guardedContract = null;
    if (in_array($type, $contractTypes, true)) {
        (new App\Services\ProjectContractEligibilityGuardService($pdo))
            ->assertCanCreateOrAttach($preReadProjectId, [$id]);
        $locked = $pdo->prepare('SELECT * FROM contracts WHERE id=? FOR UPDATE');
        $locked->execute([$id]);
        $guardedContract = $locked->fetch(PDO::FETCH_ASSOC);
        if (!$guardedContract) {
            throw new Exception('Contract not found');
        }
        $lockedProjectId = $guardedContract['project_id'] !== null ? (int)$guardedContract['project_id'] : null;
        if ($lockedProjectId !== $preReadProjectId) {
            throw new DomainException('Contract Project assignment changed. Retry the request.');
        }
    }
    switch ($type) {
        case 'quote':
            throw new Exception('Terminal quotes cannot be reopened. Clone the quote into a new draft.');

        case 'contract':
            // Re-enable contract (change from cancelled/denied/void back to pending)
            $doc = $guardedContract;
            
            // Only allow re-enabling cancelled/denied contracts
            if (!in_array($doc['status'], ['cancelled', 'denied', 'void'])) {
                throw new Exception('Only cancelled/denied/void contracts can be re-enabled');
            }
            
            // Store previous status to potentially restore related docs
            $previousStatus = $doc['status'];
            
            // Update status to pending, clear voided_at, and refresh document_date
            $pdo->prepare("UPDATE contracts SET status='pending', voided_at=NULL, document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$id]);
            
            // Re-enable related invoices that were voided when contract was voided
            $pdo->prepare("UPDATE invoices SET status='unpaid', document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE contract_id=? AND status='void'")
                ->execute([$id]);
            
            // Un-revoke public links for those invoices
            try {
                $pdo->prepare('UPDATE public_links SET revoked=0, redirect=NULL WHERE document_type="invoice" AND document_id IN (SELECT id FROM invoices WHERE contract_id=?) AND revoked=1')
                    ->execute([$id]);
            } catch (Throwable $_e) { /* ignore */ }
            
            $redirectPage = ($doc['contract_type'] ?? '') === 'long_term' ? 'contract/long-term-contract-details' : 'contract/contract-details';
            break;

        case 'invoice':
            // Backward-compatible route; invoice pages use invoice/invoice-reenable.
            invoice_reenable_void($pdo, $id);
            $redirectPage = 'invoice/invoice-details';
            break;

        case 'long_term_contract':
            // Re-enable long-term contract
            $doc = $guardedContract;
            if (($doc['contract_type'] ?? '') !== 'long_term') throw new Exception('Long-term contract not found');
            
            // Only allow re-enabling cancelled/denied/void contracts
            if (!in_array($doc['status'], ['cancelled', 'denied', 'void'], true)) {
                throw new Exception('Only cancelled/denied/void long-term contracts can be re-enabled');
            }
            
            // Update status to pending and refresh document date
            $pdo->prepare("UPDATE contracts SET status='pending', voided_at=NULL, document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE id=? AND contract_type='long_term'")
                ->execute([$id]);
            
            $redirectPage = 'contract/long-term-contract-details';
            break;

        case 'on_demand_contract':
            // Re-enable on-demand contract
            $doc = $guardedContract;
            if (($doc['contract_type'] ?? '') !== 'on_demand') throw new Exception('On-demand contract not found');
            
            // Only allow re-enabling cancelled/denied/void contracts
            if (!in_array($doc['status'], ['cancelled', 'denied', 'void'], true)) {
                throw new Exception('Only cancelled/denied/void on-demand contracts can be re-enabled');
            }
            
            // Update status to pending and refresh document date
            $pdo->prepare("UPDATE contracts SET status='pending', voided_at=NULL, document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE id=? AND contract_type='on_demand'")
                ->execute([$id]);
            
            $redirectPage = 'contract/contract-details';
            break;

        default:
            throw new Exception('Invalid document type');
    }

    $pdo->commit();
    header("Location: /?page={$redirectPage}&id={$id}&reenabled=1");
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errorMsg = urlencode($e->getMessage());
    $redirectPage = $_POST['redirect'] ?? 'dashboard';
    header("Location: /?page={$redirectPage}&error={$errorMsg}");
    exit;
}
