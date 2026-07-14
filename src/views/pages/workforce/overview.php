<?php

use App\Modules\Timekeeping\WorkforceSettings;

$userId = (int)($_SESSION['user']['id'] ?? 0);
$isAdmin = in_array(acl_user_role($pdo, $userId), ['admin', 'owner'], true);
$manageWorkforce = WorkforceSettings::canManageAllTime($pdo, $userId)
    || WorkforceSettings::canReviewTime($pdo, $userId);
$canViewPay = $isAdmin
    || user_can($pdo, $userId, 'employee_pay.self', 0)
    || user_can($pdo, $userId, 'employee_pay.view', 0)
    || user_can($pdo, $userId, 'employee_pay.manage', 0);
$settings = WorkforceSettings::load($pdo);
$currency = (string)$settings['currency'];
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$money = static fn(float $amount): string => $currency . ' ' . number_format($amount, 2);

$activeEmployees = 0;
$runningNow = 0;
$awaitingReview = 0;
$pendingPay = 0.0;
$employeeRows = [];
$personal = [
    'hours_month' => 0.0,
    'awaiting_review' => 0,
    'approved_month' => 0.0,
    'pending_pay' => 0.0,
    'paid_year' => 0.0,
    'assigned_projects' => 0,
    'running' => false,
];
$assignedProjects = [];

if ($manageWorkforce) {
    $activeEmployees = (int)$pdo->query(
        "SELECT COUNT(*) FROM users u JOIN employee_profiles ep ON ep.user_id=u.id
         WHERE u.role='employee' AND u.is_disabled=0 AND u.deleted_at IS NULL AND ep.employment_status='active'"
    )->fetchColumn();
    $runningNow = (int)$pdo->query("SELECT COUNT(*) FROM work_time_entries WHERE status='running' AND end_time IS NULL")->fetchColumn();
    $awaitingReview = (int)$pdo->query("SELECT COUNT(*) FROM work_time_entries WHERE status='review'")->fetchColumn();
    $pendingPay = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM work_pay_accruals WHERE status='pending'")->fetchColumn();
    $employeeRows = $pdo->query(
        "SELECT u.id,u.email,ep.first_name,ep.last_name,ep.employment_status,
                COALESCE(SUM(CASE WHEN t.start_time>=UTC_TIMESTAMP()-INTERVAL 30 DAY AND t.status NOT IN ('cancelled','voided') THEN t.duration_seconds ELSE 0 END),0) seconds_30,
                SUM(CASE WHEN t.status='review' THEN 1 ELSE 0 END) review_count,
                MAX(CASE WHEN t.status='running' AND t.end_time IS NULL THEN 1 ELSE 0 END) running,
                (SELECT COALESCE(SUM(a.amount),0) FROM work_pay_accruals a WHERE a.employee_user_id=u.id AND a.status='pending') pending_pay
         FROM users u JOIN employee_profiles ep ON ep.user_id=u.id
         LEFT JOIN work_time_entries t ON t.user_id=u.id
         WHERE u.role='employee' AND u.deleted_at IS NULL
         GROUP BY u.id,u.email,ep.first_name,ep.last_name,ep.employment_status
         ORDER BY ep.employment_status,ep.first_name,ep.last_name,u.email"
    )->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN start_time>=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-01') AND status NOT IN ('cancelled','voided') THEN duration_seconds ELSE 0 END),0) seconds_month,
                SUM(CASE WHEN status='review' THEN 1 ELSE 0 END) review_count,
                COALESCE(SUM(CASE WHEN start_time>=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-01') AND status='approved' THEN duration_seconds ELSE 0 END),0) approved_seconds,
                MAX(CASE WHEN status='running' AND end_time IS NULL THEN 1 ELSE 0 END) running
         FROM work_time_entries WHERE user_id=?"
    );
    $stmt->execute([$userId]);
    $timeSummary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $personal['hours_month'] = ((int)($timeSummary['seconds_month'] ?? 0)) / 3600;
    $personal['awaiting_review'] = (int)($timeSummary['review_count'] ?? 0);
    $personal['approved_month'] = ((int)($timeSummary['approved_seconds'] ?? 0)) / 3600;
    $personal['running'] = !empty($timeSummary['running']);
    if ($canViewPay) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END),0) pending_pay,
                    COALESCE(SUM(CASE WHEN status='paid' AND YEAR(paid_at)=YEAR(UTC_TIMESTAMP()) THEN amount ELSE 0 END),0) paid_year
             FROM work_pay_accruals WHERE employee_user_id=?"
        );
        $stmt->execute([$userId]);
        $paySummary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $personal['pending_pay'] = (float)($paySummary['pending_pay'] ?? 0);
        $personal['paid_year'] = (float)($paySummary['paid_year'] ?? 0);
    }
    $stmt = $pdo->prepare(
        "SELECT p.id,p.name,p.status FROM project_assignments a JOIN projects p ON p.id=a.project_id
         WHERE a.user_id=? AND (a.ends_at IS NULL OR a.ends_at>UTC_TIMESTAMP(6))
           AND p.status NOT IN ('completed','cancelled') ORDER BY p.name"
    );
    $stmt->execute([$userId]);
    $assignedProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $personal['assigned_projects'] = count($assignedProjects);
}
?>

<section class="workforce-page">
  <div class="workforce-head">
    <div>
      <p class="workforce-eyebrow">Workforce</p>
      <h2><?= $manageWorkforce ? 'Team overview' : 'My workforce overview' ?></h2>
      <p class="workforce-subtitle"><?= $manageWorkforce ? 'See employee time, approval workload, and pay status in one PA workspace.' : 'A private summary of your time, assigned projects, and permitted pay information.' ?></p>
    </div>
    <div class="workforce-head__actions">
      <a class="btn btn-primary" href="/?page=workforce/time"><?= $personal['running'] ? 'Open running timer' : 'Track time' ?></a>
      <?php if ($manageWorkforce && $isAdmin): ?><a class="btn" href="/?page=accounts">Employee accounts &amp; ACL</a><?php endif; ?>
    </div>
  </div>

  <?php if ($manageWorkforce): ?>
    <div class="workforce-kpis">
      <article class="workforce-kpi"><span>Active employees</span><strong><?= number_format($activeEmployees) ?></strong><small>Enabled employee accounts</small></article>
      <article class="workforce-kpi"><span>Clocked in now</span><strong class="<?= $runningNow ? 'is-running' : '' ?>"><?= number_format($runningNow) ?></strong><small>Live timers</small></article>
      <article class="workforce-kpi"><span>Awaiting approval</span><strong><?= number_format($awaitingReview) ?></strong><small>Submitted entries</small></article>
    </div>
    <div class="workforce-overview-grid">
      <article class="card workforce-card workforce-card--table">
        <div class="card-head"><div><h3 class="card-title">Employees</h3><p class="muted text-sm mb-0">Time and pay setup is managed from each PA account.</p></div></div>
        <div class="pa-table-wrap"><table class="pa-table workforce-table"><thead><tr><th>Employee</th><th>Last 30 days</th><th>Review</th><?php if ($canViewPay): ?><th>Pending pay</th><?php endif; ?><th></th></tr></thead><tbody>
          <?php foreach ($employeeRows as $employee): ?>
            <tr>
              <td><strong><?= $h(trim($employee['first_name'] . ' ' . $employee['last_name']) ?: $employee['email']) ?></strong><small><?= $h(ucfirst((string)$employee['employment_status'])) ?><?= $employee['running'] ? ' · Clocked in' : '' ?></small></td>
              <td><?= number_format(((int)$employee['seconds_30']) / 3600, 2) ?> h</td>
              <td><?= number_format((int)$employee['review_count']) ?> awaiting</td>
              <?php if ($canViewPay): ?><td><?= $h($money((float)$employee['pending_pay'])) ?></td><?php endif; ?>
              <td class="text-right"><a class="btn btn-sm" href="/?page=account-edit&amp;id=<?= (int)$employee['id'] ?>">Account &amp; assignments</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$employeeRows): ?><tr><td colspan="<?= $canViewPay ? 5 : 4 ?>" class="workforce-empty">Create an Employee account from Accounts to get started.</td></tr><?php endif; ?>
        </tbody></table></div>
      </article>
      <aside class="card workforce-card">
        <div class="card-head"><h3 class="card-title">Workforce actions</h3></div>
        <div class="workforce-quick-links">
          <a class="workforce-quick-link" href="/?page=workforce/approvals">Review submitted time <span><?= number_format($awaitingReview) ?> waiting</span></a>
          <?php if ($canViewPay): ?><a class="workforce-quick-link" href="/?page=workforce/pay">Review employee pay <span><?= $h($money($pendingPay)) ?> pending</span></a><?php endif; ?>
          <?php if ($isAdmin): ?><a class="workforce-quick-link" href="/?page=accounts&amp;action=create">Create employee account <span>Role + ACL</span></a><?php endif; ?>
          <?php if ($isAdmin): ?><a class="workforce-quick-link" href="/?page=settings&amp;tab=system">Time defaults <span>System Settings</span></a><?php endif; ?>
        </div>
      </aside>
    </div>
  <?php else: ?>
    <div class="workforce-kpis">
      <article class="workforce-kpi"><span>Hours this month</span><strong><?= number_format($personal['hours_month'], 2) ?></strong><small><?= number_format($personal['approved_month'], 2) ?> approved</small></article>
      <article class="workforce-kpi"><span>Awaiting review</span><strong><?= number_format($personal['awaiting_review']) ?></strong><small>Your submitted entries</small></article>
      <article class="workforce-kpi"><span>Assigned projects</span><strong><?= number_format($personal['assigned_projects']) ?></strong><small>Active assignments</small></article>
    </div>
    <div class="workforce-overview-grid">
      <article class="card workforce-card">
        <div class="card-head"><h3 class="card-title">My assigned projects</h3></div>
        <?php if ($assignedProjects): ?><div class="workforce-quick-links"><?php foreach ($assignedProjects as $project): ?><div class="workforce-quick-link"><span style="font-weight:700;color:inherit"><?= $h($project['name']) ?></span><span><?= $h(ucwords(str_replace('_', ' ', (string)$project['status']))) ?></span></div><?php endforeach; ?></div><?php else: ?><p class="muted">No active projects are assigned to your account.</p><?php endif; ?>
      </article>
      <aside class="card workforce-card">
        <div class="card-head"><h3 class="card-title">My pay</h3></div>
        <?php if ($canViewPay): ?>
          <div class="workforce-quick-links">
            <a class="workforce-quick-link" href="/?page=workforce/pay">Pending pay <span><?= $h($money($personal['pending_pay'])) ?></span></a>
            <a class="workforce-quick-link" href="/?page=workforce/pay">Paid this year <span><?= $h($money($personal['paid_year'])) ?></span></a>
          </div>
        <?php else: ?><p class="muted">Your account does not have permission to view pay accruals.</p><?php endif; ?>
      </aside>
    </div>
  <?php endif; ?>
</section>
