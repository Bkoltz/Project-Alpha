<?php
// src/views/pages/invoice/recurring-invoices-list.php
require_once __DIR__ . '/../../../config/db.php';

// Ensure the optional long_term_contracts table exists before querying
$has_long_term_table = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='long_term_contracts'")->fetchColumn();
if (!$has_long_term_table) {
  echo '<section><h2>Recurring Billing Schedule</h2><div style="margin:10px 0;padding:12px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;color:#856404">Recurring billing is not available because the database table <code>long_term_contracts</code> is missing. Run the migrations or contact your administrator to enable this feature.</div></section>';
  return;
}

$status = $_GET['status'] ?? 'active';

$where = [];
$p = [];

if ($status !== '' && $status !== 'all') {
    $where[] = 'ltc.status=?';
    $p[] = $status;
}

$sql = "SELECT ltc.id, ltc.doc_number, ltc.project_code, ltc.status, ltc.billing_interval_count, ltc.billing_interval_unit, ltc.pricing_type, ltc.price_per_invoice, ltc.total, ltc.total_invoiced, ltc.next_invoice_date, ltc.last_invoice_date, ltc.start_date, ltc.end_date, c.name client_name, c.id AS client_id 
        FROM long_term_contracts ltc 
        LEFT JOIN clients c ON c.id=ltc.client_id";

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY ltc.next_invoice_date ASC, ltc.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($p);
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<section>
  <h2>Recurring Billing Schedule</h2>
  
  <div style="margin:16px 0;padding:12px;background:#fef3c7;border:1px solid #fbbf24;border-radius:8px">
    <div style="font-weight:600;margin-bottom:4px;color:#92400e">Automatic Invoice Generation</div>
    <div style="font-size:14px;color:#78350f">Invoices are automatically generated based on the billing schedule below. Active contracts will create new invoices on their next invoice date.</div>
  </div>

  <form method="get" action="/" style="display:flex;gap:8px;align-items:end;margin:12px 0">
    <input type="hidden" name="page" value="invoice/recurring-invoices-list">
    <label style="display:flex;flex-direction:column;gap:4px">
      <div>Status</div>
      <select name="status" style="padding:8px;border-radius:8px;border:1px solid #ddd">
        <option value="all" <?php echo $status==='all'?'selected':''; ?>>All</option>
        <option value="active" <?php echo $status==='active'?'selected':''; ?>>Active</option>
        <option value="pending" <?php echo $status==='pending'?'selected':''; ?>>Pending</option>
        <option value="paused" <?php echo $status==='paused'?'selected':''; ?>>Paused</option>
        <option value="completed" <?php echo $status==='completed'?'selected':''; ?>>Completed</option>
      </select>
    </label>
    <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Filter</button>
    <a href="/?page=invoice/recurring-invoices-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;text-decoration:none;color:inherit; font-size: small;">Reset</a>
  </form>

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
          <?php foreach ($contracts as $ltc): ?>
            <?php
              $billingInterval = $ltc['billing_interval_count'] . ' ' . ucfirst($ltc['billing_interval_unit']);
              if ($ltc['billing_interval_count'] > 1) $billingInterval .= 's';
              
              $amountText = '';
              if ($ltc['pricing_type'] === 'per_invoice') {
                $amountText = '$' . number_format((float)$ltc['price_per_invoice'], 2);
              } else {
                $amountText = '$' . number_format((float)$ltc['total'], 2) . ' total';
              }
              
              $isOngoing = empty($ltc['end_date']);
              $nextDate = $ltc['next_invoice_date'] ? date('M j, Y', strtotime($ltc['next_invoice_date'])) : '—';
              $lastDate = $ltc['last_invoice_date'] ? date('M j, Y', strtotime($ltc['last_invoice_date'])) : 'Not yet';
              
              // Calculate progress for fixed_total contracts
              $progressText = '—';
              if ($ltc['pricing_type'] === 'fixed_total' && !$isOngoing) {
                $total = (float)$ltc['total'];
                $invoiced = (float)$ltc['total_invoiced'];
                if ($total > 0) {
                  $percent = min(100, ($invoiced / $total) * 100);
                  $progressText = number_format($percent, 1) . '%';
                }
              }
              
              $rowStyle = '';
              if ($ltc['status'] === 'active') {
                $rowStyle = 'background:#fffbeb;';
              } elseif ($ltc['status'] === 'paused') {
                $rowStyle = 'background:#fef2f2;';
              } elseif ($ltc['status'] === 'completed') {
                $rowStyle = 'background:#ecfdf5;';
              }
            ?>
            <tr style="border-top:1px solid #f3f4f6;<?php echo $rowStyle; ?>">
              <td style="padding:10px">
                <a href="/?page=contract/long-term-contract-details&id=<?php echo (int)$ltc['id']; ?>" style="text-decoration:none;color:inherit;font-weight:600">
                  LTC-<?php echo (int)($ltc['doc_number'] ?? $ltc['id']); ?>
                </a>
                <div style="font-size:13px;color:#6b7280"><?php echo htmlspecialchars($ltc['project_code'] ?? ''); ?></div>
              </td>
              <td style="padding:10px">
                <a href="/?page=client/clients-list&selected_client_id=<?php echo (int)$ltc['client_id']; ?>">
                  <?php echo htmlspecialchars($ltc['client_name']); ?>
                </a>
              </td>
              <td style="padding:10px;text-transform:capitalize"><?php echo htmlspecialchars($ltc['status']); ?></td>
              <td style="padding:10px"><?php echo htmlspecialchars($billingInterval); ?></td>
              <td style="padding:10px;font-weight:600"><?php echo htmlspecialchars($amountText); ?></td>
              <td style="padding:10px"><?php echo $lastDate; ?></td>
              <td style="padding:10px">
                <?php if ($ltc['status'] === 'active' && $ltc['next_invoice_date']): ?>
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
