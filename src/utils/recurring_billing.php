<?php
// src/utils/recurring_billing.php
// Idempotent helper for generating a single long-term recurring invoice.

function generate_recurring_invoice(PDO $pdo, array $contract, array $appConfig): ?int {
    $logPrefix = '[generate_recurring_invoice]';
    $today = date('Y-m-d');

    try {
        $pdo->beginTransaction();

        // Idempotency check: the contract must still be active and due today or earlier.
        $checkStmt = $pdo->prepare('
            SELECT * FROM contracts
            WHERE id = ?
            AND status = ?
            AND contract_type = "long_term"
            AND next_invoice_date IS NOT NULL
            AND next_invoice_date <= ?
            FOR UPDATE
        ');
        $checkStmt->execute([$contract['id'], 'active', $today]);
        $freshContract = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$freshContract) {
            $pdo->rollBack();
            return null;
        }

        $contract = $freshContract;
        $contractId = $contract['id'];
        $clientId = $contract['client_id'];
        $projectCode = $contract['project_code'];
        $projectId = !empty($contract['project_id']) ? (int)$contract['project_id'] : null;
        $organizationId = !empty($contract['organization_id']) ? (int)$contract['organization_id'] : null;
        $createdBy = !empty($contract['created_by']) ? (int)$contract['created_by'] : null;

        // Calculate invoice amount
        $subtotal = 0;
        $items = [];

        if ($contract['pricing_type'] === 'per_invoice') {
            // Simple per-invoice pricing - recurring amount
            $subtotal = (float)$contract['price_per_invoice'];
        } elseif ($contract['pricing_type'] === 'fixed_total') {
            // Fixed total - divide total by invoice_count
            $invoiceCount = (int)($contract['invoice_count'] ?? 1);
            $contractInvoicesGenerated = (int)($contract['invoices_generated'] ?? 0);
            $contractTotal = (float)$contract['total'];
            $depositPaid = (float)$contract['deposit_paid'];

            // Calculate amount to invoice: (total - deposit) / invoice_count
            $amountToInvoice = ($contractTotal - $depositPaid) / $invoiceCount;
            $subtotal = $amountToInvoice;

            // Load items for display (will be shown proportionally)
            $itemsQuery = $pdo->prepare('SELECT * FROM contract_items WHERE contract_id=?');
            $itemsQuery->execute([$contractId]);
            $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        }

        // Apply discount and tax (already factored into subtotal for fixed_total)
        $discountType = $contract['discount_type'] ?? 'none';
        $discountValue = (float)($contract['discount_value'] ?? 0);
        $taxPercent = (float)$contract['tax_percent'];

        // For fixed_total, discount and tax are already calculated in the contract total
        // For per_invoice, apply them per invoice
        if ($contract['pricing_type'] === 'per_invoice') {
            $discount = 0;
            if ($discountType === 'percent') {
                $discount = max(0, min(100, $discountValue)) * $subtotal / 100;
            } elseif ($discountType === 'fixed') {
                $discount = $discountValue;
            }
            $taxable = max(0, $subtotal - $discount);
            $tax = max(0, $taxPercent) * $taxable / 100;
            $total = max(0, $taxable + $tax);
        } else {
            // fixed_total: subtotal already has discount/tax baked in
            $total = $subtotal;
        }

        // Check if we've reached the invoice limit (for fixed_total pricing)
        if ($contract['pricing_type'] === 'fixed_total') {
            $invoiceCount = (int)($contract['invoice_count'] ?? 1);
            $contractInvoicesGenerated = (int)($contract['invoices_generated'] ?? 0);

            if ($contractInvoicesGenerated >= $invoiceCount) {
                // All invoices generated - mark as completed
                $pdo->prepare('UPDATE contracts SET status=?, next_invoice_date=NULL WHERE id=? AND contract_type="long_term"')
                    ->execute(['completed', $contractId]);
                @error_log("$logPrefix Contract LTC-{$contract['doc_number']} all {$invoiceCount} invoices generated, marked as completed");
                $pdo->commit();
                return null;
            }
        }

        // Create invoice
        $dueDate = date('Y-m-d', strtotime('+' . ($appConfig['net_terms_days'] ?? 30) . ' days'));

        $insertInvoice = $pdo->prepare('
            INSERT INTO invoices (
                contract_id, client_id, project_id, project_code, organization_id, created_by, invoice_type,
                discount_type, discount_value, tax_percent,
                subtotal, total, status, due_date, finalized_at, finalization_source, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), "recurring_schedule", NOW())
        ');

        $insertInvoice->execute([
            $contractId, // Link to long-term contract
            $clientId,
            $projectId,
            $projectCode,
            $organizationId,
            $createdBy,
            'long_term',
            $discountType,
            $discountValue,
            $contract['tax_percent'],
            $subtotal,
            $total,
            'unpaid',
            $dueDate
        ]);

        $invoiceId = (int)$pdo->lastInsertId();
        if ($projectId) {
            require_once __DIR__ . '/project_billing.php';
            if (project_uses_monthly_invoice_billing($pdo, $projectId)) {
                $pdo->prepare('UPDATE invoices SET collection_mode="project_aggregate" WHERE id=?')->execute([$invoiceId]);
            }
        }

        // Assign doc number
        $maxDoc = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices WHERE invoice_type = "long_term"')->fetchColumn();
        $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$maxDoc + 1, $invoiceId]);

        // Add invoice items
        if ($contract['pricing_type'] === 'per_invoice') {
            // Single line item for recurring service
            $billingInterval = $contract['billing_interval_count'] . ' ' . ucfirst($contract['billing_interval_unit']);
            if ($contract['billing_interval_count'] > 1) $billingInterval .= 's';

            $description = 'Recurring service fee (' . strtolower($billingInterval) . ')';
            if (!empty($contract['scope'])) {
                $description .= ' - ' . substr($contract['scope'], 0, 100);
            }

            $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)')
                ->execute([$invoiceId, $description, 1, $total, $total]);
        } elseif ($contract['pricing_type'] === 'fixed_total') {
            // For fixed_total, show items proportionally divided
            $invoiceCount = (int)($contract['invoice_count'] ?? 1);
            $contractInvoicesGenerated = (int)($contract['invoices_generated'] ?? 0);
            $invoiceNum = $contractInvoicesGenerated + 1;

            // Calculate proportion for this invoice
            foreach ($items as $item) {
                $proportionalQty = (float)$item['quantity'] / $invoiceCount;
                $proportionalTotal = (float)$item['line_total'] / $invoiceCount;

                $description = $item['description'] . ' (Payment ' . $invoiceNum . ' of ' . $invoiceCount . ')';

                $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)')
                    ->execute([
                        $invoiceId,
                        $description,
                        $proportionalQty,
                        $item['unit_price'],
                        $proportionalTotal
                    ]);
            }
        }

        // Calculate next invoice date
        $currentDate = $contract['next_invoice_date'];
        $intervalCount = (int)$contract['billing_interval_count'];
        $intervalUnit = $contract['billing_interval_unit'];

        $nextDate = date('Y-m-d', strtotime($currentDate . ' +' . $intervalCount . ' ' . $intervalUnit));

        // Check if we should continue invoicing
        $shouldContinue = true;
        if (!empty($contract['end_date'])) {
            if ($nextDate > $contract['end_date']) {
                $shouldContinue = false;
                $nextDate = null;
            }
        }

        // Update contract
        $newTotalInvoiced = (float)$contract['total_invoiced'] + $total;
        $newInvoicesGenerated = (int)($contract['invoices_generated'] ?? 0) + 1;

        if ($shouldContinue) {
            $pdo->prepare('UPDATE contracts SET next_invoice_date=?, last_invoice_date=?, total_invoiced=?, invoices_generated=? WHERE id=? AND contract_type="long_term"')
                ->execute([$nextDate, $today, $newTotalInvoiced, $newInvoicesGenerated, $contractId]);
        } else {
            $pdo->prepare('UPDATE contracts SET status=?, next_invoice_date=NULL, last_invoice_date=?, total_invoiced=?, invoices_generated=? WHERE id=? AND contract_type="long_term"')
                ->execute(['completed', $today, $newTotalInvoiced, $newInvoicesGenerated, $contractId]);
        }

        $pdo->commit();

        @error_log("$logPrefix Generated invoice INV-$maxDoc for contract LTC-{$contract['doc_number']} (\${$total})");

        return $invoiceId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @error_log("$logPrefix Error processing contract {$contract['id']}: " . $e->getMessage());
        return null;
    }
}
