<?php
// src/views/pages/invoice/recurring-invoices-list.php
// Updated: uses unified contracts table instead of long_term_contracts
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/twig.php';

// Unified contracts table replaces long_term_contracts
$has_contracts_table = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contracts'")->fetchColumn();
if (!$has_contracts_table) {
  echo '<section><h2>Recurring Billing Schedule</h2><div style="margin:10px 0;padding:12px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;color:#856404">Recurring billing is not available because the database table <code>contracts</code> is missing. Run the migrations or contact your administrator to enable this feature.</div></section>';
  return;
}

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$client_name = trim($_GET['client'] ?? '');
$status = $_GET['status'] ?? 'active';
$project_code = trim($_GET['project_code'] ?? '');
$min_price = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? (float)$_GET['min_price'] : null;
$max_price = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? (float)$_GET['max_price'] : null;

$where = ['ct.contract_type = ?'];
$p = ['long_term'];

if($client_id>0){$where[]='ct.client_id=?';$p[]=$client_id;}
elseif($client_name!==''){ $where[]='c.name LIKE ?'; $p[]='%'.$client_name.'%'; }
if ($status !== '' && $status !== 'all') {
    $where[] = 'ct.status=?';
    $p[] = $status;
}
if($project_code!==''){ $where[]='ct.project_code LIKE ?'; $p[] = $project_code.'%'; }
if($min_price !== null){ $where[]='ct.price_per_invoice >= ?'; $p[] = $min_price; }
if($max_price !== null){ $where[]='ct.price_per_invoice <= ?'; $p[] = $max_price; }

$sql = "SELECT ct.id, ct.doc_number, ct.project_code, ct.status, ct.billing_interval_count, ct.billing_interval_unit, ct.pricing_type, ct.price_per_invoice, ct.total, ct.total_invoiced, ct.next_invoice_date, ct.last_invoice_date, ct.start_date, ct.end_date, c.name client_name, c.id AS client_id 
        FROM contracts ct 
        LEFT JOIN clients c ON c.id=ct.client_id";

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY ct.next_invoice_date ASC, ct.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($p);
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<section>
  <h2>Recurring Billing Schedule</h2>

  <?php
  $filterConfig = [
      'page' => 'invoice/recurring-invoices-list',
      'filters' => [
          'client' => [
              'type' => 'client_autocomplete',
              'label' => 'Client',
              'value' => $client_name,
              'id_value' => $client_id
          ],
          'status' => [
              'type' => 'select',
              'label' => 'Status',
              'value' => $status,
              'options' => [
                  ['value' => 'all', 'label' => 'All'],
                  ['value' => 'active', 'label' => 'Active'],
                  ['value' => 'pending', 'label' => 'Pending'],
                  ['value' => 'paused', 'label' => 'Paused'],
                  ['value' => 'completed', 'label' => 'Completed'],
                  ['value' => 'cancelled', 'label' => 'Cancelled']
              ]
          ],
          'min_price' => [
              'type' => 'number',
              'label' => 'Min ($)',
              'value' => $min_price ?? '',
              'step' => '0.01'
          ],
          'max_price' => [
              'type' => 'number',
              'label' => 'Max ($)',
              'value' => $max_price ?? '',
              'step' => '0.01'
          ],
          'project_code' => [
              'type' => 'text',
              'label' => 'Project ID',
              'value' => $project_code,
              'placeholder' => 'PA-2025'
          ]
      ]
  ];
  
  echo render_template('components/document-filter.html.twig', $filterConfig);
  ?>

  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">Contract</th>
          <th style="padding:10px">Client</th>
          <th style="padding:10px">Status</th>
          <th style="padding:10px">Billing</th>
          <th style="padding:10px">Amount/Invoice</th>
          <th style="padding:10px">Last Invoice</th>
          <th style="padding:10px">Next Invoice</th>
          <th style="padding:10px">Progress</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($contracts)): ?>
          <tr>
            <td colspan="8" style="padding:20px;text-align:center;color:#6b7280">No recurring billing contracts found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($contracts as $ct): ?>
            <?php
              $billingInterval = $ct['billing_interval_count'] . ' ' . ucfirst($ct['billing_interval_unit']);
              if ($ct['billing_interval_count'] > 1) $billingInterval .= 's';
              
              $amountText = '';
              if ($ct['pricing_type'] === 'per_invoice') {
                $amountText = '$' . number_format((float)$ct['price_per_invoice'], 2);
              } else {
                $amountText = '$' . number_format((float)$ct['total'], 2) . ' total';
              }
              
              $isOngoing = empty($ct['end_date']);
              $nextDate = $ct['next_invoice_date'] ? date('M j, Y', strtotime($ct['next_invoice_date'])) : '—';
              $lastDate = $ct['last_invoice_date'] ? date('M j, Y', strtotime($ct['last_invoice_date'])) : 'Not yet';
              
              $progressText = '—';
              if ($ct['pricing_type'] === 'fixed_total' && !$isOngoing) {
                $total = (float)$ct['total'];
                $invoiced = (float)$ct['total_invoiced'];
                if ($total > 0) {
                  $percent = min(100, ($invoiced / $total) * 100);
                  $progressText = number_format($percent, 1) . '%';
                }
              }
              
              $rowStyle = '';
              if ($ct['status'] === 'active') {
                $rowStyle = 'background:#fffbeb;';
              } elseif ($ct['status'] === 'paused') {
                $rowStyle = 'background:#fef2f2;';
              } elseif ($ct['status'] === 'completed') {
                $rowStyle = 'background:#ecfdf5;';
              }
            ?>
            <tr style="border-top:1px solid #f3f4f6;<?php echo $rowStyle; ?>">
              <td style="padding:10px">
                <a href="/?page=contract/long-term-contract-details&id=<?php echo (int)$ct['id']; ?>" style="text-decoration:none;color:inherit;font-weight:600">
                  LTC-<?php echo (int)($ct['doc_number'] ?? $ct['id']); ?>
                </a>
                <div style="font-size:13px;color:#6b7280"><?php echo htmlspecialchars($ct['project_code'] ?? ''); ?></div>
              </td>
              <td style="padding:10px">
                <a href="/?page=client/clients-list&selected_client_id=<?php echo (int)$ct['client_id']; ?>">
                  <?php echo htmlspecialchars($ct['client_name']); ?>
                </a>
              </td>
              <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($ct['status']); ?></td>
              <td style="padding:10px"><?php echo htmlspecialchars($billingInterval); ?></td>
              <td style="padding:10px;font-weight:600"><?php echo htmlspecialchars($amountText); ?></td>
              <td style="padding:10px"><?php echo $lastDate; ?></td>
              <td style="padding:10px">
                <?php if ($ct['status'] === 'active' && $ct['next_invoice_date']): ?>
                  <span style="font-weight:600;color:#059669"><?php echo $nextDate; ?></span>
                <?php else: ?>
                  <span style="color:#6b7280"><?php echo $nextDate; ?></span>
                <?php endif; ?>
              </td>
              <td style="padding:10px"><?php echo htmlspecialchars($progressText); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div style="margin-top:20px;padding:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px">
    <h3 style="margin:0 0 12px 0;font-size:16px">About Recurring Billing</h3>
    <ul style="margin:0;padding-left:20px;color:#374151;line-height:1.8">
      <li><strong>Active contracts</strong> automatically generate invoices on their scheduled date</li>
      <li><strong>Pending contracts</strong> must be activated before invoicing begins</li>
      <li><strong>Paused contracts</strong> skip billing until resumed</li>
      <li><strong>Completed contracts</strong> have finished their billing cycle</li>
      <li>Invoices are generated daily by the system at 2:00 AM</li>
      <li>Fixed-total contracts complete automatically when fully invoiced</li>
    </ul>
  </div>
</section>
