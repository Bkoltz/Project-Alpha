<?php

require_once __DIR__ . '/../../../utils/external_ops.php';

$externalOpsConfig = pa_external_ops_delivery_config($pdo);
$externalOpsLabel = (string)$externalOpsConfig['label'];
$externalOpsApplicationKey = (string)$externalOpsConfig['application_key'];
$externalOpsUsers = [];
$externalOpsUnits = [];
$externalOpsScopes = [];
$externalOpsProjects = [];
$externalOpsPlanningUsers = [];
$externalOpsOperations = [];
$externalOpsTasks = [];
$externalOpsStatus = [
    'pending' => 0,
    'failed' => 0,
    'last_delivered_at' => null,
    'last_error' => null,
];

try {
    $statement = $pdo->prepare(
        'SELECT u.id,u.email,u.username,u.role,u.is_disabled,u.deleted_at,wp.display_name,
                ae.id AS entitlement_id,ae.enabled,ae.role_key,ae.updated_at
         FROM users u
         LEFT JOIN worker_profiles wp ON wp.user_id = u.id
         LEFT JOIN application_entitlements ae ON ae.user_id = u.id AND ae.application_key = ?
         ORDER BY COALESCE(wp.display_name,u.username,u.email),u.id'
    );
    $statement->execute([$externalOpsApplicationKey]);
    $externalOpsUsers = $statement->fetchAll(PDO::FETCH_ASSOC);

    $externalOpsUnits = $pdo->query('SELECT id,name,code,is_active FROM business_units ORDER BY is_active DESC,name,id')->fetchAll(PDO::FETCH_ASSOC);
    $scopeStatement = $pdo->prepare(
        'SELECT aebu.entitlement_id,aebu.business_unit_id
         FROM application_entitlement_business_units aebu
         JOIN application_entitlements ae ON ae.id = aebu.entitlement_id
         WHERE ae.application_key = ? ORDER BY aebu.entitlement_id,aebu.business_unit_id'
    );
    $scopeStatement->execute([$externalOpsApplicationKey]);
    foreach ($scopeStatement->fetchAll(PDO::FETCH_ASSOC) as $scope) {
        $externalOpsScopes[(int)$scope['entitlement_id']][] = (int)$scope['business_unit_id'];
    }

    $externalOpsProjects = $pdo->query("SELECT id,name,status FROM projects WHERE status <> 'cancelled' ORDER BY name,id")->fetchAll(PDO::FETCH_ASSOC);
    $externalOpsPlanningUsers = $pdo->query(
        'SELECT u.id,u.email,COALESCE(wp.display_name,u.username,u.email) AS display_name
         FROM users u LEFT JOIN worker_profiles wp ON wp.user_id=u.id
         WHERE u.is_disabled=0 AND u.deleted_at IS NULL ORDER BY display_name,u.id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $externalOpsOperations = $pdo->query(
        'SELECT o.*,p.name AS project_name,bu.name AS business_unit_name,
                GROUP_CONCAT(oa.user_id ORDER BY oa.user_id) AS assigned_user_ids
         FROM operations o
         JOIN projects p ON p.id=o.project_id
         LEFT JOIN business_units bu ON bu.id=o.business_unit_id
         LEFT JOIN operation_assignments oa ON oa.operation_id=o.id
         GROUP BY o.id,p.name,bu.name
         ORDER BY COALESCE(o.scheduled_start_at,o.created_at) DESC,o.id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $externalOpsTasks = $pdo->query(
        'SELECT t.*,p.name AS project_name,o.title AS operation_title,
                COALESCE(wp.display_name,u.username,u.email) AS assignee_name
         FROM tasks t
         JOIN projects p ON p.id=t.project_id
         LEFT JOIN operations o ON o.id=t.operation_id
         LEFT JOIN users u ON u.id=t.assignee_user_id
         LEFT JOIN worker_profiles wp ON wp.user_id=u.id
         ORDER BY t.status IN ("completed","cancelled"),COALESCE(t.due_at,t.created_at),t.id'
    )->fetchAll(PDO::FETCH_ASSOC);

    $statusStatement = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN delivered_at IS NULL THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN delivered_at IS NULL AND attempts > 0 AND last_error IS NOT NULL THEN 1 ELSE 0 END) AS failed,
            MAX(delivered_at) AS last_delivered_at
         FROM integration_outbox WHERE integration_key = ?'
    );
    $statusStatement->execute([$externalOpsApplicationKey]);
    $statusRow = $statusStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $externalOpsStatus['pending'] = (int)($statusRow['pending'] ?? 0);
    $externalOpsStatus['failed'] = (int)($statusRow['failed'] ?? 0);
    $externalOpsStatus['last_delivered_at'] = $statusRow['last_delivered_at'] ?? null;
    $errorStatement = $pdo->prepare('SELECT last_error FROM integration_outbox WHERE integration_key = ? AND last_error IS NOT NULL ORDER BY id DESC LIMIT 1');
    $errorStatement->execute([$externalOpsApplicationKey]);
    $externalOpsStatus['last_error'] = $errorStatement->fetchColumn() ?: null;
} catch (Throwable $error) {
    echo '<div class="settings-alert settings-alert-danger">The external operations schema is not ready. Run the current database migration before configuring this module.</div>';
    return;
}

$configurationChecks = [
    'Webhook URL' => $externalOpsConfig['webhook_url'] !== '',
    'Cloudflare Access Client ID' => $externalOpsConfig['access_client_id'] !== '',
    'Cloudflare Access Client Secret' => $externalOpsConfig['access_client_secret'] !== '',
    'Webhook HMAC secret' => $externalOpsConfig['hmac_secret'] !== '',
];
$editOperationId = max(0, (int)($_GET['operation_id'] ?? 0));
$editTaskId = max(0, (int)($_GET['task_id'] ?? 0));
$editOperation = null;
$editTask = null;
foreach ($externalOpsOperations as $operation) {
    if ((int)$operation['id'] === $editOperationId) {
        $editOperation = $operation;
        break;
    }
}
foreach ($externalOpsTasks as $task) {
    if ((int)$task['id'] === $editTaskId) {
        $editTask = $task;
        break;
    }
}
$dateTimeLocal = static function ($value): string {
    if (!$value) return '';
    try {
        return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d\TH:i');
    } catch (Throwable $error) {
        return '';
    }
};
?>

<div class="settings-alert settings-alert-warning" role="note">
  <strong>Optional custom integration.</strong>
  This advanced module is disabled in standard Project Alpha installations. It is intended for operators who run a separate, authenticated operations dashboard and understand the synchronization model. Project Alpha remains the source of truth and is not placed behind that dashboard's access policy.
</div>

<div class="settings-card">
  <h3>Custom integration setup</h3>
  <p>The integration is stored only in Project Alpha's database. Cloudflare Access credentials and the shared HMAC secret are encrypted with PA's persisted application encryption key and are never displayed after saving.</p>
  <?php if (!empty($externalOpsConfig['credentials_unreadable'])): ?>
    <div class="settings-alert settings-alert-danger">The saved credentials cannot be decrypted. Verify PA's application encryption key, then enter all three credentials again.</div>
  <?php endif; ?>
  <form method="post" action="/?page=settings/external-ops-handler" class="settings-card">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="save-config">
    <div class="settings-form-grid">
      <label class="check-row" style="grid-column:1/-1">
        <input type="checkbox" name="enabled" value="1" <?php echo !empty($externalOpsConfig['enabled']) ? 'checked' : ''; ?>>
        Enable this custom integration
      </label>
      <label class="field"><span class="label">Settings label</span><input class="input" name="label" maxlength="100" required value="<?php echo htmlspecialchars($externalOpsLabel); ?>"><small>For example, LTDS Operations.</small></label>
      <div class="field"><span class="label">Application key</span><code><?php echo htmlspecialchars($externalOpsApplicationKey); ?></code><small>Fixed by the LTDS Operations synchronization contract.</small></div>
      <label class="field" style="grid-column:1/-1"><span class="label">Provisioning webhook URL</span><input class="input" type="url" name="webhook_url" maxlength="1000" value="<?php echo htmlspecialchars((string)$externalOpsConfig['webhook_url']); ?>" placeholder="https://ops.example.com/api/provisioning/events"></label>
      <label class="field"><span class="label">Cloudflare Access Client ID</span><input class="input" type="password" name="access_client_id" maxlength="500" autocomplete="new-password" placeholder="<?php echo !empty($externalOpsConfig['access_client_id']) ? 'Configured — leave blank to keep' : 'Enter service-token client ID'; ?>"></label>
      <label class="field"><span class="label">Cloudflare Access Client Secret</span><input class="input" type="password" name="access_client_secret" maxlength="1000" autocomplete="new-password" placeholder="<?php echo !empty($externalOpsConfig['access_client_secret']) ? 'Configured — leave blank to keep' : 'Enter service-token client secret'; ?>"></label>
      <label class="field" style="grid-column:1/-1"><span class="label">Webhook HMAC secret</span><input class="input" type="password" name="hmac_secret" minlength="32" maxlength="1000" autocomplete="new-password" placeholder="<?php echo !empty($externalOpsConfig['hmac_secret']) ? 'Configured — leave blank to keep' : 'Enter the same 32+ character secret configured in the receiver'; ?>"></label>
      <label class="field"><span class="label">Request timeout (seconds)</span><input class="input" type="number" name="timeout_seconds" min="2" max="60" value="<?php echo (int)$externalOpsConfig['timeout_seconds']; ?>"></label>
      <label class="field"><span class="label">Maximum delivery attempts</span><input class="input" type="number" name="max_attempts" min="1" max="100" value="<?php echo (int)$externalOpsConfig['max_attempts']; ?>"></label>
    </div>
    <button class="btn btn-primary" type="submit">Save custom integration</button>
  </form>
</div>

<?php if (empty($externalOpsConfig['enabled'])): ?>
  <div class="settings-alert settings-alert-warning">This module is currently off. Configure the receiver and credentials above, then enable it to reveal entitlements, operations, tasks, and delivery status.</div>
  <?php return; ?>
<?php endif; ?>

<div class="settings-card">
  <h3>Operations</h3>
  <p>These records are owned and edited in Project Alpha. The external dashboard receives a read-only projection.</p>
  <form method="post" action="/?page=settings/external-ops-handler" class="settings-card">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="save-operation">
    <input type="hidden" name="id" value="<?php echo (int)($editOperation['id'] ?? 0); ?>">
    <div class="settings-form-grid">
      <label class="field"><span class="label">Project</span><select class="input" name="project_id" required><option value="">Select project</option><?php foreach ($externalOpsProjects as $project): ?><option value="<?php echo (int)$project['id']; ?>" <?php echo (int)($editOperation['project_id'] ?? 0)===(int)$project['id']?'selected':''; ?>><?php echo htmlspecialchars((string)$project['name']); ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="label">Business unit</span><select class="input" name="business_unit_id"><option value="">No specific unit</option><?php foreach ($externalOpsUnits as $unit): if (empty($unit['is_active'])) continue; ?><option value="<?php echo (int)$unit['id']; ?>" <?php echo (int)($editOperation['business_unit_id'] ?? 0)===(int)$unit['id']?'selected':''; ?>><?php echo htmlspecialchars((string)$unit['name']); ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="label">Title</span><input class="input" name="title" maxlength="255" required value="<?php echo htmlspecialchars((string)($editOperation['title'] ?? '')); ?>"></label>
      <label class="field"><span class="label">Status</span><select class="input" name="status"><?php foreach (\App\Services\OperationsPlanningService::OPERATION_STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo ($editOperation['status'] ?? 'draft')===$status?'selected':''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$status))); ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="label">Scheduled start</span><input class="input" type="datetime-local" name="scheduled_start_at" value="<?php echo htmlspecialchars($dateTimeLocal($editOperation['scheduled_start_at'] ?? null)); ?>"></label>
      <label class="field"><span class="label">Scheduled end</span><input class="input" type="datetime-local" name="scheduled_end_at" value="<?php echo htmlspecialchars($dateTimeLocal($editOperation['scheduled_end_at'] ?? null)); ?>"></label>
      <label class="field"><span class="label">Location</span><input class="input" name="location" maxlength="500" value="<?php echo htmlspecialchars((string)($editOperation['location'] ?? '')); ?>"></label>
      <fieldset class="field" style="border:0;padding:0"><legend class="label">Assigned staff</legend><?php $assignedIds=array_map('intval',array_filter(explode(',',(string)($editOperation['assigned_user_ids']??'')))); foreach($externalOpsPlanningUsers as $planningUser): ?><label class="check-row"><input type="checkbox" name="assigned_user_ids[]" value="<?php echo (int)$planningUser['id']; ?>" <?php echo in_array((int)$planningUser['id'],$assignedIds,true)?'checked':''; ?>><?php echo htmlspecialchars((string)$planningUser['display_name']); ?></label><?php endforeach; ?></fieldset>
      <label class="field" style="grid-column:1/-1"><span class="label">Notes</span><textarea class="input" name="notes" rows="3"><?php echo htmlspecialchars((string)($editOperation['notes'] ?? '')); ?></textarea></label>
    </div>
    <button class="btn btn-primary" type="submit"><?php echo $editOperation ? 'Update operation' : 'Create operation'; ?></button>
    <?php if ($editOperation): ?><a class="btn" href="/?page=settings&amp;tab=external-ops">Cancel edit</a><?php endif; ?>
  </form>

  <div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Operation</th><th>Project</th><th>Schedule</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($externalOpsOperations as $operation): ?><tr><td><?php echo htmlspecialchars((string)$operation['title']); ?></td><td><?php echo htmlspecialchars((string)$operation['project_name']); ?></td><td><?php echo htmlspecialchars((string)($operation['scheduled_start_at'] ?: 'Unscheduled')); ?></td><td><?php echo htmlspecialchars(ucwords(str_replace('_',' ',(string)$operation['status']))); ?></td><td><a class="btn btn-sm" href="/?page=settings&amp;tab=external-ops&amp;operation_id=<?php echo (int)$operation['id']; ?>">Edit</a></td></tr><?php endforeach; ?>
  <?php if (!$externalOpsOperations): ?><tr><td colspan="5">No operations yet.</td></tr><?php endif; ?></tbody></table></div>
</div>

<div class="settings-card">
  <h3>Tasks</h3>
  <form method="post" action="/?page=settings/external-ops-handler" class="settings-card">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="save-task">
    <input type="hidden" name="id" value="<?php echo (int)($editTask['id'] ?? 0); ?>">
    <div class="settings-form-grid">
      <label class="field"><span class="label">Project</span><select class="input" name="project_id" required><option value="">Select project</option><?php foreach ($externalOpsProjects as $project): ?><option value="<?php echo (int)$project['id']; ?>" <?php echo (int)($editTask['project_id'] ?? 0)===(int)$project['id']?'selected':''; ?>><?php echo htmlspecialchars((string)$project['name']); ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="label">Operation</span><select class="input" name="operation_id"><option value="">No operation</option><?php foreach ($externalOpsOperations as $operation): ?><option value="<?php echo (int)$operation['id']; ?>" <?php echo (int)($editTask['operation_id'] ?? 0)===(int)$operation['id']?'selected':''; ?>><?php echo htmlspecialchars((string)$operation['project_name'].' · '.(string)$operation['title']); ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="label">Business unit</span><select class="input" name="business_unit_id"><option value="">No specific unit</option><?php foreach ($externalOpsUnits as $unit): if(empty($unit['is_active']))continue; ?><option value="<?php echo (int)$unit['id']; ?>" <?php echo (int)($editTask['business_unit_id'] ?? 0)===(int)$unit['id']?'selected':''; ?>><?php echo htmlspecialchars((string)$unit['name']); ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="label">Assignee</span><select class="input" name="assignee_user_id"><option value="">Unassigned</option><?php foreach($externalOpsPlanningUsers as $planningUser): ?><option value="<?php echo (int)$planningUser['id']; ?>" <?php echo (int)($editTask['assignee_user_id'] ?? 0)===(int)$planningUser['id']?'selected':''; ?>><?php echo htmlspecialchars((string)$planningUser['display_name']); ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="label">Title</span><input class="input" name="title" maxlength="255" required value="<?php echo htmlspecialchars((string)($editTask['title'] ?? '')); ?>"></label>
      <label class="field"><span class="label">Status</span><select class="input" name="status"><?php foreach (\App\Services\OperationsPlanningService::TASK_STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo ($editTask['status'] ?? 'todo')===$status?'selected':''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$status))); ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="label">Due</span><input class="input" type="datetime-local" name="due_at" value="<?php echo htmlspecialchars($dateTimeLocal($editTask['due_at'] ?? null)); ?>"></label>
      <label class="field" style="grid-column:1/-1"><span class="label">Notes</span><textarea class="input" name="notes" rows="3"><?php echo htmlspecialchars((string)($editTask['notes'] ?? '')); ?></textarea></label>
    </div>
    <button class="btn btn-primary" type="submit"><?php echo $editTask ? 'Update task' : 'Create task'; ?></button>
    <?php if ($editTask): ?><a class="btn" href="/?page=settings&amp;tab=external-ops">Cancel edit</a><?php endif; ?>
  </form>
  <div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Task</th><th>Project / operation</th><th>Assignee</th><th>Due</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($externalOpsTasks as $task): ?><tr><td><?php echo htmlspecialchars((string)$task['title']); ?></td><td><?php echo htmlspecialchars((string)$task['project_name'].(!empty($task['operation_title'])?' · '.(string)$task['operation_title']:'')); ?></td><td><?php echo htmlspecialchars((string)($task['assignee_name'] ?: 'Unassigned')); ?></td><td><?php echo htmlspecialchars((string)($task['due_at'] ?: 'No due time')); ?></td><td><?php echo htmlspecialchars(ucwords(str_replace('_',' ',(string)$task['status']))); ?></td><td><a class="btn btn-sm" href="/?page=settings&amp;tab=external-ops&amp;task_id=<?php echo (int)$task['id']; ?>">Edit</a></td></tr><?php endforeach; ?>
  <?php if (!$externalOpsTasks): ?><tr><td colspan="6">No tasks yet.</td></tr><?php endif; ?></tbody></table></div>
</div>

<div class="settings-card">
  <h3>Integration status</h3>
  <p><strong>Application key:</strong> <code><?php echo htmlspecialchars($externalOpsApplicationKey); ?></code></p>
  <div class="settings-form-grid">
    <div><span class="label">Pending events</span><strong><?php echo (int)$externalOpsStatus['pending']; ?></strong></div>
    <div><span class="label">Events with retry errors</span><strong><?php echo (int)$externalOpsStatus['failed']; ?></strong></div>
    <div><span class="label">Last delivered event</span><strong><?php echo htmlspecialchars((string)($externalOpsStatus['last_delivered_at'] ?: 'Never')); ?></strong></div>
  </div>
  <?php if ($externalOpsStatus['last_error']): ?>
    <div class="settings-alert settings-alert-danger" style="margin-top:12px">Latest delivery error: <?php echo htmlspecialchars((string)$externalOpsStatus['last_error']); ?></div>
  <?php endif; ?>
  <ul>
    <?php foreach ($configurationChecks as $label => $configured): ?>
      <li><?php echo $configured ? 'Configured' : 'Missing'; ?> — <?php echo htmlspecialchars($label); ?></li>
    <?php endforeach; ?>
  </ul>
  <form method="post" action="/?page=settings/external-ops-handler">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="send-now">
    <button class="btn" type="submit">Retry due events now</button>
  </form>
</div>

<div class="settings-card">
  <h3><?php echo htmlspecialchars($externalOpsLabel); ?> access</h3>
  <p>The checkbox is the explicit access ACL. The PA account role is authoritative: only <code>admin</code> becomes global <code>role-admin</code>; Owner and every other role become business-unit-scoped <code>role-operator</code>.</p>

  <?php foreach ($externalOpsUsers as $externalUser):
      $entitlementId = (int)($externalUser['entitlement_id'] ?? 0);
      $selectedUnits = $externalOpsScopes[$entitlementId] ?? [];
      $displayName = trim((string)($externalUser['display_name'] ?: $externalUser['username'] ?: $externalUser['email']));
      $userUnavailable = !empty($externalUser['is_disabled']) || !empty($externalUser['deleted_at']);
  ?>
    <form method="post" action="/?page=settings/external-ops-handler" class="settings-card" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="action" value="save-entitlement">
      <input type="hidden" name="user_id" value="<?php echo (int)$externalUser['id']; ?>">
      <h4 style="margin:0 0 4px"><?php echo htmlspecialchars($displayName); ?></h4>
      <p style="margin-top:0"><?php echo htmlspecialchars(strtolower((string)$externalUser['email'])); ?><?php echo $userUnavailable ? ' · PA account inactive' : ''; ?></p>
      <div class="settings-form-grid">
        <label class="check-row">
          <input type="checkbox" name="enabled" value="1" <?php echo !empty($externalUser['enabled']) ? 'checked' : ''; ?>>
          LTDS Operations access
        </label>
        <div class="field">
          <span class="label">Derived application role</span>
          <code><?php echo ($externalUser['role'] ?? '') === 'admin' ? 'role-admin' : 'role-operator'; ?></code>
        </div>
        <fieldset class="field" style="border:0;padding:0">
          <legend class="label">Business-unit scope</legend>
          <?php if (($externalUser['role'] ?? '') === 'admin'): ?>
            <span>Global (saved employee selections are retained for a later demotion)</span>
          <?php else: ?>
            <input type="hidden" name="business_unit_scope_present" value="1">
            <?php foreach ($externalOpsUnits as $unit): ?>
            <label class="check-row">
              <input type="checkbox" name="business_unit_ids[]" value="<?php echo (int)$unit['id']; ?>" <?php echo in_array((int)$unit['id'], $selectedUnits, true) ? 'checked' : ''; ?> <?php echo empty($unit['is_active']) ? 'disabled' : ''; ?>>
              <?php echo htmlspecialchars((string)$unit['name']); ?><?php echo !empty($unit['code']) ? ' (' . htmlspecialchars((string)$unit['code']) . ')' : ''; ?>
            </label>
            <?php endforeach; ?>
          <?php endif; ?>
        </fieldset>
      </div>
      <button class="btn btn-primary" type="submit">Save application access</button>
    </form>
  <?php endforeach; ?>
</div>
