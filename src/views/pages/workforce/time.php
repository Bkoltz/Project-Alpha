<?php
use App\Modules\Timekeeping\AuditRecorder;
use App\Modules\Timekeeping\TimekeepingService;

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$service = new TimekeepingService($pdo, new AuditRecorder($pdo));
$manageAll = user_can($pdo, $userId, 'timekeeping.manage', 0);
$projects = $service->projectsFor($userId, $manageAll);
$running = $service->running($userId);
$entries = $service->entries($userId, false, 100);
$business = $pdo->query('SELECT timezone FROM business_settings WHERE singleton=1')->fetch(PDO::FETCH_ASSOC) ?: ['timezone' => 'UTC'];
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$displayTime = static function(?string $value) use ($business): string { if(!$value)return '-'; return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string)$business['timezone']))->format('Y-m-d H:i'); };
$inputTime = static function(?string $value) use ($business): string { if(!$value)return ''; return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string)$business['timezone']))->format('Y-m-d\\TH:i'); };
?>
<style>
.al-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px}.al-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px;margin-bottom:18px}.al-card h2,.al-card h3{margin-top:0}.al-form{display:grid;gap:12px}.al-form label{display:grid;gap:6px;font-weight:600}.al-form input,.al-form select,.al-form textarea{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px}.al-actions{display:flex;gap:8px;flex-wrap:wrap}.al-table{width:100%;border-collapse:collapse}.al-table th,.al-table td{text-align:left;padding:10px;border-bottom:1px solid #e2e8f0;vertical-align:top}.al-alert{padding:12px 14px;border-radius:10px;margin-bottom:16px;background:#ecfdf5;color:#166534}.al-alert.error{background:#fef2f2;color:#991b1b}.al-muted{color:#64748b;font-size:13px}
</style>
<div class="page-header"><div><p class="eyebrow">Workforce</p><h1>Time</h1><p>Server-authoritative timekeeping. Times are entered in <?= $h($business['timezone']) ?> and stored in UTC.</p></div></div>
<?php if (!empty($_GET['success'])): ?><div class="al-alert"><?= $h($_GET['success']) ?></div><?php endif; ?>
<?php if (!empty($_GET['error'])): ?><div class="al-alert error"><?= $h($_GET['error']) ?></div><?php endif; ?>

<div class="al-grid">
  <section class="al-card">
    <h2><?= $running ? 'Running timer' : 'Clock in' ?></h2>
    <?php if ($running): ?>
      <p><strong><?= $h($running['project_name'] ?: 'Unassigned') ?></strong><br><?= $h($running['description']) ?></p>
      <p class="al-muted">Started <?= $h($displayTime($running['start_time'])) ?> <?= $h($business['timezone']) ?></p>
      <div class="al-actions">
        <form method="post" action="/time/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="clock-out"><input type="hidden" name="entry_id" value="<?= $h($running['id']) ?>"><button class="btn btn-primary">Clock out</button></form>
        <?php if ($running['open_break_id']): ?>
        <form method="post" action="/time/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="break-end"><input type="hidden" name="break_id" value="<?= $h($running['open_break_id']) ?>"><button class="btn">End break</button></form>
        <?php else: ?>
        <form method="post" action="/time/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="break-start"><input type="hidden" name="entry_id" value="<?= $h($running['id']) ?>"><button class="btn">Start break</button></form>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <form class="al-form" method="post" action="/time/action">
        <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="clock-in">
        <label>Assigned project<select name="project_id"><option value="">No project</option><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>"><?= $h($project['name']) ?></option><?php endforeach; ?></select></label>
        <label>Description<textarea name="description" rows="3"></textarea></label>
        <button class="btn btn-primary">Clock in</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="al-card">
    <h2>Manual time</h2>
    <form class="al-form" method="post" action="/time/action">
      <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="manual-create">
      <label>Assigned project<select name="project_id"><option value="">No project</option><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>"><?= $h($project['name']) ?></option><?php endforeach; ?></select></label>
      <label>Start<input type="datetime-local" name="start_time" required></label>
      <label>End<input type="datetime-local" name="end_time" required></label>
      <label>Description<textarea name="description" rows="2"></textarea></label>
      <label><span><input type="checkbox" name="is_payable" value="1" checked style="width:auto"> Payable</span></label>
      <button class="btn btn-primary">Submit for review</button>
    </form>
  </section>
</div>

<section class="al-card">
  <h2>Your entries</h2>
  <div style="overflow:auto"><table class="al-table"><thead><tr><th>Date</th><th>Project</th><th>Duration</th><th>Status</th><th>Description</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($entries as $entry): ?><tr><td><?= $h($displayTime($entry['start_time'])) ?></td><td><?= $h($entry['project_name'] ?: '-') ?></td><td><?= number_format(((int) $entry['duration_seconds']) / 3600, 2) ?> h</td><td><?= $h(ucfirst($entry['status'])) ?><?php if ($entry['rejection_reason']): ?><small class="al-muted" style="display:block"><?= $h($entry['rejection_reason']) ?></small><?php endif; ?></td><td><?= $h($entry['description']) ?></td><td>
    <?php if ($entry['status'] === 'rejected'): ?><details><summary>Edit and resubmit</summary><form class="al-form" method="post" action="/time/action" style="min-width:280px;margin-top:8px"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="resubmit"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><label>Project<select name="project_id"><option value="">No project</option><?php foreach($projects as $project): ?><option value="<?= (int)$project['id'] ?>" <?= (int)$entry['project_id']===(int)$project['id']?'selected':'' ?>><?= $h($project['name']) ?></option><?php endforeach; ?></select></label><label>Start<input type="datetime-local" name="start_time" value="<?= $h($inputTime($entry['start_time'])) ?>" required></label><label>End<input type="datetime-local" name="end_time" value="<?= $h($inputTime($entry['end_time'])) ?>" required></label><label>Description<textarea name="description" rows="2"><?= $h($entry['description']) ?></textarea></label><label><span><input type="checkbox" name="is_payable" value="1" <?= $entry['is_payable']?'checked':'' ?> style="width:auto"> Payable</span></label><button class="btn btn-primary">Resubmit</button></form></details><?php endif; ?>
    <?php if (in_array($entry['status'], ['review','rejected'], true)): ?><form method="post" action="/time/action" style="margin-top:8px" onsubmit="return confirm('Cancel this time entry?');"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><button class="btn">Cancel</button></form><?php endif; ?>
  </td></tr><?php endforeach; ?>
  <?php if (!$entries): ?><tr><td colspan="6">No time entries yet.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>

<?php // Pending actions are rendered once in the entries table above. ?>
<?php $editableEntries = []; ?>
<?php if ($editableEntries): ?><section class="al-card"><h2>Pending entry actions</h2><div class="al-grid"><?php foreach($editableEntries as $entry): ?><article><strong><?= $h($displayTime($entry['start_time'])) ?> · <?= $h($entry['project_name'] ?: 'No project') ?></strong><?php if($entry['status']==='rejected'): ?><p class="al-muted"><?= $h($entry['rejection_reason']) ?></p><details><summary>Edit and resubmit</summary><form class="al-form" method="post" action="/time/action" style="margin-top:8px"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="resubmit"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><label>Project<select name="project_id"><option value="">No project</option><?php foreach($projects as $project): ?><option value="<?= (int)$project['id'] ?>" <?= (int)$entry['project_id']===(int)$project['id']?'selected':'' ?>><?= $h($project['name']) ?></option><?php endforeach; ?></select></label><label>Start<input type="datetime-local" name="start_time" value="<?= $h($inputTime($entry['start_time'])) ?>" required></label><label>End<input type="datetime-local" name="end_time" value="<?= $h($inputTime($entry['end_time'])) ?>" required></label><label>Description<textarea name="description" rows="2"><?= $h($entry['description']) ?></textarea></label><label><span><input type="checkbox" name="is_payable" value="1" <?= $entry['is_payable']?'checked':'' ?> style="width:auto"> Payable</span></label><button class="btn btn-primary">Resubmit</button></form></details><?php else: ?><p class="al-muted">Awaiting approval.</p><?php endif; ?><form method="post" action="/time/action" style="margin-top:8px" onsubmit="return confirm('Cancel this time entry?');"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><button class="btn">Cancel</button></form></article><?php endforeach; ?></div></section><?php endif; ?>
