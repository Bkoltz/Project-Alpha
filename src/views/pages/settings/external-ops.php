<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../utils/external_ops.php';

$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$config = pa_external_ops_delivery_config($pdo);
$label = (string)$config['label'];
$applicationKey = (string)$config['application_key'];
$grantAccessLabel = 'Grant ' . $label . ' access';
$revokeAccessLabel = 'Revoke ' . $label . ' access';
$search = trim((string)($_GET['access_search'] ?? ''));
$pageNumber = max(1, (int)($_GET['access_page'] ?? 1));
$pageSize = 20;
$offset = ($pageNumber - 1) * $pageSize;
$detailUserId = max(0, (int)($_GET['access_user_id'] ?? 0));
$accessCount = 0;
$accessUsers = [];
$eligibleUsers = [];
$accessDetail = null;
$projectSources = [];
$status = [];
$directoryError = false;

if (!empty($config['enabled'])) {
    try {
        $where = 'e.application_key=? AND e.enabled=1 AND e.manual_enabled=1';
        $params = [$applicationKey];
        if ($search !== '') {
            $where .= ' AND (wp.display_name LIKE ? OR tm.display_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term, $term);
        }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM application_entitlements e JOIN users u ON u.id=e.user_id LEFT JOIN worker_profiles wp ON wp.user_id=u.id LEFT JOIN team_members tm ON tm.user_id=u.id WHERE {$where}");
        $countStmt->execute($params);
        $accessCount = (int)$countStmt->fetchColumn();
        $accessStmt = $pdo->prepare("SELECT e.*,COALESCE(NULLIF(wp.display_name,''),NULLIF(tm.display_name,''),NULLIF(u.username,''),u.email) display_name,u.username,u.email,u.role,u.is_disabled,u.deleted_at,wp.status worker_status,ep.employment_status FROM application_entitlements e JOIN users u ON u.id=e.user_id LEFT JOIN worker_profiles wp ON wp.user_id=u.id LEFT JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN team_members tm ON tm.user_id=u.id WHERE {$where} ORDER BY display_name LIMIT {$pageSize} OFFSET {$offset}");
        $accessStmt->execute($params);
        $accessUsers = $accessStmt->fetchAll(PDO::FETCH_ASSOC);
        $eligibleStmt = $pdo->prepare("SELECT u.id,COALESCE(NULLIF(wp.display_name,''),NULLIF(tm.display_name,''),NULLIF(u.username,''),u.email) name,u.email,u.is_disabled,wp.status worker_status,ep.employment_status FROM users u LEFT JOIN worker_profiles wp ON wp.user_id=u.id LEFT JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN team_members tm ON tm.user_id=u.id WHERE u.deleted_at IS NULL AND COALESCE(wp.status,'active')<>'terminated' AND COALESCE(ep.employment_status,'active')<>'terminated' AND NOT EXISTS (SELECT 1 FROM application_entitlements selected WHERE selected.application_key=? AND selected.user_id=u.id AND selected.enabled=1 AND selected.manual_enabled=1) ORDER BY name LIMIT 250");
        $eligibleStmt->execute([$applicationKey]);
        $eligibleUsers = $eligibleStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($detailUserId > 0) {
            $detailStmt = $pdo->prepare("SELECT e.*,COALESCE(NULLIF(wp.display_name,''),NULLIF(tm.display_name,''),NULLIF(u.username,''),u.email) display_name,u.username,u.email,u.role,u.is_disabled,u.deleted_at,wp.status worker_status,ep.employment_status FROM application_entitlements e JOIN users u ON u.id=e.user_id LEFT JOIN worker_profiles wp ON wp.user_id=u.id LEFT JOIN employee_profiles ep ON ep.user_id=u.id LEFT JOIN team_members tm ON tm.user_id=u.id WHERE e.application_key=? AND e.user_id=?");
            $detailStmt->execute([$applicationKey, $detailUserId]);
            $accessDetail = $detailStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $sourceStmt = $pdo->prepare('SELECT DISTINCT p.id,p.name FROM projects p LEFT JOIN project_assignments pa ON pa.project_id=p.id AND pa.user_id=? AND (pa.ends_at IS NULL OR pa.ends_at>UTC_TIMESTAMP(6)) WHERE pa.id IS NOT NULL OR p.manager_user_id=? ORDER BY p.name');
            $sourceStmt->execute([$detailUserId,$detailUserId]);
            $projectSources = $sourceStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $statusStmt = $pdo->prepare('SELECT SUM(CASE WHEN delivered_at IS NULL THEN 1 ELSE 0 END) pending,SUM(CASE WHEN delivered_at IS NULL AND attempts>0 AND last_error IS NOT NULL THEN 1 ELSE 0 END) failed,MAX(delivered_at) last_delivered_at FROM integration_outbox WHERE integration_key=?');
        $statusStmt->execute([$applicationKey]);
        $status = $statusStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $error) {
        $directoryError = true;
        error_log('[external_ops_settings] Failed to load access directory: ' . $error->getMessage());
    }
}
?>
<div class="settings-alert settings-alert-warning"><strong>Optional external operations application.</strong> Project Alpha remains the source of truth. The configured external application receives signed, read-only operational updates and reconciliation snapshots.</div>
<div class="settings-card">
 <h3>External operations application</h3>
 <form method="post" action="/?page=settings/external-ops-handler">
  <input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="save-config">
  <div class="settings-form-grid">
   <label class="check-row" style="grid-column:1/-1"><input type="checkbox" name="enabled" value="1" <?=!empty($config['configured_enabled'])?'checked':''?>> Enable this external operations application</label>
   <label class="field"><span class="label">Display label</span><input class="input" name="label" maxlength="100" required value="<?=$h($label)?>"><small>Deployment-specific. The open-source default is External operations.</small></label>
   <label class="field"><span class="label">Application key</span><input class="input" name="application_key" maxlength="64" minlength="2" pattern="[A-Za-z0-9][A-Za-z0-9_-]{1,63}" required value="<?=$h($applicationKey)?>" placeholder="field_operations"><small>Must match the receiver's configured APPLICATION_KEY.</small></label>
   <label class="field" style="grid-column:1/-1"><span class="label">Signed event URL</span><input class="input" type="url" name="webhook_url" value="<?=$h($config['webhook_url'])?>" placeholder="https://operations.example.com/api/integration/events"></label>
   <label class="field"><span class="label">Access service-token ID</span><input class="input" type="password" name="access_client_id" autocomplete="new-password" placeholder="<?=!empty($config['access_client_id'])?'Configured - leave blank to keep':'Service-token ID'?>"></label>
   <label class="field"><span class="label">Access service-token secret</span><input class="input" type="password" name="access_client_secret" autocomplete="new-password" placeholder="<?=!empty($config['access_client_secret'])?'Configured - leave blank to keep':'Service-token secret'?>"></label>
   <label class="field" style="grid-column:1/-1"><span class="label">HMAC secret</span><input class="input" type="password" name="hmac_secret" minlength="32" autocomplete="new-password" placeholder="<?=!empty($config['hmac_secret'])?'Configured - leave blank to keep':'Same 32+ character secret as the receiver'?>"></label>
   <label class="field"><span class="label">Timeout seconds</span><input class="input" type="number" name="timeout_seconds" min="2" max="60" value="<?=(int)$config['timeout_seconds']?>"></label>
   <label class="field"><span class="label">Maximum attempts</span><input class="input" type="number" name="max_attempts" min="1" max="100" value="<?=(int)$config['max_attempts']?>"></label>
  </div><button class="btn btn-primary">Save integration</button>
 </form>
</div>
<?php if(!empty($config['configured_enabled'])&&empty($config['delivery_ready'])):?>
<div class="settings-alert settings-alert-warning" role="alert"><strong>Outbound signed-event delivery is paused.</strong> Complete or replace the following settings: <?=$h(implode(', ',(array)$config['delivery_issues']))?>. Project Alpha continues recording authoritative events for later delivery, and existing API-key pull synchronization and access administration remain available.</div>
<?php endif;?>
<?php if(empty($config['configured_enabled'])):?><div class="settings-alert settings-alert-info">Outbound signed-event delivery is disabled. Existing API-key pull synchronization is separate and remains available. Enable delivery only after its receiver settings and contract are ready.</div><?php return;endif;?>
<?php if($directoryError):?><div class="settings-alert settings-alert-danger" role="alert"><strong>Integration settings could not be loaded.</strong> Verify that all database migrations have completed, then reload this page.</div><?php return;endif;?>
<div class="settings-card">
 <div class="settings-section-heading"><h3><?=$h($label)?> access</h3><p>Only users explicitly granted access can sign in. Exact PA administrators become global external administrators; everyone else is an assigned-work operator and sees only assigned Projects, Operations, and Tasks.</p></div>
 <form method="get" class="settings-form-grid"><input type="hidden" name="page" value="settings"><input type="hidden" name="tab" value="external-ops"><label class="field"><span class="label">Search selected users</span><input class="input" name="access_search" value="<?=$h($search)?>" placeholder="Name or email"></label><div><button class="btn">Search</button></div></form>
 <div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>User</th><th>Selection</th><th>External role</th><th>Account</th><th></th></tr></thead><tbody>
 <?php foreach($accessUsers as $user):$userEffective=!empty($user['enabled'])&&!empty($user['manual_enabled'])&&empty($user['is_disabled'])&&empty($user['deleted_at'])&&!in_array((string)($user['worker_status']??''),['inactive','terminated'],true)&&!in_array((string)($user['employment_status']??''),['inactive','terminated'],true);?><tr><td><strong><?=$h($user['display_name'])?></strong><small><?=$h(strtolower((string)$user['email']))?></small></td><td><strong style="color:<?=$userEffective?'#047857':'#92400e'?>"><?=$userEffective?'Access granted':'Not granted'?></strong></td><td><?=$user['role']==='admin'?'Global external administrator':'Assigned-work operator'?></td><td><?=$userEffective?'Active':'Inactive / inconsistent'?></td><td><a class="btn btn-sm" href="/?page=settings&amp;tab=external-ops&amp;access_user_id=<?=(int)$user['user_id']?>">Details</a></td></tr><?php endforeach;?>
 <?php if(!$accessUsers):?><tr><td colspan="5">No selected users match this search.</td></tr><?php endif;?></tbody></table></div>
 <div style="display:flex;justify-content:space-between;margin-top:12px"><span><?=$accessCount?> enabled access records</span><span><?php if($pageNumber>1):?><a class="btn btn-sm" href="/?page=settings&amp;tab=external-ops&amp;access_page=<?=$pageNumber-1?>&amp;access_search=<?=rawurlencode($search)?>">Previous</a><?php endif;?> <?php if($offset+$pageSize<$accessCount):?><a class="btn btn-sm" href="/?page=settings&amp;tab=external-ops&amp;access_page=<?=$pageNumber+1?>&amp;access_search=<?=rawurlencode($search)?>">Next</a><?php endif;?></span></div>
</div>
<details class="settings-card" data-external-ops-access><summary><strong><?=$h($grantAccessLabel)?></strong></summary><p>Select a PA account to provision. Inactive accounts are reactivated after confirmation. Assignments determine what non-administrators can see.</p><form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Grant external operations access? If this PA account is inactive, it will also be reactivated. Project and Task visibility will not be expanded.')"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="grant-access"><div class="settings-form-grid"><label class="field"><span class="label">User</span><select class="input" name="user_id" required><option value="">Search or select user</option><?php foreach($eligibleUsers as $user):?><option value="<?=(int)$user['id']?>"><?=$h($user['name'].' - '.$user['email'].(!empty($user['is_disabled'])?' - inactive account':''))?></option><?php endforeach;?></select></label></div><button class="btn btn-primary"><?=$h($grantAccessLabel)?></button></form></details>
<?php if($accessDetail):$effectiveAccess=!empty($accessDetail['enabled'])&&!empty($accessDetail['manual_enabled'])&&empty($accessDetail['is_disabled'])&&empty($accessDetail['deleted_at'])&&!in_array((string)($accessDetail['worker_status']??''),['inactive','terminated'],true)&&!in_array((string)($accessDetail['employment_status']??''),['inactive','terminated'],true);?><div class="settings-card"><h3><?=$h($accessDetail['display_name'])?> access details</h3><p><strong>Assigned Projects:</strong> <?=$projectSources?$h(implode(', ',array_column($projectSources,'name'))):'None'?></p><p><strong>External role:</strong> <?=$accessDetail['role']==='admin'?'Global external administrator':'Assigned-work operator'?></p><p><strong>Effective access:</strong> <?=$effectiveAccess?'Access granted':'Not granted'?></p><?php if(!empty($accessDetail['enabled'])):?><form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Revoke external operations access? The Project Alpha account will remain active.')"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="revoke-access"><input type="hidden" name="user_id" value="<?=(int)$accessDetail['user_id']?>"><button class="btn"><?=$h($revokeAccessLabel)?></button></form><?php endif;?></div><?php endif;?>
<div class="settings-card"><h3>Synchronization status</h3><div class="settings-form-grid"><div><span class="label">Pending</span><strong><?=(int)($status['pending']??0)?></strong></div><div><span class="label">Retry errors</span><strong><?=(int)($status['failed']??0)?></strong></div><div><span class="label">Last delivered</span><strong><?=$h($status['last_delivered_at']??'Never')?></strong></div></div><form method="post" action="/?page=settings/external-ops-handler"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="send-now"><button class="btn" <?=empty($config['delivery_ready'])?'disabled aria-disabled="true" title="Outbound delivery is paused"':''?>>Send due outbound events</button></form></div>
<script>
(function(){function initExternalAccessSearch(context){var root=context&&context.root?context.root:document;root.querySelectorAll('[data-external-ops-access] select[name="user_id"]').forEach(function(select){if(select.dataset.searchReady)return;select.dataset.searchReady='1';var search=document.createElement('input');search.type='search';search.className='input';search.placeholder='Type a name or email to filter';search.setAttribute('aria-label','Search users');select.parentNode.insertBefore(search,select);search.addEventListener('input',function(){var term=search.value.trim().toLowerCase();Array.from(select.options).forEach(function(option,index){option.hidden=index>0&&term!==''&&!option.text.toLowerCase().includes(term);});if(select.selectedOptions[0]&&select.selectedOptions[0].hidden)select.value='';});});}initExternalAccessSearch.pageInitializerId='external-operations-access-search';if(window.ProjectAlpha&&typeof window.ProjectAlpha.registerPage==='function'){window.ProjectAlpha.registerPage('settings',initExternalAccessSearch);}else{initExternalAccessSearch();}})();
</script>
