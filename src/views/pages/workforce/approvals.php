<?php

use App\Modules\Timekeeping\WorkforceSettings;

$userId = (int)($_SESSION['user']['id'] ?? 0);
if (!WorkforceSettings::canReviewTime($pdo, $userId)) {
    http_response_code(403);
    echo '<p style="padding:24px">Time approval is limited to administrators unless non-admin reviewers are enabled in Workflow settings and granted the Approvals Review permission.</p>';
    return;
}

$queueStmt = $pdo->prepare(
    "SELECT t.*,p.name project_name,c.name client_name,i.doc_number invoice_number,i.invoice_type,
            COALESCE(NULLIF(TRIM(CONCAT(ep.first_name,' ',ep.last_name)),''),u.username,u.email) employee_name
     FROM work_time_entries t JOIN users u ON u.id=t.user_id
     LEFT JOIN employee_profiles ep ON ep.user_id=t.user_id
     LEFT JOIN projects p ON p.id=t.project_id LEFT JOIN clients c ON c.id=t.client_id LEFT JOIN invoices i ON i.id=t.invoice_id
     WHERE t.status='review' AND t.user_id<>? ORDER BY t.start_time"
);
$queueStmt->execute([$userId]);
$queue = $queueStmt->fetchAll(PDO::FETCH_ASSOC);
$approved = $pdo->query(
    "SELECT t.*,s.id snapshot_id,s.client_name,s.invoice_number,
            COALESCE(NULLIF(TRIM(CONCAT(ep.first_name,' ',ep.last_name)),''),u.username,u.email) employee_name,
            p.name project_name
     FROM work_time_entries t JOIN users u ON u.id=t.user_id
     LEFT JOIN employee_profiles ep ON ep.user_id=t.user_id LEFT JOIN projects p ON p.id=t.project_id
     LEFT JOIN work_approval_snapshots s ON s.id=(
         SELECT s2.id FROM work_approval_snapshots s2
         WHERE s2.time_entry_id=t.id AND s2.entry_revision<=t.revision AND s2.voided_at IS NULL
         ORDER BY s2.entry_revision DESC LIMIT 1
     )
     WHERE t.status='approved' ORDER BY t.reviewed_at DESC LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);
$projects = $pdo->query("SELECT id,name FROM projects WHERE status NOT IN ('completed','cancelled') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
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
          <div class="workforce-review-meta"><span><?= $h($displayTime($entry['start_time'])) ?> – <?= $h($displayTime($entry['end_time'])) ?></span><span><?= number_format(((int)$entry['duration_seconds']) / 3600, 2) ?> hours</span><?php if ($entry['client_name']): ?><span><?= $h($entry['client_name']) ?><?= $entry['invoice_number'] ? ' · ' . $h($invoiceLabel($entry)) : '' ?></span><?php endif; ?></div>
          <?php if ($entry['description']): ?><p><?= $h($entry['description']) ?></p><?php endif; ?>
          <div class="workforce-actions">
            <form method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><button class="btn btn-primary">Approve</button></form>
            <form class="workforce-inline-form" method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><input class="input" name="reason" placeholder="Reason for rejection" required><button class="btn">Reject</button></form>
          </div>
        </section>
      <?php endforeach; ?>
      <?php if (!$queue): ?><p class="workforce-empty">Nothing is waiting for review.</p><?php endif; ?>
    </div>
  </article>

  <article class="card workforce-card">
    <div class="card-head"><h3 class="card-title">Recently approved</h3></div>
    <?php foreach ($approved as $entry): ?>
      <details class="workforce-approved-item"><summary><span><strong><?= $h($entry['employee_name']) ?></strong> · <?= $h($entry['project_name'] ?: 'No project') ?><?php if ($entry['client_name']): ?> · <?= $h($entry['client_name']) ?><?php endif; ?></span><span><?= number_format(((int)$entry['duration_seconds']) / 3600, 2) ?> h</span></summary>
        <div class="workforce-approved-item__body">
          <form class="workforce-form" method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="correct"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>">
            <label class="field"><span class="label">Project</span><select class="input" name="project_id"><option value="">No project</option><?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>" <?= (int)$entry['project_id'] === (int)$project['id'] ? 'selected' : '' ?>><?= $h($project['name']) ?></option><?php endforeach; ?></select></label>
            <div class="workforce-context-grid workforce-context-grid--time"><label class="field"><span class="label">Start</span><input class="input" type="datetime-local" name="start_time" value="<?= $h($localInput($entry['start_time'])) ?>" required></label><label class="field"><span class="label">End</span><input class="input" type="datetime-local" name="end_time" value="<?= $h($localInput($entry['end_time'])) ?>" required></label></div>
            <label class="field"><span class="label">Description</span><textarea class="input" name="description" rows="2"><?= $h($entry['description']) ?></textarea></label>
            <div class="workforce-checks"><label><input type="checkbox" name="billable" value="1" <?= $entry['billable'] ? 'checked' : '' ?>> Billable</label><label><input type="checkbox" name="is_payable" value="1" <?= $entry['is_payable'] ? 'checked' : '' ?>> Payable</label></div>
            <label class="field"><span class="label">Correction reason</span><input class="input" name="reason" required></label><button class="btn">Create correction revision</button>
          </form>
          <form class="workforce-inline-form" method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="void"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><input class="input" name="reason" placeholder="Void reason" required><button class="btn btn-danger">Void entry</button></form>
        </div>
      </details>
    <?php endforeach; ?>
    <?php if (!$approved): ?><p class="workforce-empty">No approved entries yet.</p><?php endif; ?>
  </article>
</section>
