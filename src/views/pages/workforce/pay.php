<?php
$userId = (int)($_SESSION['user']['id'] ?? 0);
$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$manage = $isAdmin || user_can($pdo, $userId, 'employee_pay.manage', 0);
$viewAll = $manage || user_can($pdo, $userId, 'employee_pay.view', 0);
if ($viewAll) {
    $stmt = $pdo->query('SELECT * FROM work_pay_accruals ORDER BY created_at DESC LIMIT 500');
} else {
    $stmt = $pdo->prepare('SELECT a.* FROM work_pay_accruals a JOIN employee_profiles ep ON ep.user_id=a.employee_user_id WHERE a.employee_user_id=? AND ep.employee_can_view_pay=1 ORDER BY a.created_at DESC LIMIT 500');
    $stmt->execute([$userId]);
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$workerStmt=$pdo->prepare('SELECT id FROM worker_profiles WHERE user_id=?');$workerStmt->execute([$userId]);$currentWorkerId=(int)$workerStmt->fetchColumn();
$statementSql='SELECT ws.*,pp.period_start,pp.period_end,wp.display_name,wp.relationship_type,(SELECT COUNT(*) FROM worker_statement_lines l WHERE l.worker_statement_id=ws.id) line_count FROM worker_statements ws JOIN pay_periods pp ON pp.id=ws.pay_period_id JOIN worker_profiles wp ON wp.id=ws.worker_profile_id';
if(!$viewAll){$statementSql.=' WHERE ws.worker_profile_id=?';$statementStmt=$pdo->prepare($statementSql.' ORDER BY pp.period_end DESC,ws.id DESC');$statementStmt->execute([$currentWorkerId]);}else{$statementStmt=$pdo->query($statementSql.' ORDER BY pp.period_end DESC,ws.id DESC');}
$statements=$statementStmt->fetchAll(PDO::FETCH_ASSOC);
$totals = ['pending' => [], 'paid' => []];
foreach ($rows as $row) {
    if (isset($totals[$row['status']])) {
        $totals[$row['status']][$row['currency']] = ($totals[$row['status']][$row['currency']] ?? 0) + (float)$row['amount'];
    }
}
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$formatTotals = static function (array $values): string {
    if (!$values) return '—';
    return implode(' · ', array_map(static fn($currency, $amount): string => $currency . ' ' . number_format((float)$amount, 2), array_keys($values), $values));
};
?>
<section class="workforce-page">
  <div class="workforce-head"><div><p class="workforce-eyebrow">Workforce</p><h2><?= $viewAll ? 'Employee pay' : 'My pay' ?></h2><p class="workforce-subtitle">Pay accruals created from approved time. PA records status and history; it does not process payroll.</p></div><div class="workforce-head__actions"><a class="btn" href="/?page=workforce/overview">Overview</a><a class="btn" href="/?page=workforce/time">Time</a></div></div>
  <?php if (!empty($_GET['success'])): ?><div class="alert alert-success"><?= $h($_GET['success']) ?></div><?php endif; ?>
  <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= $h($_GET['error']) ?></div><?php endif; ?>
  <div class="workforce-kpis">
    <article class="workforce-kpi"><span>Pending</span><strong style="font-size:20px"><?= $h($formatTotals($totals['pending'])) ?></strong><small>Approved, not marked paid</small></article>
    <article class="workforce-kpi"><span>Paid</span><strong style="font-size:20px"><?= $h($formatTotals($totals['paid'])) ?></strong><small>Recorded as paid</small></article>
    <article class="workforce-kpi"><span>Visible records</span><strong><?= number_format(count($rows)) ?></strong><small><?= $viewAll ? 'Team accruals' : 'Your accruals only' ?></small></article>
  </div>
  <article class="card workforce-card workforce-card--table"><div class="card-head"><div><h3 class="card-title">Period statements</h3><p class="muted text-sm mb-0">Employees receive pay statements. Contractors receive settlement statements and may attach their own invoice.</p></div></div><div class="pa-table-wrap"><table class="pa-table workforce-table"><thead><tr><?php if($viewAll):?><th>Worker</th><?php endif;?><th>Period</th><th>Type</th><th>Lines</th><th>Total</th><th>Status</th><th>Contractor invoice</th><?php if($manage):?><th></th><?php endif;?></tr></thead><tbody><?php foreach($statements as $statement):?><tr><?php if($viewAll):?><td><strong><?=$h($statement['display_name'])?></strong><small><?=$h(ucfirst($statement['relationship_type']))?></small></td><?php endif;?><td><?=$h($statement['period_start'].' – '.$statement['period_end'])?></td><td><?=$h($statement['statement_type']==='contractor_settlement'?'Contractor settlement':'Employee pay')?></td><td><?=(int)$statement['line_count']?></td><td><?=$h($statement['currency'].' '.number_format((float)$statement['total_amount'],2))?></td><td><span class="status-pill status-pill--<?=$h($statement['status'])?>"><?=$h(ucfirst($statement['status']))?></span></td><td><?php if($statement['statement_type']==='contractor_settlement'):?><?php if($statement['contractor_invoice_path']):?><a class="btn btn-sm" href="/?page=workforce/contractor-invoice-download&id=<?=(int)$statement['id']?>">Download</a><?php else:?><form method="post" enctype="multipart/form-data" action="/?page=workforce/contractor-invoice" class="inline-form"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="statement_id" value="<?=(int)$statement['id']?>"><input class="input input--small" type="file" name="contractor_invoice" accept="application/pdf,.pdf" required><button class="btn btn-sm">Attach</button></form><?php endif;?><?php else:?>—<?php endif;?></td><?php if($manage):?><td class="text-right"><?php if($statement['status']==='issued'):?><form method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="statement-settle"><input type="hidden" name="statement_id" value="<?=(int)$statement['id']?>"><button class="btn btn-sm">Mark settled</button></form><?php endif;?></td><?php endif;?></tr><?php endforeach;?><?php if(!$statements):?><tr><td colspan="<?=($viewAll?1:0)+($manage?7:6)?>" class="workforce-empty">No period statements yet.</td></tr><?php endif;?></tbody></table></div></article>
  <article class="card workforce-card workforce-card--table"><div class="card-head"><div><h3 class="card-title">Approved time accruals</h3><p class="muted text-sm mb-0">Detail retained for compatibility; period statements are the settlement record.</p></div></div><div class="pa-table-wrap"><table class="pa-table workforce-table"><thead><tr><?php if ($viewAll): ?><th>Employee</th><?php endif; ?><th>Date</th><th>Hours</th><th>Rate</th><th>Amount</th><th>Status</th><?php if ($manage): ?><th></th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($rows as $row): ?><tr><?php if ($viewAll): ?><td><strong><?= $h($row['employee_name']) ?></strong></td><?php endif; ?><td><?= $h(date('M j, Y', strtotime((string)$row['created_at']))) ?></td><td><?= $h($row['hours']) ?></td><td><?= $h($row['currency'] . ' ' . $row['rate']) ?></td><td><?= $h($row['currency'] . ' ' . $row['amount']) ?></td><td><span class="status-pill status-pill--<?= $h($row['status']) ?>"><?= $h(ucfirst((string)$row['status'])) ?></span></td><?php if ($manage): ?><td class="text-right"><?php if ($row['status'] !== 'voided'): ?><form method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="pay-status"><input type="hidden" name="accrual_id" value="<?= $h($row['id']) ?>"><input type="hidden" name="status" value="<?= $row['status'] === 'paid' ? 'pending' : 'paid' ?>"><button class="btn btn-sm"><?= $row['status'] === 'paid' ? 'Reopen' : 'Mark paid' ?></button></form><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= ($viewAll ? 1 : 0) + ($manage ? 6 : 5) ?>" class="workforce-empty">No pay accruals are visible.</td></tr><?php endif; ?>
  </tbody></table></div></article>
</section>
