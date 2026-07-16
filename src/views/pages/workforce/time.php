<?php

use App\Modules\Timekeeping\AuditRecorder;
use App\Modules\Timekeeping\TimekeepingService;
use App\Modules\Timekeeping\WorkforceSettings;

$userId = (int)($_SESSION['user']['id'] ?? 0);
$service = new TimekeepingService($pdo, new AuditRecorder($pdo));
$manageAll = WorkforceSettings::canManageAllTime($pdo, $userId);
if (!$manageAll && !user_can($pdo, $userId, 'timekeeping.self', 0)) {
    http_response_code(403);
    echo '<p style="padding:24px">You do not have permission to access timekeeping.</p>';
    return;
}
$timeUsers = $manageAll ? $service->usersForManager() : [];
$selectedUserId = $manageAll ? (int)($_GET['user'] ?? $userId) : $userId;
$selectedUser = null;
foreach ($timeUsers as $candidate) {
    if ((int)$candidate['id'] === $selectedUserId) {
        $selectedUser = $candidate;
        break;
    }
}
if ($manageAll && !$selectedUser) {
    $selectedUserId = $userId;
    foreach ($timeUsers as $candidate) {
        if ((int)$candidate['id'] === $selectedUserId) {
            $selectedUser = $candidate;
            break;
        }
    }
}
$selectedRole = (string)($selectedUser['role'] ?? ($_SESSION['user']['role'] ?? 'member'));
$projects = $service->projectsFor($selectedUserId, $manageAll);
$jobs = $service->jobsFor($selectedUserId, $manageAll);
$workTypes = $service->workTypes();
$assignments = $service->assignmentsFor($selectedUserId);
$offeredAssignments = $selectedUserId===$userId ? $service->offeredAssignmentsFor($selectedUserId) : [];
$canEditInvoices = $manageAll && user_can($pdo, $userId, 'invoices.edit', 0);
$invoices = $canEditInvoices ? array_values(array_filter(
    $service->invoicesForManager(),
    static fn(array $invoice): bool => can_access_record($pdo, 'invoices', (int)$invoice['id'], $userId)
)) : [];
$running = $service->running($selectedUserId);
$entries = $service->entries($selectedUserId, false, 100);
$settings = WorkforceSettings::load($pdo);
$timezone = (string)$settings['timezone'];
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$displayTime = static function (?string $value) use ($timezone): string {
    if (!$value) {
        return '-';
    }
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone($timezone))
        ->format('M j, Y g:i A');
};
$inputTime = static function (?string $value) use ($timezone): string {
    if (!$value) {
        return '';
    }
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone($timezone))
        ->format('Y-m-d\TH:i');
};
$invoiceLabel = static function (array $entry): string {
    if (empty($entry['invoice_number'])) {
        return '';
    }
    $prefix = match ((string)($entry['invoice_type'] ?? 'regular')) {
        'long_term' => 'LTI-',
        'on_demand' => 'ODI-',
        default => 'I-',
    };
    return $prefix . $entry['invoice_number'];
};
$hoursTotal = 0.0;
$reviewCount = 0;
foreach ($entries as $entry) {
    if (!in_array($entry['status'], ['cancelled', 'voided'], true)) {
        $hoursTotal += ((int)$entry['duration_seconds']) / 3600;
    }
    if ($entry['status'] === 'review') {
        $reviewCount++;
    }
}
$defaultPayable = $selectedRole === 'employee';
?>

<section class="workforce-page" data-workforce-time-page data-running-start="<?= $h($running['start_time'] ?? '') ?>">
  <div class="workforce-head">
    <div>
      <p class="workforce-eyebrow">Workforce</p>
      <h2>Time</h2>
      <p class="workforce-subtitle">Capture time now and add client, Job, Work Type, or invoice details when they are available. Entries are stored in UTC.</p>
    </div>
    <div class="workforce-head__actions">
      <a class="btn" href="/?page=workforce/overview">Overview</a>
      <?php if ($manageAll): ?><a class="btn" href="/?page=accounts">Manage employee accounts</a><?php endif; ?>
    </div>
  </div>

  <?php if (!empty($_GET['success'])): ?><div class="alert alert-success"><?= $h($_GET['success']) ?></div><?php endif; ?>
  <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= $h($_GET['error']) ?></div><?php endif; ?>

  <?php if ($manageAll): ?>
    <form class="workforce-person-switcher" method="get" action="/">
      <input type="hidden" name="page" value="workforce/time">
      <label class="field">
        <span class="label">Enter or review time for</span>
        <select class="input" name="user" onchange="this.form.submit()">
          <?php foreach ($timeUsers as $person): ?>
            <option value="<?= (int)$person['id'] ?>" <?= (int)$person['id'] === $selectedUserId ? 'selected' : '' ?>>
              <?= $h($person['display_name']) ?><?= $person['role'] !== 'employee' ? ' · ' . $h(ucfirst((string)$person['role'])) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
  <?php endif; ?>

  <div class="workforce-kpis">
    <article class="workforce-kpi"><span>Recorded hours</span><strong><?= number_format($hoursTotal, 2) ?></strong><small>Most recent 100 entries</small></article>
    <article class="workforce-kpi"><span>Awaiting review</span><strong><?= number_format($reviewCount) ?></strong><small>Submitted entries</small></article>
    <article class="workforce-kpi"><span>Timer</span><strong class="<?= $running ? 'is-running' : '' ?>"><?= $running ? 'Running' : 'Stopped' ?></strong><small id="workforce-timer-display"><?= $running ? '00:00:00' : 'Ready to clock in' ?></small></article>
  </div>

  <?php if($offeredAssignments):?><article class="card workforce-card"><div class="card-head"><div><h3 class="card-title">Assignment offers</h3><p class="muted text-sm mb-0">Review the scope and estimated compensation before accepting.</p></div></div><div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Job / work</th><th>Expected time</th><th>Estimated compensation</th><th class="text-right">Decision</th></tr></thead><tbody><?php foreach($offeredAssignments as $offer):?><tr><td><strong><?=$h($offer['job_code'])?></strong><small><?=$h($offer['name'].' · '.$offer['work_type_name'])?></small></td><td><?=$offer['expected_duration_minutes']!==null?(int)$offer['expected_duration_minutes'].' min':'Not estimated'?></td><td><?=$offer['estimated_pay']!==null?'$'.number_format((float)$offer['estimated_pay'],2).' '.$h($offer['currency']):'Nonpayable / internal'?></td><td class="text-right"><form method="post" action="/?page=workforce/action" class="inline-form"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="assignment-accept"><input type="hidden" name="assignment_id" value="<?=(int)$offer['id']?>"><button class="btn btn-sm btn-primary">Accept</button></form><form method="post" action="/?page=workforce/action" class="inline-form"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="assignment-decline"><input type="hidden" name="assignment_id" value="<?=(int)$offer['id']?>"><input class="input input--small" name="reason" placeholder="Reason" required><button class="btn btn-sm">Decline</button></form></td></tr><?php endforeach;?></tbody></table></div></article><?php endif;?>

  <?php if($selectedUserId===$userId&&$assignments):?><article class="card workforce-card"><div class="card-head"><h3 class="card-title">My active assignments</h3></div><div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Job / work</th><th>Status</th><th class="text-right">Action</th></tr></thead><tbody><?php foreach($assignments as $assignment):?><tr><td><strong><?=$h($assignment['job_code'])?></strong><small><?=$h($assignment['name'].' · '.$assignment['work_type_name'])?></small></td><td><?=$h(ucfirst($assignment['status']))?></td><td class="text-right"><form method="post" action="/?page=workforce/action" class="inline-form"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="assignment_id" value="<?=(int)$assignment['id']?>"><input type="hidden" name="action" value="<?=$assignment['status']==='accepted'?'assignment-start':'assignment-complete'?>"><button class="btn btn-sm"><?=$assignment['status']==='accepted'?'Start work':'Mark completed'?></button></form></td></tr><?php endforeach;?></tbody></table></div></article><?php endif;?>

  <div class="workforce-grid workforce-grid--two">
    <?php if ($manageAll && in_array($selectedRole, ['admin','owner'], true)): ?>
    <article class="card workforce-card">
      <div class="card-head"><div><h3 class="card-title">Quick work entry</h3><p class="muted text-sm mb-0">Record owner onsite or editing time without clocking in. It is self-confirmed and never creates owner pay.</p></div></div>
      <form class="workforce-form" method="post" action="/?page=workforce/action" data-workforce-entry-form>
        <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="quick-duration"><input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>">
        <div class="workforce-context-grid"><label class="field"><span class="label">Work date</span><input class="input" type="date" name="work_date" value="<?= $h((new DateTimeImmutable('now',new DateTimeZone($timezone)))->format('Y-m-d')) ?>" required></label><label class="field"><span class="label">Duration (minutes)</span><input class="input" type="number" name="duration_minutes" min="1" max="1440" required></label><label class="field"><span class="label">Optional exact start</span><input class="input" type="datetime-local" name="start_time"></label></div>
        <div class="workforce-context-grid">
          <label class="field workforce-combobox" data-workforce-client-combobox><span class="label">Client <small>optional</small></span><input class="input" type="search" autocomplete="off" placeholder="Type to search clients" data-workforce-client-search aria-autocomplete="list" aria-expanded="false"><input type="hidden" name="client_id" data-workforce-client><span class="workforce-combobox__results" data-workforce-client-results role="listbox" hidden></span></label>
          <label class="field"><span class="label">Job</span><select class="input" name="job_id"><option value="">No Job</option><?php foreach($jobs as $job): ?><option value="<?= (int)$job['id'] ?>" data-client-id="<?= (int)$job['client_id'] ?>" data-client-name="<?= $h($job['client_name']) ?>" data-project-id="<?= (int)($job['project_id'] ?? 0) ?>"><?= $h($job['job_code'].' · '.$job['client_name']) ?></option><?php endforeach; ?></select></label>
          <label class="field"><span class="label">Work Type</span><select class="input" name="work_type_id"><option value="">Unclassified work</option><?php foreach($workTypes as $type): ?><option value="<?= (int)$type['id'] ?>"><?= $h($type['name']) ?></option><?php endforeach; ?></select></label>
        </div>
        <label class="field"><span class="label">Description</span><textarea class="input" name="description" rows="2" placeholder="Onsite work, editing, client call…"></textarea></label>
        <label class="check-row"><input type="checkbox" name="billable" value="1" checked> Available for client billing</label>
        <button class="btn btn-primary">Record work</button>
      </form>
    </article>
    <?php endif; ?>
    <article class="card workforce-card">
      <div class="card-head"><h3 class="card-title"><?= $running ? 'Running timer' : 'Clock in' ?></h3></div>
      <?php if ($running): ?>
        <div class="workforce-running">
          <strong><?= $h($running['project_name'] ?: 'General time') ?></strong>
          <?php if ($manageAll && ($running['client_name'] || $running['invoice_number'])): ?>
            <span><?= $h($running['client_name'] ?: '') ?><?= $running['invoice_number'] ? ' · ' . $h($invoiceLabel($running)) : '' ?></span>
          <?php endif; ?>
          <p><?= $h($running['description']) ?></p>
          <small>Started <?= $h($displayTime($running['start_time'])) ?></small>
        </div>
        <div class="workforce-actions">
          <form method="post" action="/?page=workforce/action">
            <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
            <input type="hidden" name="action" value="clock-out">
            <input type="hidden" name="entry_id" value="<?= $h($running['id']) ?>">
            <input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>">
            <button class="btn btn-primary">Clock out</button>
          </form>
          <?php if ($running['open_break_id']): ?>
            <form method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="break-end"><input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>"><input type="hidden" name="break_id" value="<?= $h($running['open_break_id']) ?>"><button class="btn">End break</button></form>
          <?php else: ?>
            <form method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="break-start"><input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>"><input type="hidden" name="entry_id" value="<?= $h($running['id']) ?>"><button class="btn">Start break</button></form>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <form class="workforce-form" method="post" action="/?page=workforce/action" data-workforce-entry-form>
          <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
          <input type="hidden" name="action" value="clock-in">
          <input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>">
          <?php if ($manageAll): ?>
            <div class="workforce-context-grid">
              <label class="field workforce-combobox" data-workforce-client-combobox><span class="label">Client <small>optional</small></span><input class="input" type="search" autocomplete="off" placeholder="Type to search clients" data-workforce-client-search aria-autocomplete="list" aria-expanded="false"><input type="hidden" name="client_id" data-workforce-client><span class="workforce-combobox__results" data-workforce-client-results role="listbox" hidden></span></label>
              <label class="field"><span class="label">Project</span><select class="input" name="project_id" data-workforce-project><option value="">No project</option><?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>" data-client-id="<?= (int)($project['client_id'] ?? 0) ?>" data-client-name="<?= $h($project['client_name'] ?? '') ?>"><?= $h($project['name']) ?><?= !empty($project['client_name']) ? ' · ' . $h($project['client_name']) : '' ?></option><?php endforeach; ?></select></label>
              <label class="field"><span class="label">Invoice <small>optional</small></span><select class="input" name="invoice_id" data-workforce-invoice><option value="">Link later</option><?php foreach ($invoices as $invoice): ?><option value="<?= (int)$invoice['id'] ?>" data-client-id="<?= (int)$invoice['client_id'] ?>" data-client-name="<?= $h($invoice['client_name'] ?? '') ?>" data-project-id="<?= (int)($invoice['project_id'] ?? 0) ?>" data-job-id="<?= (int)($invoice['job_id'] ?? 0) ?>"><?= $h(($invoice['client_name'] ?? '') . ' · ' . $invoiceLabel($invoice)) ?></option><?php endforeach; ?></select></label>
            </div>
          <?php else: ?>
            <label class="field"><span class="label">Assigned project</span><select class="input" name="project_id"><option value="">No project</option><?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>"><?= $h($project['name']) ?></option><?php endforeach; ?></select></label>
          <?php endif; ?>
          <div class="workforce-context-grid"><label class="field"><span class="label">Job</span><select class="input" name="job_id"><option value="">No Job</option><?php foreach($jobs as $job):?><option value="<?=(int)$job['id']?>" data-client-id="<?=(int)$job['client_id']?>" data-client-name="<?=$h($job['client_name'])?>" data-project-id="<?=(int)($job['project_id']??0)?>"><?=$h($job['job_code'].' · '.$job['client_name'])?></option><?php endforeach;?></select></label><label class="field"><span class="label">Work Type</span><select class="input" name="work_type_id"><option value="">Unclassified</option><?php foreach($workTypes as $type):?><option value="<?=(int)$type['id']?>"><?=$h($type['name'])?></option><?php endforeach;?></select></label><label class="field"><span class="label">Accepted assignment</span><select class="input" name="work_assignment_id"><option value="">No assignment</option><?php foreach($assignments as $assignment):?><option value="<?=(int)$assignment['id']?>" data-job-id="<?=(int)$assignment['job_id']?>" data-work-type-id="<?=(int)$assignment['work_type_id']?>"><?=$h($assignment['job_code'].' · '.$assignment['name'])?></option><?php endforeach;?></select></label></div>
          <label class="field"><span class="label">Description</span><textarea class="input" name="description" rows="3" placeholder="What are you working on?"></textarea></label>
          <?php if ($manageAll): ?><div class="workforce-checks"><label><input type="checkbox" name="billable" value="1" checked> Billable</label><label><input type="checkbox" name="is_payable" value="1" <?= $defaultPayable ? 'checked' : '' ?>> Payable</label></div><?php endif; ?>
          <button class="btn btn-primary">Clock in</button>
        </form>
      <?php endif; ?>
    </article>

    <article class="card workforce-card">
      <div class="card-head"><h3 class="card-title">Manual entry</h3></div>
      <form class="workforce-form" method="post" action="/?page=workforce/action" data-workforce-entry-form>
        <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
        <input type="hidden" name="action" value="manual-create">
        <input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>">
        <?php if ($manageAll): ?>
          <div class="workforce-context-grid">
            <label class="field workforce-combobox" data-workforce-client-combobox><span class="label">Client <small>optional</small></span><input class="input" type="search" autocomplete="off" placeholder="Type to search clients" data-workforce-client-search aria-autocomplete="list" aria-expanded="false"><input type="hidden" name="client_id" data-workforce-client><span class="workforce-combobox__results" data-workforce-client-results role="listbox" hidden></span></label>
            <label class="field"><span class="label">Project</span><select class="input" name="project_id" data-workforce-project><option value="">No project</option><?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>" data-client-id="<?= (int)($project['client_id'] ?? 0) ?>" data-client-name="<?= $h($project['client_name'] ?? '') ?>"><?= $h($project['name']) ?><?= !empty($project['client_name']) ? ' · ' . $h($project['client_name']) : '' ?></option><?php endforeach; ?></select></label>
            <label class="field"><span class="label">Invoice <small>optional</small></span><select class="input" name="invoice_id" data-workforce-invoice><option value="">Link later</option><?php foreach ($invoices as $invoice): ?><option value="<?= (int)$invoice['id'] ?>" data-client-id="<?= (int)$invoice['client_id'] ?>" data-client-name="<?= $h($invoice['client_name'] ?? '') ?>" data-project-id="<?= (int)($invoice['project_id'] ?? 0) ?>" data-job-id="<?= (int)($invoice['job_id'] ?? 0) ?>"><?= $h(($invoice['client_name'] ?? '') . ' · ' . $invoiceLabel($invoice)) ?></option><?php endforeach; ?></select></label>
          </div>
        <?php else: ?>
          <label class="field"><span class="label">Assigned project</span><select class="input" name="project_id"><option value="">No project</option><?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>"><?= $h($project['name']) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <div class="workforce-context-grid"><label class="field"><span class="label">Job</span><select class="input" name="job_id"><option value="">No Job</option><?php foreach($jobs as $job):?><option value="<?=(int)$job['id']?>" data-client-id="<?=(int)$job['client_id']?>" data-client-name="<?=$h($job['client_name'])?>" data-project-id="<?=(int)($job['project_id']??0)?>"><?=$h($job['job_code'].' · '.$job['client_name'])?></option><?php endforeach;?></select></label><label class="field"><span class="label">Work Type</span><select class="input" name="work_type_id"><option value="">Unclassified</option><?php foreach($workTypes as $type):?><option value="<?=(int)$type['id']?>"><?=$h($type['name'])?></option><?php endforeach;?></select></label><label class="field"><span class="label">Accepted assignment</span><select class="input" name="work_assignment_id"><option value="">No assignment</option><?php foreach($assignments as $assignment):?><option value="<?=(int)$assignment['id']?>" data-job-id="<?=(int)$assignment['job_id']?>" data-work-type-id="<?=(int)$assignment['work_type_id']?>"><?=$h($assignment['job_code'].' · '.$assignment['name'])?></option><?php endforeach;?></select></label></div>
        <div class="workforce-context-grid workforce-context-grid--time">
          <label class="field"><span class="label">Start</span><input class="input" type="datetime-local" name="start_time" required></label>
          <label class="field"><span class="label">End</span><input class="input" type="datetime-local" name="end_time" required></label>
        </div>
        <label class="field"><span class="label">Description</span><textarea class="input" name="description" rows="2" placeholder="Optional for manager entries"></textarea></label>
        <?php if ($manageAll): ?><div class="workforce-checks"><label><input type="checkbox" name="billable" value="1" checked> Billable</label><label><input type="checkbox" name="is_payable" value="1" <?= $defaultPayable ? 'checked' : '' ?>> Payable</label></div><?php endif; ?>
        <button class="btn btn-primary">Save time entry</button>
      </form>
    </article>
  </div>

  <article class="card workforce-card workforce-card--table">
    <div class="card-head"><div><h3 class="card-title">Time entries</h3><p class="muted text-sm mb-0">Client and invoice details are visible only to timekeeping managers.</p></div></div>
    <div class="pa-table-wrap">
      <table class="pa-table workforce-table">
        <thead><tr><th>Date</th><?php if ($manageAll): ?><th>Client / Invoice</th><?php endif; ?><th>Job / work</th><th>Duration</th><th>Status</th><th>Description</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($entries as $entry): ?>
          <tr>
            <td><?= $h($displayTime($entry['start_time'])) ?></td>
            <?php if ($manageAll): ?><td><strong><?= $h($entry['client_name'] ?: 'Internal / unassigned') ?></strong><?php if ($entry['invoice_number']): ?><small><?= $h($invoiceLabel($entry)) ?></small><?php endif; ?></td><?php endif; ?>
            <td><strong><?= $h($entry['job_code'] ?: ($entry['project_name'] ?: 'No Job')) ?></strong><?php if($entry['work_type_name']):?><small><?=$h($entry['work_type_name'])?></small><?php endif;?></td>
            <td><?= number_format(((int)$entry['duration_seconds']) / 3600, 2) ?> h</td>
            <td><span class="status-pill status-pill--<?= $h($entry['status']) ?>"><?= $h(ucfirst((string)$entry['status'])) ?></span><?php if ($entry['rejection_reason']): ?><small class="workforce-reason"><?= $h($entry['rejection_reason']) ?></small><?php endif; ?></td>
            <td><?= $h($entry['description']) ?></td>
            <td class="text-right">
              <?php
                $projectionBilled = !empty($entry['billing_projection_billed']) || !empty($entry['billing_invoice_item_id']);
                $canEditEntry = !$projectionBilled && (
                    in_array((string)$entry['status'], ['review','rejected'], true)
                    || ((string)$entry['status'] === 'approved' && !empty($entry['owner_self_confirmed']))
                );
              ?>
              <?php if ($canEditEntry): ?>
                <details class="workforce-entry-edit"><summary class="btn btn-sm">Edit</summary>
                  <form class="workforce-form" method="post" action="/?page=workforce/action" data-workforce-entry-form>
                    <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>"><input type="hidden" name="work_assignment_id" value="<?= (int)($entry['work_assignment_id'] ?? 0) ?>" data-job-id="<?= (int)($entry['job_id'] ?? 0) ?>">
                    <?php if ($manageAll): ?>
                      <div class="workforce-context-grid">
                        <label class="field workforce-combobox" data-workforce-client-combobox><span class="label">Client <small>optional</small></span><input class="input" type="search" value="<?= $h($entry['client_name'] ?? '') ?>" data-selected-name="<?= $h($entry['client_name'] ?? '') ?>" autocomplete="off" placeholder="Type to search clients" data-workforce-client-search aria-autocomplete="list" aria-expanded="false"><input type="hidden" name="client_id" value="<?= (int)($entry['client_id'] ?? 0) ?>" data-workforce-client><span class="workforce-combobox__results" data-workforce-client-results role="listbox" hidden></span></label>
                        <label class="field"><span class="label">Project</span><select class="input" name="project_id" data-workforce-project><option value="">No project</option><?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>" data-client-id="<?= (int)($project['client_id'] ?? 0) ?>" data-client-name="<?= $h($project['client_name'] ?? '') ?>" <?= (int)$entry['project_id'] === (int)$project['id'] ? 'selected' : '' ?>><?= $h($project['name']) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span class="label">Invoice <small>optional</small></span><select class="input" name="invoice_id" data-workforce-invoice><option value="">Link later</option><?php foreach ($invoices as $invoice): ?><option value="<?= (int)$invoice['id'] ?>" data-client-id="<?= (int)$invoice['client_id'] ?>" data-client-name="<?= $h($invoice['client_name'] ?? '') ?>" data-project-id="<?= (int)($invoice['project_id'] ?? 0) ?>" data-job-id="<?= (int)($invoice['job_id'] ?? 0) ?>" <?= (int)$entry['invoice_id'] === (int)$invoice['id'] ? 'selected' : '' ?>><?= $h(($invoice['client_name'] ?? '') . ' · ' . $invoiceLabel($invoice)) ?></option><?php endforeach; ?></select></label>
                      </div>
                    <?php else: ?>
                      <label class="field"><span class="label">Assigned project</span><select class="input" name="project_id"><option value="">No project</option><?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>" <?= (int)$entry['project_id'] === (int)$project['id'] ? 'selected' : '' ?>><?= $h($project['name']) ?></option><?php endforeach; ?></select></label>
                    <?php endif; ?>
                    <div class="workforce-context-grid">
                      <label class="field"><span class="label">Job</span><select class="input" name="job_id"><option value="">No Job</option><?php foreach ($jobs as $job): ?><option value="<?= (int)$job['id'] ?>" data-client-id="<?= (int)$job['client_id'] ?>" data-client-name="<?= $h($job['client_name']) ?>" data-project-id="<?= (int)($job['project_id'] ?? 0) ?>" <?= (int)$entry['job_id'] === (int)$job['id'] ? 'selected' : '' ?>><?= $h($job['job_code'].' · '.$job['client_name']) ?></option><?php endforeach; ?></select></label>
                      <label class="field"><span class="label">Work Type</span><select class="input" name="work_type_id"><option value="">Unclassified</option><?php foreach ($workTypes as $type): ?><option value="<?= (int)$type['id'] ?>" <?= (int)$entry['work_type_id'] === (int)$type['id'] ? 'selected' : '' ?>><?= $h($type['name']) ?></option><?php endforeach; ?></select></label>
                    </div>
                    <div class="workforce-context-grid workforce-context-grid--time"><label class="field"><span class="label">Start</span><input class="input" type="datetime-local" name="start_time" value="<?= $h($inputTime($entry['start_time'])) ?>" required></label><label class="field"><span class="label">End</span><input class="input" type="datetime-local" name="end_time" value="<?= $h($inputTime($entry['end_time'])) ?>" required></label></div>
                    <label class="field"><span class="label">Description</span><textarea class="input" name="description" rows="2"><?= $h($entry['description']) ?></textarea></label>
                    <?php if ($manageAll): ?><div class="workforce-checks"><label><input type="checkbox" name="billable" value="1" <?= $entry['billable'] ? 'checked' : '' ?>> Available for client billing</label><label><input type="checkbox" name="is_payable" value="1" <?= $entry['is_payable'] ? 'checked' : '' ?>> Payable</label></div><?php endif; ?>
                    <button class="btn btn-primary btn-sm">Save changes</button>
                  </form>
                </details>
              <?php endif; ?>
              <?php if ($manageAll && (string)$entry['status'] === 'approved' && !empty($entry['billable']) && !$projectionBilled && $invoices): ?>
                <details class="workforce-entry-edit"><summary class="btn btn-sm btn-primary">Add to invoice</summary>
                  <form class="workforce-form" method="post" action="/?page=workforce/action" data-workforce-entry-form>
                    <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="link-invoice"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>">
                    <label class="field"><span class="label">Invoice</span><select class="input" name="invoice_id" data-workforce-invoice required><option value="">Choose an invoice</option><?php foreach ($invoices as $invoice): ?><option value="<?= (int)$invoice['id'] ?>" data-client-id="<?= (int)$invoice['client_id'] ?>" data-client-name="<?= $h($invoice['client_name'] ?? '') ?>" data-project-id="<?= (int)($invoice['project_id'] ?? 0) ?>" data-job-id="<?= (int)($invoice['job_id'] ?? 0) ?>"><?= $h(($invoice['client_name'] ?? '') . ' · ' . $invoiceLabel($invoice)) ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span class="label">Hourly billing rate <small>only needed when PA cannot infer it</small></span><input class="input" type="number" name="billing_rate" min="0.01" step="0.01" value="<?= (float)($entry['billing_rate'] ?? 0) > 0 ? $h((string)$entry['billing_rate']) : '' ?>" placeholder="Use saved or invoice rate"></label>
                    <p class="muted text-sm">PA will add a visible tracked-time line and update this entry's client, Project, Job, and invoice automatically.</p>
                    <button class="btn btn-primary btn-sm">Add time to invoice</button>
                  </form>
                </details>
              <?php elseif ($projectionBilled): ?><span class="status-pill status-pill--approved">Invoiced</span><?php endif; ?>
              <?php if (in_array($entry['status'], ['review', 'rejected'], true)): ?>
                <form method="post" action="/?page=workforce/action" onsubmit="return confirm('Cancel this time entry?');"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="entry_id" value="<?= $h($entry['id']) ?>"><input type="hidden" name="entry_user_id" value="<?= $selectedUserId ?>"><button class="btn btn-sm">Cancel</button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$entries): ?><tr><td colspan="<?= $manageAll ? 7 : 6 ?>" class="workforce-empty">No time entries yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>

<script src="<?= $h(asset_url('/assets/js/workforce.js')) ?>"></script>
