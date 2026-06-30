<?php
// src/controllers/invoices_create.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/project_id.php';
require_once __DIR__ . '/../../utils/project_billing.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/document_fields.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';

$__orgId = get_active_org_id() ?: null;
$__creator = (int)($_SESSION['user']['id'] ?? 0) ?: null;

$client_id = (int)($_POST['client_id'] ?? 0);
$project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
$return_to_project = (int)($_POST['return_to_project'] ?? 0);
$discount_type = in_array(($_POST['discount_type'] ?? 'none'), ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
$discount_value = (float)($_POST['discount_value'] ?? 0);
$tax_percent = (float)($_POST['tax_percent'] ?? 0);
$billing_mode = ($_POST['billing_mode'] ?? 'fixed') === 'hourly' ? 'hourly' : 'fixed';
$due_date = $_POST['due_date'] ?? null;
if (!$due_date || trim($due_date) === '') {
    $due_date = project_invoice_due_date($pdo, $project_id, $appConfig);
}

$item = $_POST['item'] ?? [];
$desc = $_POST['item_desc'] ?? [];
$qty = $_POST['item_qty'] ?? [];
$price = $_POST['item_price'] ?? [];
$billingUnits = $_POST['item_billing_unit'] ?? [];
$timeEntryIdsByRow = $_POST['time_entry_ids'] ?? [];
$legacyTimeEntryIds = $_POST['time_entry_id'] ?? [];

if ($client_id <= 0 || empty($item)) {
    header('Location: /?page=invoice/invoices-create&error=Invalid%20input');
    exit;
}

$items = [];
$subtotal = 0.0;
for ($i=0; $i<count($item); $i++) {
    $itm = trim((string)($item[$i] ?? ''));
    $d = trim((string)($desc[$i] ?? ''));
    $q = (float)($qty[$i] ?? 0);
    $p = (float)($price[$i] ?? 0);
    if ($itm === '' || $q <= 0 || $p < 0) continue;
    $line = $q * $p;
    $subtotal += $line;
    $items[] = [
        'item' => $itm,
        'description' => $d,
        'quantity' => $q,
        'unit_price' => $p,
        'line_total' => $line,
        'billing_unit' => (($billingUnits[$i] ?? 'each') === 'hour' || $billing_mode === 'hourly') ? 'hour' : 'each',
        'time_entry_ids' => array_values(array_filter(array_map('intval', $timeEntryIdsByRow[$i] ?? [($legacyTimeEntryIds[$i] ?? 0)])))
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
    $stmt = $pdo->prepare('INSERT INTO invoices (client_id, project_id, billing_mode, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, custom_fields, organization_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$client_id, $project_id, $billing_mode, $discount_type, $discount_value, $tax_percent, $subtotal, $total, 'unpaid', $due_date ?: null, $customFieldsJson, $__orgId, $__creator]);
    $invoice_id = (int)$pdo->lastInsertId();
    // Assign a new Project ID and doc_number
    $projectCode = project_next_code($pdo, $client_id);
    $pdo->prepare('UPDATE invoices SET project_code=? WHERE id=?')->execute([$projectCode, $invoice_id]);
    $notes = trim((string)($_POST['project_notes'] ?? ''));
    if ($notes !== '') {
      $up = $pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes) VALUES (?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes)');
      $up->execute([$projectCode, $client_id, $notes]);
    }
    // Assign per-type doc_number for invoices
    $iMax = (int)$pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices')->fetchColumn();
    $pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$iMax + 1, $invoice_id]);

    $ii = $pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total, billing_unit, time_entry_id, hours) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($items as $idx => $it) {
        $primaryTimeEntryId = !empty($it['time_entry_ids']) ? (int)$it['time_entry_ids'][0] : null;
        $ii->execute([$invoice_id, $it['item'], $it['description'], $it['quantity'], $it['unit_price'], $it['line_total'], $it['billing_unit'], $primaryTimeEntryId, $it['billing_unit'] === 'hour' ? ($it['quantity'] ?? null) : null]);
        if (!empty($it['time_entry_ids'])) {
            $itemId = (int)$pdo->lastInsertId();
            $check = $pdo->prepare('SELECT id FROM time_entries WHERE id = ? AND client_id = ? AND billed = 0');
            $mark = $pdo->prepare('UPDATE time_entries SET billed = 1, invoice_item_id = ?, invoice_id = ? WHERE id = ?');
            foreach ($it['time_entry_ids'] as $teId) {
                $check->execute([(int)$teId, $client_id]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException('Invalid or already billed time entry selected.');
                }
                $mark->execute([$itemId, $invoice_id, (int)$teId]);
            }
        }
    }
    
    // Add to project_documents if project_id is set
    if ($project_id) {
        $pdo->prepare('INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?, "invoice", ?)')->execute([$project_id, $invoice_id]);
    }
    
    audit_log($pdo, 'invoice.create', 'invoice', $invoice_id, ['client_id' => $client_id, 'organization_id' => $__orgId, 'created_by' => $__creator]);
    
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    header('Location: /?page=invoice/invoices-create&error=Failed%20to%20create%20invoice');
    exit;
}

// Notify client that an invoice has been created (best-effort; don't fail creation)
try {
    $clientStmt = $pdo->prepare('SELECT email, name FROM clients WHERE id = ?');
    $clientStmt->execute([$client_id]);
    $clientRow = $clientStmt->fetch(PDO::FETCH_ASSOC);
    if ($clientRow && !empty($clientRow['email']) && filter_var($clientRow['email'], FILTER_VALIDATE_EMAIL)) {
        require_once __DIR__ . '/../../services/EmailService.php';
        $docnum = '';
        $invStmt = $pdo->prepare('SELECT doc_number, total FROM invoices WHERE id = ?');
        $invStmt->execute([$invoice_id]);
        $invRow = $invStmt->fetch(PDO::FETCH_ASSOC);
        if ($invRow) {
            $docnum = (string)($invRow['doc_number'] ?? $invoice_id);
            $total = (float)($invRow['total'] ?? 0);
        }
        $clientName = trim((string)($clientRow['name'] ?? ''));
        $firstName = $clientName !== '' ? preg_split('/\s+/', $clientName)[0] : 'there';
        $subject = 'Invoice I-' . $docnum . ' has been created';
        $body = '<p>Hello ' . htmlspecialchars($firstName) . ',</p>'
              . '<p>Your invoice <strong>I-' . htmlspecialchars($docnum) . '</strong> has been created for <strong>$' . number_format($total, 2) . '</strong>.</p>'
              . '<p>Due date: ' . htmlspecialchars($due_date) . '</p>'
              . '<p>You can log in to view and pay the invoice. Thank you!</p>';
        EmailService::sendEmail($clientRow['email'], $subject, $body);
    }
} catch (Throwable $e) {
    @error_log('[invoices_create] Client notification email failed: ' . $e->getMessage());
}

header('Location: /?page=invoice/invoices-list&created=1');
exit;
