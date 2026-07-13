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
  <article class="card workforce-card workforce-card--table"><div class="card-head"><h3 class="card-title">Pay accruals</h3></div><div class="pa-table-wrap"><table class="pa-table workforce-table"><thead><tr><?php if ($viewAll): ?><th>Employee</th><?php endif; ?><th>Date</th><th>Hours</th><th>Rate</th><th>Amount</th><th>Status</th><?php if ($manage): ?><th></th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($rows as $row): ?><tr><?php if ($viewAll): ?><td><strong><?= $h($row['employee_name']) ?></strong></td><?php endif; ?><td><?= $h(date('M j, Y', strtotime((string)$row['created_at']))) ?></td><td><?= $h($row['hours']) ?></td><td><?= $h($row['currency'] . ' ' . $row['rate']) ?></td><td><?= $h($row['currency'] . ' ' . $row['amount']) ?></td><td><span class="status-pill status-pill--<?= $h($row['status']) ?>"><?= $h(ucfirst((string)$row['status'])) ?></span></td><?php if ($manage): ?><td class="text-right"><?php if ($row['status'] !== 'voided'): ?><form method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="pay-status"><input type="hidden" name="accrual_id" value="<?= $h($row['id']) ?>"><input type="hidden" name="status" value="<?= $row['status'] === 'paid' ? 'pending' : 'paid' ?>"><button class="btn btn-sm"><?= $row['status'] === 'paid' ? 'Reopen' : 'Mark paid' ?></button></form><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= ($viewAll ? 1 : 0) + ($manage ? 6 : 5) ?>" class="workforce-empty">No pay accruals are visible.</td></tr><?php endif; ?>
  </tbody></table></div></article>
</section>
