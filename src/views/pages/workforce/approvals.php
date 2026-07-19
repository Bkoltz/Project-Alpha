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
$reviewerRole = acl_user_role($pdo, $userId);
$canManageCorrections = in_array($reviewerRole, ['admin', 'owner'], true)
    || user_can($pdo, $userId, 'workforce.corrections.manage', 0);
$canResolveCorrectionBilling = $canManageCorrections
    && user_can($pdo, $userId, 'invoices.edit', 0);
$tableExists = static function (string $table) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
};
$correctionRequests = [];
if ($tableExists('time_correction_requests')) {
    $correctionStmt = $pdo->query(
        "SELECT r.id correction_request_id,r.status correction_status,r.reason,r.created_at correction_created_at,
                r.original_revision,r.applied_revision,r.proposed_snapshot,e.worker_amount_delta,e.billing_amount_delta,
                e.currency effect_currency,e.statement_action,e.billing_action,t.*,
                COALESCE(NULLIF(TRIM(CONCAT(ep.first_name,' ',ep.last_name)),''),u.username,u.email) employee_name,
                p.name project_name,c.name client_name
         FROM time_correction_requests r
         JOIN work_time_entries t ON t.id=r.time_entry_id
         JOIN users u ON u.id=t.user_id
         LEFT JOIN employee_profiles ep ON ep.user_id=t.user_id
         LEFT JOIN projects p ON p.id=t.project_id
         LEFT JOIN clients c ON c.id=t.client_id
         LEFT JOIN time_correction_effects e ON e.correction_request_id=r.id
         ORDER BY CASE r.status WHEN 'pending' THEN 0 ELSE 1 END,r.created_at DESC LIMIT 100"
    );
    while ($correction = $correctionStmt->fetch(PDO::FETCH_ASSOC)) {
        if ($approvalPolicy->canReviewRecord($userId, $correction, 'history')) {
            $correctionRequests[] = $correction;
        }
    }
}
$openPayPeriods = $canManageCorrections
    ? $pdo->query("SELECT id,period_start,period_end FROM pay_periods WHERE status='open' ORDER BY period_start")->fetchAll(PDO::FETCH_ASSOC)
    : [];
$correctionDraftInvoices = [];
if ($canResolveCorrectionBilling) {
    foreach ($pdo->query("SELECT i.id,i.doc_number invoice_number,i.invoice_type,c.name client_name FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.status='draft' AND i.finalized_at IS NULL ORDER BY c.name,i.created_at DESC")->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
        if (can_access_record($pdo, 'invoices', (int)$invoice['id'], $userId)) $correctionDraftInvoices[] = $invoice;
    }
}
$visibleEntries = array_merge($queue, $approved);
$billingExceptions = array_values(array_filter($visibleEntries, static fn(array $entry): bool =>
    empty($entry['work_type_id'])
    || (!empty($entry['billable']) && (empty($entry['job_id']) || empty($entry['client_id'])))
    || in_array((string)($entry['billing_state'] ?? ''), ['rate_needed'], true)
));
$correctionRevisions = $correctionRequests ?: array_values(array_filter($approved, static fn(array $entry): bool => (int)($entry['revision'] ?? 1) > 1));
$payExceptions = array_values(array_filter($visibleEntries, static fn(array $entry): bool =>
    in_array((string)($entry['compensation_state'] ?? ''), ['needs_setup', 'provisional'], true)
));
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

<section class="workforce-page" data-workforce-page>
  <div class="workforce-head"><div><p class="workforce-eyebrow">Workforce</p><h2>Work Review</h2><p class="workforce-subtitle">Resolve submitted time, missing billing context, correction revisions, and pay setup exceptions from one queue.</p></div><div class="workforce-head__actions"><a class="btn" href="/?page=workforce/overview">Overview</a><a class="btn" href="/?page=workforce/time">Time</a></div></div>
  <?php if (!empty($_GET['success'])): ?><div class="alert alert-success"><?= $h($_GET['success']) ?></div><?php endif; ?>
  <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= $h($_GET['error']) ?></div><?php endif; ?>

  <div class="workforce-kpis">
    <article class="workforce-kpi"><span>Awaiting review</span><strong><?= number_format(count($queue)) ?></strong><small>Submitted entries</small></article>
    <article class="workforce-kpi"><span>Recently approved</span><strong><?= number_format(count($approved)) ?></strong><small>Latest 50 entries</small></article>
    <article class="workforce-kpi"><span>Timezone</span><strong style="font-size:18px"><?= $h($timezone) ?></strong><small>PA System Settings</small></article>
  </div>

  <div class="workforce-tabs" data-workforce-tabs>
    <div class="workforce-tab-list" role="tablist" aria-label="Work Review queues">
      <button class="workforce-tab is-active" type="button" role="tab" aria-selected="true" data-workforce-tab="time-review">Time review <span class="workforce-tab-count"><?= count($queue) ?></span></button>
      <button class="workforce-tab" type="button" role="tab" aria-selected="false" data-workforce-tab="billing-context">Billing context <span class="workforce-tab-count"><?= count($billingExceptions) ?></span></button>
      <button class="workforce-tab" type="button" role="tab" aria-selected="false" data-workforce-tab="corrections">Corrections <span class="workforce-tab-count"><?= count($correctionRevisions) ?></span></button>
      <button class="workforce-tab" type="button" role="tab" aria-selected="false" data-workforce-tab="pay-exceptions">Pay exceptions <span class="workforce-tab-count"><?= count($payExceptions) ?></span></button>
    </div>

    <div data-workforce-tab-panel="time-review" role="tabpanel">
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
    </div>

    <div data-workforce-tab-panel="billing-context" role="tabpanel" hidden>
      <article class="card workforce-card workforce-card--table">
        <div class="card-head"><div><h3 class="card-title">Missing Job or billing context</h3><p class="muted text-sm mb-0">A Job is required before client-billable time can reach an invoice.</p></div></div>
        <div class="pa-table-wrap"><table class="pa-table workforce-table"><thead><tr><th>Worker</th><th>Time</th><th>Missing context</th><th>Next step</th></tr></thead><tbody>
          <?php foreach ($billingExceptions as $entry): ?><tr><td><strong><?= $h($entry['employee_name']) ?></strong></td><td><?= $h($displayTime($entry['start_time'])) ?><small><?= number_format(((int)$entry['duration_seconds']) / 3600, 2) ?> h</small></td><td><?= empty($entry['work_type_id']) ? 'Work Activity' : (empty($entry['job_id']) ? 'Job' : (empty($entry['client_id']) ? 'Client' : 'Billing rate')) ?></td><td><a class="btn btn-sm" href="/?page=workforce/time&amp;user=<?= (int)$entry['user_id'] ?>">Open time entry</a></td></tr><?php endforeach; ?>
          <?php if (!$billingExceptions): ?><tr><td colspan="4" class="workforce-empty">No billing-context exceptions in your review scope.</td></tr><?php endif; ?>
        </tbody></table></div>
      </article>
    </div>

    <div data-workforce-tab-panel="corrections" role="tabpanel" hidden>
      <article class="card workforce-card workforce-card--table">
        <div class="card-head"><div><h3 class="card-title">Correction requests & history</h3><p class="muted text-sm mb-0">Corrections create revisions; the original time and earning snapshots remain in the audit trail.</p></div></div>
        <div class="pa-table-wrap"><table class="pa-table workforce-table"><thead><tr><th>Worker</th><th>Work date</th><th>Status / revision</th><th>Reason</th><th>Context</th><?php if ($canManageCorrections): ?><th>Actions</th><?php endif; ?></tr></thead><tbody>
          <?php foreach ($correctionRevisions as $entry): ?><?php $proposal = !empty($entry['proposed_snapshot']) ? (json_decode((string)$entry['proposed_snapshot'], true) ?: []) : []; ?><tr><td><strong><?= $h($entry['employee_name']) ?></strong></td><td><?= $h($displayTime($entry['start_time'])) ?><?php if ($proposal): ?><small>Proposed: <?= $h($displayTime($proposal['start_time'] ?? $entry['start_time'])) ?> · <?= number_format(((int)($proposal['duration_seconds'] ?? $entry['duration_seconds'])) / 3600, 2) ?> h</small><?php endif; ?></td><td><?php if (isset($entry['correction_status'])): ?><span class="status-pill status-pill--<?= $h($entry['correction_status']) ?>"><?= $h(ucfirst((string)$entry['correction_status'])) ?></span><small>Original revision <?= (int)$entry['original_revision'] ?></small><?php else: ?>Revision <?= (int)$entry['revision'] ?><?php endif; ?></td><td><?= $h($entry['reason'] ?? 'Admin correction') ?></td><td><?= $h($entry['project_name'] ?: ($entry['client_name'] ?: 'Unclassified')) ?><?php if (isset($entry['worker_amount_delta']) && $entry['worker_amount_delta'] !== null): ?><small>Worker delta <?= $h(($entry['effect_currency'] ?: 'USD') . ' ' . number_format((float)$entry['worker_amount_delta'], 2)) ?></small><?php endif; ?></td>
            <?php if ($canManageCorrections): ?><td class="text-right">
              <?php if (($entry['correction_status'] ?? '') === 'pending'): ?><details class="workforce-entry-edit"><summary class="btn btn-sm btn-primary">Review</summary><div class="workforce-review-dialog">
                <form class="workforce-form" method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="correction-approve"><input type="hidden" name="request_id" value="<?= $h($entry['correction_request_id']) ?>"><label class="field"><span class="label">Next open pay period <small>used only for prior-period adjustments</small></span><select class="input" name="next_open_pay_period_id"><option value="">Let PA resolve automatically</option><?php foreach ($openPayPeriods as $period): ?><option value="<?= (int)$period['id'] ?>"><?= $h($period['period_start'] . ' – ' . $period['period_end']) ?></option><?php endforeach; ?></select></label><label class="field"><span class="label">Manual worker delta <small>optional override</small></span><input class="input" type="number" name="manual_worker_delta" step="0.01" placeholder="Use calculated delta"></label><label class="field"><span class="label">Approval notes <small>optional</small></span><textarea class="input" name="notes" rows="2"></textarea></label><button class="btn btn-primary" type="submit">Approve correction</button></form>
                <form class="workforce-form workforce-form--danger" method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="correction-reject"><input type="hidden" name="request_id" value="<?= $h($entry['correction_request_id']) ?>"><label class="field"><span class="label">Rejection reason</span><textarea class="input" name="notes" rows="2" required></textarea></label><button class="btn btn-danger" type="submit">Reject correction</button></form>
              </div></details><?php endif; ?>
              <?php if ($canResolveCorrectionBilling && ($entry['billing_action'] ?? '') === 'admin_review'): ?><details class="workforce-entry-edit"><summary class="btn btn-sm">Resolve billing</summary><form class="workforce-form" method="post" action="/?page=workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="correction-billing-resolve"><input type="hidden" name="request_id" value="<?= $h($entry['correction_request_id']) ?>"><label class="field"><span class="label">Decision</span><select class="input" name="decision" required><option value="invoice_adjustment">Create invoice charge or credit</option><option value="move_to_draft">Move to another draft invoice</option><option value="absorb">Absorb the difference</option></select></label><label class="field"><span class="label">Target draft invoice <small>for move to draft</small></span><select class="input" name="target_draft_invoice_id"><option value="">Choose when needed</option><?php foreach ($correctionDraftInvoices as $invoice): ?><option value="<?= (int)$invoice['id'] ?>"><?= $h($invoice['client_name'] . ' · ' . $invoiceLabel($invoice)) ?></option><?php endforeach; ?></select></label><label class="field"><span class="label">Reason</span><textarea class="input" name="reason" rows="2" required></textarea></label><button class="btn btn-primary" type="submit">Resolve billing impact</button></form></details><?php endif; ?>
            </td><?php endif; ?>
          </tr><?php endforeach; ?>
          <?php if (!$correctionRevisions): ?><tr><td colspan="<?= $canManageCorrections ? 6 : 5 ?>" class="workforce-empty">No correction requests or revisions in the recent review history.</td></tr><?php endif; ?>
        </tbody></table></div>
      </article>
    </div>

    <div data-workforce-tab-panel="pay-exceptions" role="tabpanel" hidden>
      <article class="card workforce-card workforce-card--table">
        <div class="card-head"><div><h3 class="card-title">Pay setup exceptions</h3><p class="muted text-sm mb-0">Client billing stays separate. These entries need worker compensation setup or review.</p></div></div>
        <div class="pa-table-wrap"><table class="pa-table workforce-table"><thead><tr><th>Worker</th><th>Work date</th><th>Status</th><th>Next step</th></tr></thead><tbody>
          <?php foreach ($payExceptions as $entry): ?><tr><td><strong><?= $h($entry['employee_name']) ?></strong></td><td><?= $h($displayTime($entry['start_time'])) ?></td><td><?= $h(ucwords(str_replace('_', ' ', (string)$entry['compensation_state']))) ?></td><td><a class="btn btn-sm" href="/?page=settings&amp;tab=work-types">Review compensation rules</a></td></tr><?php endforeach; ?>
          <?php if (!$payExceptions): ?><tr><td colspan="4" class="workforce-empty">No pay exceptions in your review scope.</td></tr><?php endif; ?>
        </tbody></table></div>
      </article>
    </div>
  </div>
</section>
