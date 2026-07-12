<?php
$payRows = [];
try {
    $payStmt = $pdo->prepare("SELECT epr.*,COALESCE(NULLIF(epr.employee_name_snapshot,''),NULLIF(u.username,''),u.email,epr.external_employee_id,'Unlinked employee') AS employee_name,u.email FROM employee_pay_records epr LEFT JOIN users u ON u.id=epr.user_id WHERE epr.deleted_at IS NULL AND (?=0 OR epr.organization_id=?) ORDER BY COALESCE(epr.accrued_at,epr.created_at) DESC LIMIT 250");
    $payStmt->execute([$orgId, $orgId]);
    $payRows = $payStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
}
?>
<div class="expense-section-head">
  <div><h3>Employee Pay</h3><p>Approved AlphaLedger pay accruals. PA owns payment status; changing it sends a signed status event back to AL.</p></div>
</div>
<?php if (!$payRows): ?>
  <div class="expense-empty"><strong>No AlphaLedger pay records</strong><p>Approved payable time appears here after AlphaLedger connects and syncs.</p></div>
<?php else: ?>
  <div class="pa-table-wrap"><table class="pa-table expense-table">
    <thead><tr><th>Employee</th><th>Hours</th><th>Rate</th><th>Amount</th><th>Status</th><th>Received</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($payRows as $row): ?>
      <tr>
        <td><strong><?php echo htmlspecialchars((string)$row['employee_name']); ?></strong><?php if(!empty($row['email'])): ?><small style="display:block;color:var(--muted)"><?php echo htmlspecialchars((string)$row['email']); ?></small><?php elseif(empty($row['user_id'])): ?><small style="display:block;color:var(--muted)">AL identity is not linked to a PA user</small><?php endif; ?></td>
        <td><?php echo number_format((float)$row['hours'], 2); ?></td>
        <td><?php echo htmlspecialchars((string)$row['currency']); ?> <?php echo number_format((float)$row['rate'], 2); ?></td>
        <td><strong><?php echo htmlspecialchars((string)$row['currency']); ?> <?php echo number_format((float)$row['amount'], 2); ?></strong></td>
        <td><span class="expense-badge"><?php echo htmlspecialchars(ucfirst((string)$row['status'])); ?></span></td>
        <td><?php echo htmlspecialchars(substr((string)$row['created_at'], 0, 10)); ?></td>
        <td><div class="expense-row-actions">
          <?php foreach (($row['status'] === 'pending' ? ['paid' => 'Mark Paid', 'voided' => 'Void'] : ['pending' => 'Reopen']) as $status => $label): ?>
            <form method="post" action="/?page=financial/employee-pay-status" style="display:inline">
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
              <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
              <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
              <button class="btn btn-sm" type="submit"><?php echo htmlspecialchars($label); ?></button>
            </form>
          <?php endforeach; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
