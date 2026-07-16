<?php

use App\Modules\Timekeeping\WorkforceSettings;
use App\Services\TimeApprovalPolicy;
use App\Services\TimeReviewQueueService;

$userId = (int)($_SESSION['user']['id'] ?? 0);
$approvalPolicy = new TimeApprovalPolicy($pdo);
if (!$approvalPolicy->canAccessQueue($userId)) {
    http_response_code(403);
    echo '<p style="padding:24px">Time approval requires reviewer permission and an approval scope.</p>';
    return;
}

$reviewQueue = new TimeReviewQueueService($pdo, $approvalPolicy);
$queue = $reviewQueue->pendingFor($userId);
$approved = $reviewQueue->recentlyApprovedFor($userId, 50);
if ($approvalPolicy->hasGlobalReviewScope($userId)) {
    $projects = $pdo->query("SELECT id,name FROM projects WHERE status NOT IN ('completed','cancelled') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $projectIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int)($row['project_id'] ?? 0),
        array_merge($queue, $approved)
    ))));
    $projects = [];
    if ($projectIds) {
        $projectStmt = $pdo->prepare(
            'SELECT id,name FROM projects WHERE status NOT IN (\'completed\',\'cancelled\') AND id IN ('
            . implode(',', array_fill(0, count($projectIds), '?')) . ') ORDER BY name'
        );
        $projectStmt->execute($projectIds);
        $projects = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
$settings = WorkforceSettings::load($pdo);
$timezone = (string)$settings['timezone'];
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$displayTime = static fn(string $value): string => (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format('M j, Y g:i A');
$localInput = static fn(string $value): string => (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d\TH:i');
$invoiceLabel = static function (array $entry): string {
    if (empty($entry['invoice_number'])) return '';
    if (preg_match('/^(?:I|LTI|ODI)-/', (string)$entry['invoice_number'])) return (string)$entry['invoice_number'];
    return match ((string)($entry['invoice_type'] ?? 'regular')) { 'long_term' => 'LTI-', 'on_demand' => 'ODI-', default => 'I-' } . $entry['invoice_number'];
};
?>

<section class="workforce-page">
  <div class="workforce-head"><div><p class="workforce-eyebrow">Workforce</p><h2>Time approvals</h2><p class="workforce-subtitle">Review submitted time before PA creates immutable pay and billing snapshots.</p></div><div class="workforce-head__actions"><a class="btn" href="/?page=workforce/overview">Overview</a><a class="btn" href="/?page=workforce/time">Time</a></div></div>
  <?php if (!empty($_GET['success'])): ?><div class="alert alert-success"><?= $h($_GET['success']) ?></div><?php endif; ?>
  <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= $h($_GET['error']) ?></div><?php endif; ?>

  <div class="workforce-kpis">
    <article class="workforce-kpi"><span>Awaiting review</span><strong><?= number_format(count($queue)) ?></strong><small>Submitted entries</small></article>
    <article class="workforce-kpi"><span>Recently approved</span><strong><?= number_format(count($approved)) ?></strong><small>Latest 50 entries</small></article>
    <article class="workforce-kpi"><span>Timezone</span><strong style="font-size:18px"><?= $h($timezone) ?></strong><small>PA System Settings</small></article>
  </div>

  <article class="card workforce-card">
    <div class="card-head"><h3 class="card-title">Review queue</h3></div>
    <div class="workforce-review-list">
      <?php foreach ($queue as $entry): ?>
        <section class="workforce-review-item">
          <div class="workforce-review-item__head"><div><strong><?= $h($entry['employee_name']) ?></strong><span><?= $h($entry['project_name'] ?: 'No project') ?></span></div><span class="status-pill status-pill--pending">Review</span></div>
          <div class="workforce-review-meta"><span><?= $h($displayTime($entry['start_time'])) ?> &ndash; <?= $h($displayTime($entry['end_time'])) ?></span><span><?= number_format(((int)$entry['duration_seconds']) / 3600, 2) ?> hours</span><?php if ($entry['client_name']): ?><span><?= $h($entry['client_name']) ?><?= $entry['invoice_number'] ? ' &middot; ' . $h($invoiceLabel($entry)) : '' ?></span><?php endif; ?></div>
          <?php if ($entry['description']): ?><p><?= $h($entry['description']) ?></p><?php endif; ?>
          <?php if ($approvalPolicy->canReviewRecord($userId, $entry, 'approve')): ?><div class="workforce-actions">
            <form method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><button class="btn btn-primary">Approve</button></form>
            <form class="workforce-inline-form" method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><input class="input" name="reason" placeholder="What should the worker change?" required><button class="btn">Return for changes</button></form>
          </div><?php endif; ?>
        </section>
      <?php endforeach; ?>
      <?php if (!$queue): ?><p class="workforce-empty">Nothing is waiting for review.</p><?php endif; ?>
    </div>
  </article>

  <article class="card workforce-card">
    <div class="card-head"><h3 class="card-title">Recently approved</h3></div>
    <?php foreach ($approved as $entry): ?>
      <details class="workforce-approved-item"><summary><span><strong><?= $h($entry['employee_name']) ?></strong> &middot; <?= $h($entry['project_name'] ?: 'No project') ?><?php if ($entry['client_name']): ?> &middot; <?= $h($entry['client_name']) ?><?php endif; ?></span><span><?= number_format(((int)$entry['duration_seconds']) / 3600, 2) ?> h</span></summary>
        <div class="workforce-approved-item__body">
          <?php if ($approvalPolicy->canReviewRecord($userId, $entry, 'correct')): ?>
          <form class="workforce-form" method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="correct"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>">
            <label class="field"><span class="label">Project</span><select class="input" name="project_id"><option value="">No project</option><?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>" <?= (int)$entry['project_id'] === (int)$project['id'] ? 'selected' : '' ?>><?= $h($project['name']) ?></option><?php endforeach; ?></select></label>
            <div class="workforce-context-grid workforce-context-grid--time"><label class="field"><span class="label">Start</span><input class="input" type="datetime-local" name="start_time" value="<?= $h($localInput($entry['start_time'])) ?>" required></label><label class="field"><span class="label">End</span><input class="input" type="datetime-local" name="end_time" value="<?= $h($localInput($entry['end_time'])) ?>" required></label></div>
            <label class="field"><span class="label">Description</span><textarea class="input" name="description" rows="2"><?= $h($entry['description']) ?></textarea></label>
            <div class="workforce-checks"><label><input type="checkbox" name="billable" value="1" <?= $entry['billable'] ? 'checked' : '' ?>> Prepare for hourly client billing</label><label><input type="checkbox" name="is_payable" value="1" <?= $entry['is_payable'] ? 'checked' : '' ?>> Eligible for worker compensation</label></div>
            <label class="field"><span class="label">Correction reason</span><input class="input" name="reason" required></label><button class="btn">Create correction revision</button>
          </form>
          <?php endif; ?>
          <?php if ($approvalPolicy->canReviewRecord($userId, $entry, 'void')): ?>
          <form class="workforce-inline-form" method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="void"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><input class="input" name="reason" placeholder="Void reason" required><button class="btn btn-danger">Void entry</button></form>
          <?php endif; ?>
        </div>
      </details>
    <?php endforeach; ?>
    <?php if (!$approved): ?><p class="workforce-empty">No approved entries yet.</p><?php endif; ?>
  </article>
</section>
