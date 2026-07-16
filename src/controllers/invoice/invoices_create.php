<?php
// src/controllers/invoices_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/project_id.php';
require_once __DIR__ . '/../../utils/project_billing.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/document_fields.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/invoice_lifecycle.php';
require_once __DIR__ . '/../../utils/project_selection.php';
require_once __DIR__ . '/../../utils/invoice_numbers.php';
require_once __DIR__ . '/../../utils/document_locations.php';
require_once __DIR__ . '/../../utils/catalog_documents.php';
require_once __DIR__ . '/../../services/JobAssignmentService.php';
require_once __DIR__ . '/../../services/DocumentRevisionService.php';

$__orgId = request_client_org_id() ?: null;
$__creator = (int)($_SESSION['user']['id'] ?? 0) ?: null;

$client_id = (int)($_POST['client_id'] ?? 0);
$project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
$requestedServiceLocationId = !empty($_POST['service_location_id']) ? (int)$_POST['service_location_id'] : null;
$return_to_project = (int)($_POST['return_to_project'] ?? 0);
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$billing_mode = ($_POST['billing_mode'] ?? 'fixed') === 'hourly' ? 'hourly' : 'fixed';
$invoiceAction = (string)($_POST['invoice_action'] ?? 'save');
if (!in_array($invoiceAction, ['save', 'draft', 'finalize_send'], true)) {
    $invoiceAction = 'save';
}
$finalizeAndSend = $invoiceAction === 'finalize_send';
$due_date = $_POST['due_date'] ?? null;
if (!$due_date || trim($due_date) === '') {
    $due_date = project_invoice_due_date($pdo, $project_id, $appConfig);
}

$item = $_POST['item'] ?? [];
$desc = $_POST['item_desc'] ?? [];
$qty = $_POST['item_qty'] ?? [];
$price = $_POST['item_price'] ?? [];
$billingUnits = $_POST['item_billing_unit'] ?? [];
$catalogIds = $_POST['item_library_id'] ?? [];
$timeEntryIdsByRow = $_POST['time_entry_ids'] ?? [];
$legacyTimeEntryIds = $_POST['time_entry_id'] ?? [];
$mileageAllocationIdsByRow = $_POST['mileage_allocation_ids'] ?? [];

if ($client_id <= 0 || empty($item)) {
    header('Location: /?page=invoice/invoices-create&error=Invalid%20input');
    exit;
}
if ($project_id && !pa_project_is_active_for_client($pdo, $project_id, $client_id, (int)($_SESSION['user']['id'] ?? 0))) {
    header('Location: /?page=invoice/invoices-create&error=' . urlencode('Select an active or not-started project for this client or organization.'));
    exit;
}
$__orgId = resolve_client_context_org_id($pdo, $client_id, $project_id, $__orgId);

$items = [];
$subtotal = 0.0;
for ($i=0; $i<count($item); $i++) {
    $itm = trim((string)($item[$i] ?? ''));
    $d = trim((string)($desc[$i] ?? ''));
    $q = (float)($qty[$i] ?? 0);
    $p = (float)($price[$i] ?? 0);
    $rowTimeEntryIds = array_values(array_unique(array_filter(array_map('intval', $timeEntryIdsByRow[$i] ?? [($legacyTimeEntryIds[$i] ?? 0)]))));
    $rowMileageAllocationIds = array_values(array_unique(array_filter(array_map('intval', $mileageAllocationIdsByRow[$i] ?? []))));
    // Linked adjustment groups may legitimately net to zero or negative.
    if ($itm === '' || ($q == 0.0 && !$rowTimeEntryIds && !$rowMileageAllocationIds) || $p < 0) continue;
    $line = $q * $p;
    $subtotal += $line;
    $items[] = [
        'item' => $itm,
        'description' => $d,
        'quantity' => $q,
        'unit_price' => $p,
        'line_total' => $line,
        'billing_unit' => $billing_mode === 'hourly'
            ? 'hour'
            : catalog_document_unit((string)($billingUnits[$i] ?? 'each')),
        'catalog_id' => max(0,(int)($catalogIds[$i]??0)),
        'time_entry_ids' => $rowTimeEntryIds,
        'mileage_allocation_ids' => $rowMileageAllocationIds,
        'is_travel' => !empty($rowMileageAllocationIds) ? 1 : 0,
    ];
}
if (!$items) {
    header('Location: /?page=invoice/invoices-create&error=Add%20at%20least%20one%20item');
    exit;
}

$discount_amount = 0.0;
if ($discount_type === 'percent') {
    $discount_amount = max(0.0, min(100.0, $discount_value)) * $subtotal / 100.0;
} elseif ($discount_type === 'fixed') {
    $discount_amount = max(0.0, $discount_value);
}
$tax_amount = max(0.0, $tax_percent) * max(0.0, $subtotal - $discount_amount) / 100.0;
$total = max(0.0, $subtotal - $discount_amount + $tax_amount);

// Extract custom field values from POST data (only non-empty values)
$customFields = extractCustomFieldValues($_POST);
$customFieldsJson = !empty($customFields) ? json_encode($customFields) : null;

$pdo->beginTransaction();
try {
    $selectedTimeEntryIds = array_values(array_unique(array_merge(...array_map(
        static fn(array $row): array => $row['time_entry_ids'],
        $items
    ))));
    $allMileageAllocationIds = array_merge(...array_map(
        static fn(array $row): array => $row['mileage_allocation_ids'],
        $items
    ));
    $selectedMileageAllocationIds = array_values(array_unique($allMileageAllocationIds));
    if (count($allMileageAllocationIds) !== count($selectedMileageAllocationIds)) {
        throw new RuntimeException('A client travel charge cannot be added to more than one invoice row.');
    }
    foreach ($items as $row) {
        if (!$row['time_entry_ids']) continue;
        $placeholders = implode(',', array_fill(0, count($row['time_entry_ids']), '?'));
        $checkTotals = $pdo->prepare(
            "SELECT COUNT(*) row_count,COALESCE(SUM(hours),0) expected_hours,MIN(rate) min_rate,MAX(rate) max_rate
             FROM time_entries WHERE id IN ($placeholders) AND billed=0 FOR UPDATE"
        );
        $checkTotals->execute($row['time_entry_ids']);
        $expected = $checkTotals->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($expected['row_count'] ?? 0) !== count($row['time_entry_ids'])
            || abs((float)($expected['expected_hours'] ?? 0) - (float)$row['quantity']) > 0.005
            || (string)($expected['min_rate'] ?? '') !== (string)($expected['max_rate'] ?? '')
            || abs((float)($expected['min_rate'] ?? 0) - (float)$row['unit_price']) > 0.005) {
            throw new RuntimeException('Tracked-time quantity or rate does not match the selected entries.');
        }
    }
    if ($selectedTimeEntryIds) {
        $placeholders = implode(',', array_fill(0, count($selectedTimeEntryIds), '?'));
        $groups = $pdo->prepare(
            "SELECT DISTINCT approval_snapshot_id FROM work_billing_consumptions
             WHERE billing_time_entry_id IN ($placeholders)"
        );
        $groups->execute($selectedTimeEntryIds);
        $required = $pdo->prepare(
            'SELECT c.billing_time_entry_id FROM work_billing_consumptions c
             JOIN time_entries t ON t.id=c.billing_time_entry_id
             WHERE c.approval_snapshot_id=? AND t.billed=0 FOR UPDATE'
        );
        foreach ($groups->fetchAll(PDO::FETCH_COLUMN) as $snapshotId) {
            $required->execute([$snapshotId]);
            $missing = array_diff(array_map('intval', $required->fetchAll(PDO::FETCH_COLUMN)), $selectedTimeEntryIds);
            if ($missing) {
                throw new RuntimeException('Select every unbilled row in an approval adjustment group.');
            }
        }
    }
    foreach ($items as $row) {
        if (!$row['mileage_allocation_ids']) continue;
        $placeholders = implode(',', array_fill(0, count($row['mileage_allocation_ids']), '?'));
        $checkMileage = $pdo->prepare(
            "SELECT COUNT(*) row_count,
                    COALESCE(SUM(CASE WHEN charge_method='fixed_fee' THEN 1 ELSE billable_miles END),0) expected_quantity,
                    MIN(CASE WHEN charge_method='fixed_fee' THEN client_charge ELSE mileage_rate END) min_rate,
                    MAX(CASE WHEN charge_method='fixed_fee' THEN client_charge ELSE mileage_rate END) max_rate,
                    MIN(CASE WHEN charge_method='fixed_fee' THEN 'each' ELSE 'mile' END) min_unit,
                    MAX(CASE WHEN charge_method='fixed_fee' THEN 'each' ELSE 'mile' END) max_unit
             FROM mileage_charge_allocations
             WHERE id IN ($placeholders) AND client_id=? AND billed=0 FOR UPDATE"
        );
        $checkMileage->execute(array_merge($row['mileage_allocation_ids'], [$client_id]));
        $expected = $checkMileage->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($expected['row_count'] ?? 0) !== count($row['mileage_allocation_ids'])
            || abs((float)($expected['expected_quantity'] ?? 0) - (float)$row['quantity']) > 0.005
            || (string)($expected['min_rate'] ?? '') !== (string)($expected['max_rate'] ?? '')
            || abs((float)($expected['min_rate'] ?? 0) - (float)$row['unit_price']) > 0.0005
            || (string)($expected['min_unit'] ?? '') !== (string)($expected['max_unit'] ?? '')
            || $row['billing_unit'] !== (string)($expected['min_unit'] ?? '')) {
            throw new RuntimeException('Client travel quantity or rate no longer matches the selected charges.');
        }
    }
    $stmt = $pdo->prepare('INSERT INTO invoices (client_id, project_id, billing_mode, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, custom_fields, organization_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$client_id, $project_id, $billing_mode, $discount_type, $discount_value, $tax_percent, $subtotal, $total, 'draft', $due_date ?: null, $customFieldsJson, $__orgId, $__creator]);
    $invoice_id = (int)$pdo->lastInsertId();
    if ($project_id && project_uses_monthly_invoice_billing($pdo, $project_id)) {
        $pdo->prepare('UPDATE invoices SET collection_mode="project_aggregate" WHERE id=?')->execute([$invoice_id]);
    }
    // Assign a new Project ID and doc_number
    $projectCode = project_next_code($pdo, $client_id);
    $jobId = JobAssignmentService::ensureForCode($pdo, $client_id, $projectCode, $project_id ?: null, $__creator);
    $serviceLocationId = document_resolve_service_location($pdo,$client_id,$project_id,$jobId,$requestedServiceLocationId);
    $pdo->prepare('UPDATE invoices SET project_code=?,job_id=?,service_location_id=? WHERE id=?')->execute([$projectCode, $jobId, $serviceLocationId, $invoice_id]);
    $notes = trim((string)($_POST['project_notes'] ?? ''));
    if ($notes !== '') {
      $up = $pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes) VALUES (?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes)');
      $up->execute([$projectCode, $client_id, $notes]);
    }
    // Assign per-type doc_number for invoices
    $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([pa_next_invoice_doc_number($pdo, 'regular'), $invoice_id]);

    $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id,item_library_id,item,description,quantity,unit_price,line_total,billing_unit,is_travel,pricing_status,time_entry_id,hours,catalog_snapshot) VALUES (?,?,?,?,?,?,?,?,?,"standard",?,?,?)');
    foreach ($items as $idx => $it) {
        $primaryTimeEntryId = !empty($it['time_entry_ids']) ? (int)$it['time_entry_ids'][0] : null;
        $catalog=catalog_document_snapshot($pdo,(int)($it['catalog_id']??0),$it);
        $ii->execute([$invoice_id,$catalog['item_library_id'],$it['item'],$it['description'],$it['quantity'],$it['unit_price'],$it['line_total'],$it['billing_unit'],$it['is_travel'],$primaryTimeEntryId,$it['billing_unit']==='hour'?($it['quantity']??null):null,$catalog['catalog_snapshot']]);
        if (!empty($it['time_entry_ids'])) {
            $itemId = (int)$pdo->lastInsertId();
            $check = $pdo->prepare('SELECT id FROM time_entries WHERE id = ? AND billed = 0 AND COALESCE(external_status,"approved") = "approved" AND (client_id = ? OR client_id IS NULL OR client_id = 0) FOR UPDATE');
            $mark = $pdo->prepare('UPDATE time_entries SET client_id = CASE WHEN client_id IS NULL OR client_id = 0 THEN ? ELSE client_id END, billed = 1, invoice_item_id = ?, invoice_id = ? WHERE id = ?');
            foreach ($it['time_entry_ids'] as $teId) {
                $check->execute([(int)$teId, $client_id]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException('Invalid or already billed time entry selected.');
                }
                $mark->execute([$client_id, $itemId, $invoice_id, (int)$teId]);
            }
        }
        if (!empty($it['mileage_allocation_ids'])) {
            $itemId = (int)$pdo->lastInsertId();
            $markMileage = $pdo->prepare(
                'UPDATE mileage_charge_allocations SET billed=1,invoice_item_id=?,invoice_id=?
                 WHERE id=? AND client_id=? AND billed=0'
            );
            foreach ($it['mileage_allocation_ids'] as $allocationId) {
                $markMileage->execute([$itemId, $invoice_id, (int)$allocationId, $client_id]);
                if ($markMileage->rowCount() !== 1) {
                    throw new RuntimeException('Invalid or already billed client travel charge selected.');
                }
                $logIdStmt=$pdo->prepare('SELECT mileage_log_id FROM mileage_charge_allocations WHERE id=?');
                $logIdStmt->execute([(int)$allocationId]);$logId=(int)$logIdStmt->fetchColumn();
                $pdo->prepare('UPDATE mileage_logs m SET billed=NOT EXISTS(SELECT 1 FROM mileage_charge_allocations x WHERE x.mileage_log_id=m.id AND x.billed=0) WHERE m.id=?')->execute([$logId]);
            }
        }
    }
    
    // Add to project_documents if project_id is set
    if ($project_id) {
        $pdo->prepare('INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?, "invoice", ?)')->execute([$project_id, $invoice_id]);
    }
    
    audit_log($pdo, 'invoice.create', 'invoice', $invoice_id, ['client_id' => $client_id, 'organization_id' => $__orgId, 'created_by' => $__creator]);
    DocumentRevisionService::snapshotAndSave($pdo,'invoice',$invoice_id,$__creator,false);
    
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    header('Location: /?page=invoice/invoices-create&error=Failed%20to%20create%20invoice');
    exit;
}

if ($finalizeAndSend) {
    try {
        invoice_finalize($pdo, $invoice_id, $appConfig, 'manual_create', $__creator);
        invoice_send_finalized($pdo, $invoice_id, $appConfig);
    } catch (Throwable $e) {
        @error_log('[invoices_create] Finalization or delivery failed: ' . $e->getMessage());
        header('Location: /?page=invoice/invoice-details&id=' . $invoice_id . '&error=' . urlencode('Invoice saved, but finalization or email failed.'));
        exit;
    }
}

header('Location: /?page=invoice/invoices-list&created=1');
exit;
