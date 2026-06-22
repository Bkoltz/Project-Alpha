<?php
// src/controllers/contract/on_demand_invoice_generate.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';

@error_log('[on_demand_invoice_generate] POST received', 0);

$contract_id = (int)($_POST['id'] ?? 0);

if ($contract_id <= 0) {
    @error_log('[on_demand_invoice_generate] invalid contract_id', 0);
    header('Location: /?page=contract/on-demand-contracts-list&error=Invalid%20contract%20ID');
    exit;
}

$pdo->beginTransaction();

try {
    // Fetch the on-demand contract
    $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id = ? AND contract_type = "on_demand"');
    $stmt->execute([$contract_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contract) {
        throw new Exception('Contract not found');
    }
    
    // Check if contract is active
    if ($contract['status'] !== 'active') {
        throw new Exception('Contract must be active to generate invoices');
    }
    
    // Check if contract has ended
    if (!empty($contract['end_date']) && $contract['end_date'] < date('Y-m-d')) {
        throw new Exception('Contract has ended');
    }
    
    $clientId = $contract['client_id'];
    $projectCode = $contract['project_code'];
    $projectId = !empty($contract['project_id']) ? (int)$contract['project_id'] : null;
    
    // Calculate invoice amount
    $subtotal = (float)$contract['price_per_invoice'];
    
    // Apply discount and tax
    $discountType = $contract['discount_type'] ?? 'none';
    $discountValue = (float)($contract['discount_value'] ?? 0);
    $discount = 0;
    
    if ($discountType === 'percent') {
        $discount = max(0, min(100, $discountValue)) * $subtotal / 100;
    } elseif ($discountType === 'fixed') {
        $discount = $discountValue;
    }
    
    $taxable = max(0, $subtotal - $discount);
    $tax = max(0, (float)$contract['tax_percent']) * $taxable / 100;
    $total = max(0, $taxable + $tax);
    
    // Create invoice
    $dueDate = date('Y-m-d', strtotime('+' . ($appConfig['net_terms_days'] ?? 30) . ' days'));
    
    $insertInvoice = $pdo->prepare('
        INSERT INTO invoices (
            contract_id, client_id, project_id, project_code, invoice_type,
            discount_type, discount_value, tax_percent, 
            subtotal, total, status, due_date, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ');
    
    $insertInvoice->execute([
        $contract_id,
        $clientId,
        $projectId,
        $projectCode,
        'on_demand',
        $discountType,
        $discountValue,
        $contract['tax_percent'],
        $subtotal,
        $total,
        'unpaid',
        $dueDate
    ]);
    
    $invoiceId = (int)$pdo->lastInsertId();
    
    // Assign doc number
    $maxDoc = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices WHERE invoice_type = "on_demand"')->fetchColumn();
    $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$maxDoc + 1, $invoiceId]);
    
    // Add invoice item
    $billingInterval = $contract['billing_interval_count'] . ' ' . ucfirst($contract['billing_interval_unit']);
    if ($contract['billing_interval_count'] > 1) $billingInterval .= 's';
    
    $description = 'On-demand service fee (' . strtolower($billingInterval) . ')';
    if (!empty($contract['scope'])) {
        $description .= ' - ' . substr($contract['scope'], 0, 100);
    }
    
    $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)')
        ->execute([$invoiceId, $description, 1, $total, $total]);
    
    // Update contract
    $newTotalInvoiced = (float)$contract['total_invoiced'] + $total;
    $newInvoiceCount = (int)$contract['invoice_count'] + 1;
    
    $pdo->prepare('UPDATE contracts SET total_invoiced=?, invoice_count=?, last_invoice_date=? WHERE id=? AND contract_type = "on_demand"')
        ->execute([$newTotalInvoiced, $newInvoiceCount, date('Y-m-d'), $contract_id]);
    
    $pdo->commit();
    
    @error_log("[on_demand_invoice_generate] Generated invoice I-$maxDoc for contract ODC-{$contract['doc_number']} (\${$total})");
    
    header('Location: /?page=contract/on-demand-invoices-list&contract_id=' . $contract_id . '&invoice_generated=1');
    exit;
    
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @error_log('[on_demand_invoice_generate] exception: ' . $e->getMessage(), 0);
    $msg = substr($e->getMessage(), 0, 200);
    header('Location: /?page=contract/on-demand-contracts-list&error=' . urlencode($msg));
    exit;
}
