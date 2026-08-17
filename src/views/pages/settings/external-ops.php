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
$portalProfiles=[];$portalWorkspaces=[];$portalWorkspaceLinks=[];$portalPrincipals=[];$portalManagers=[];$portalSchemaReady=true;$portalRuntime=['outbound_enabled'=>false,'hooks_enabled'=>false];$portalDeliveryStatus=[];
try{
    $portalRuntime=(new App\Services\PortalProjectionDeliveryConfigService())->runtime($pdo);
    $portalProfiles=$pdo->query('SELECT * FROM portal_integration_profiles ORDER BY display_label,application_key')->fetchAll(PDO::FETCH_ASSOC);
    $portalWorkspaces=$pdo->query('SELECT * FROM portal_v2_workspaces ORDER BY display_name,public_id')->fetchAll(PDO::FETCH_ASSOC);
    $portalWorkspaceLinks=$pdo->query('SELECT pw.*,p.display_label,w.public_id workspace_public_id,w.display_name workspace_label FROM portal_integration_profile_workspaces pw JOIN portal_integration_profiles p ON p.id=pw.profile_id JOIN portal_v2_workspaces w ON w.id=pw.workspace_id ORDER BY p.display_label,w.display_name')->fetchAll(PDO::FETCH_ASSOC);
    $portalPrincipals=$pdo->query('SELECT * FROM portal_principals WHERE enabled=1 AND revoked_at IS NULL ORDER BY display_name,email_hint')->fetchAll(PDO::FETCH_ASSOC);
    $portalManagers=$pdo->query("SELECT e.*,p.display_name,p.email_hint FROM portal_v2_entitlements e JOIN portal_principals p ON p.id=e.portal_principal_id WHERE e.capability='member.manage' AND e.effect='allow' AND e.active=1 ORDER BY e.scope_type,e.scope_public_id,p.display_name")->fetchAll(PDO::FETCH_ASSOC);
    $portalDeliveryStatus=$pdo->query('SELECT COUNT(*) pending,SUM(attempts>0) retrying,SUM(dead_lettered_at IS NOT NULL) dead_lettered,MAX(delivered_at) last_delivered_at FROM portal_projection_outbox WHERE delivered_at IS NULL')->fetch(PDO::FETCH_ASSOC)?:[];
}catch(Throwable$error){$portalSchemaReady=false;}

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
   <label class="field"><span class="label">Timeout seconds</span><input class="input" type="number" name="timeout_seconds" min="2" max="30" value="<?=(int)$config['timeout_seconds']?>"></label>
   <label class="field"><span class="label">Maximum attempts</span><input class="input" type="number" name="max_attempts" min="1" max="100" value="<?=(int)$config['max_attempts']?>"></label>
  </div><button class="btn btn-primary">Save integration</button>
 </form>
</div>
<div class="settings-alert settings-alert-info"><strong>Provider-neutral portal authority.</strong> These records publish authorization intent only. A primary contact, CRM relationship, legacy public-project link, or matching email never grants portal access. The consuming portal must independently verify and bind the identity.</div>
<?php if(!$portalSchemaReady):?><div class="settings-alert settings-alert-warning" role="alert">Portal v2 administration is unavailable until migrations 0066 and 0067 complete.</div><?php else:?>
<div class="settings-card"><h3>Portal projection runtime</h3><p>Both controls are installation-wide and default off. Delivery never enables a profile or grants portal authority; mutation hooks only reconcile explicitly linked workspaces.</p><form method="post" action="/?page=settings/external-ops-handler"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="save-portal-runtime"><label class="check-row"><input type="checkbox" name="hooks_enabled" value="1" <?=!empty($portalRuntime['hooks_enabled'])?'checked':''?>> Queue scoped reconciliation with authoritative organization, department, client, project, and contact mutations</label><label class="check-row"><input type="checkbox" name="outbound_enabled" value="1" <?=!empty($portalRuntime['outbound_enabled'])?'checked':''?>> Allow the scheduler to deliver profiles whose own delivery switch is enabled</label><button class="btn btn-primary">Save runtime gates</button></form><div class="settings-form-grid"><div><span class="label">Pending</span><strong><?=(int)($portalDeliveryStatus['pending']??0)?></strong></div><div><span class="label">Retrying</span><strong><?=(int)($portalDeliveryStatus['retrying']??0)?></strong></div><div><span class="label">Dead-lettered</span><strong><?=(int)($portalDeliveryStatus['dead_lettered']??0)?></strong></div><div><span class="label">Last delivered</span><strong><?=$h($portalDeliveryStatus['last_delivered_at']??'Never')?></strong></div></div><form method="post" action="/?page=settings/external-ops-handler"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="send-portal-now"><button class="btn" <?=empty($portalRuntime['outbound_enabled'])?'disabled aria-disabled="true"':''?>>Send due portal projections now</button></form></div>
<div class="settings-card"><h3>Portal v2 integration profiles</h3><p>Profiles are installation-specific and remain disabled until every receiving route, dedicated key, HMAC secret, and fixture test is ready.</p>
 <form method="post" action="/?page=settings/external-ops-handler" class="portal-authority-grid"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="save-portal-profile">
  <label class="field"><span class="label">Display label</span><input class="input" name="display_label" maxlength="100" required placeholder="Client portal"></label>
  <label class="field"><span class="label">Application key</span><input class="input" name="application_key" minlength="2" maxlength="64" pattern="[a-z0-9][a-z0-9_-]{1,63}" required placeholder="client_portal"></label>
  <label class="field"><span class="label">Pricing source identifier</span><input class="input" name="pricing_source" maxlength="100" placeholder="portal-client"></label>
  <label class="field"><span class="label">Draft source identifier</span><input class="input" name="draft_source" maxlength="100" placeholder="operations-console"></label>
  <label class="field"><span class="label">Portal receiver route</span><input class="input" type="url" name="portal_route" placeholder="https://portal.example/api/internal/directory"></label>
  <label class="field"><span class="label">Catalog receiver route</span><input class="input" type="url" name="catalog_route" placeholder="https://portal.example/api/internal/catalog"></label>
  <fieldset class="portal-authority-flags"><legend>Default-off capabilities</legend><?php foreach(['enabled'=>'Profile enabled','portal_projection_enabled'=>'Portal projection','relation_projection_enabled'=>'Relation/lifecycle v3','catalog_projection_enabled'=>'Catalog v2','pricing_preview_enabled'=>'Pricing preview','draft_quote_enabled'=>'Draft quote command']as$field=>$labelText):?><label class="check-row"><input type="checkbox" name="<?=$h($field)?>" value="1"> <?=$h($labelText)?></label><?php endforeach;?></fieldset>
  <div><button class="btn btn-primary">Create profile</button></div>
 </form>
 <p class="page-help">Signing secrets are immutable for a key ID. Leave the secret blank to keep it; rotation requires both a distinct new key ID and a new secret.</p>
 <?php if($portalProfiles):?><div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Profile</th><th>Key</th><th>Enabled modules</th><th>Configure</th></tr></thead><tbody><?php foreach($portalProfiles as$profile):?><tr><td><strong><?=$h($profile['display_label'])?></strong><small><?=!empty($profile['delivery_enabled'])?'Delivery enabled':'Delivery off'?></small></td><td><code><?=$h($profile['application_key'])?></code></td><td><?=$h(implode(', ',array_keys(array_filter(['profile'=>(bool)$profile['enabled'],'portal'=>(bool)$profile['portal_projection_enabled'],'relations'=>(bool)$profile['relation_projection_enabled'],'catalog'=>(bool)$profile['catalog_projection_enabled'],'pricing'=>(bool)$profile['pricing_preview_enabled'],'drafts'=>(bool)$profile['draft_quote_enabled']]))))?:'All disabled'?></td><td><details><summary class="btn btn-sm">Edit profile</summary><form method="post" action="/?page=settings/external-ops-handler" class="portal-profile-edit"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="save-portal-profile"><input type="hidden" name="profile_id" value="<?=(int)$profile['id']?>"><label class="field"><span class="label">Display label</span><input class="input" name="display_label" required maxlength="100" value="<?=$h($profile['display_label'])?>"></label><label class="field"><span class="label">Application key</span><input class="input" name="application_key" required maxlength="64" pattern="[a-z0-9][a-z0-9_-]{1,63}" value="<?=$h($profile['application_key'])?>"></label><label class="field"><span class="label">Pricing source</span><input class="input" name="pricing_source" maxlength="100" value="<?=$h($profile['pricing_source'])?>"></label><label class="field"><span class="label">Draft source</span><input class="input" name="draft_source" maxlength="100" value="<?=$h($profile['draft_source'])?>"></label><label class="field"><span class="label">Portal route</span><input class="input" type="url" name="portal_route" value="<?=$h($profile['portal_route'])?>"></label><label class="field"><span class="label">Catalog route</span><input class="input" type="url" name="catalog_route" value="<?=$h($profile['catalog_route'])?>"></label><?php foreach(['enabled'=>'Profile enabled','portal_projection_enabled'=>'Portal projection','relation_projection_enabled'=>'Relations/lifecycle v3','catalog_projection_enabled'=>'Catalog v2','pricing_preview_enabled'=>'Pricing preview','draft_quote_enabled'=>'Draft quote command']as$field=>$labelText):?><label class="check-row"><input type="checkbox" name="<?=$h($field)?>" value="1" <?=!empty($profile[$field])?'checked':''?>> <?=$h($labelText)?></label><?php endforeach;?><button class="btn btn-primary">Save profile</button></form></details><details><summary class="btn btn-sm">Delivery</summary><form method="post" action="/?page=settings/external-ops-handler" class="portal-profile-edit"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="save-portal-delivery"><input type="hidden" name="profile_id" value="<?=(int)$profile['id']?>"><label class="check-row"><input type="checkbox" name="delivery_enabled" value="1" <?=!empty($profile['delivery_enabled'])?'checked':''?>> Enable signed outbound delivery for this profile</label><label class="field"><span class="label">Signing key ID</span><input class="input" name="delivery_key_id" maxlength="64" value="<?=$h($profile['delivery_key_id']??'')?>" required></label><label class="field"><span class="label">Signing secret</span><input class="input" type="password" name="delivery_secret" minlength="32" autocomplete="new-password" placeholder="<?=!empty($profile['delivery_credentials_enc'])?'Configured — blank keeps current':'32+ character secret'?>"></label><label class="field"><span class="label">Rotation overlap hours</span><input class="input" type="number" name="delivery_overlap_hours" min="1" max="168" value="48"></label><label class="field field--wide"><span class="label">Encrypted authentication headers JSON</span><textarea class="input" name="delivery_auth_headers_json" rows="3" placeholder='{"Authentication-Header":"secret value"}'></textarea><small>Blank keeps current headers. At most four operator-chosen headers; reserved signing headers are rejected.</small></label><label class="field"><span class="label">Timeout seconds</span><input class="input" type="number" name="delivery_timeout_seconds" min="2" max="30" value="<?=(int)($profile['delivery_timeout_seconds']??15)?>"></label><label class="field"><span class="label">Maximum attempts</span><input class="input" type="number" name="delivery_max_attempts" min="1" max="50" value="<?=(int)($profile['delivery_max_attempts']??12)?>"></label><button class="btn btn-primary">Save encrypted delivery</button></form></details></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
</div>
<div class="settings-card"><h3>Workspaces and portal principals</h3><div class="portal-authority-grid">
 <form method="post" action="/?page=settings/external-ops-handler"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="save-portal-workspace"><h4>Create or reactivate workspace</h4><label class="field"><span class="label">Profile</span><select class="input" name="profile_id" required><?php foreach($portalProfiles as$p):?><option value="<?=(int)$p['id']?>"><?=$h($p['display_label'])?></option><?php endforeach;?></select></label><label class="field"><span class="label">Root type</span><select class="input" name="root_type"><option value="organization">Organization</option><option value="standalone_client">Standalone client</option></select></label><label class="field"><span class="label">Root public ID</span><input class="input" name="root_public_id" required></label><label class="field"><span class="label">Workspace label</span><input class="input" name="display_name" maxlength="150"></label><button class="btn">Save workspace</button></form>
 <form method="post" action="/?page=settings/external-ops-handler"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="save-portal-principal"><h4>Create portal authority principal</h4><label class="field"><span class="label">Verified-address hint</span><input class="input" type="email" name="email" maxlength="254" required></label><label class="field"><span class="label">Display name</span><input class="input" name="display_name" maxlength="150" required></label><p><small>This does not create a login or bind an identity.</small></p><button class="btn">Save principal</button></form>
</div></div>
<div class="settings-card"><h3>Profile workspace allowlist</h3><p>All projection, pricing, and draft authorization fails closed unless the profile and workspace are explicitly linked here. Creating a workspace links only the selected profile; use this control to relink or disable an existing pair.</p>
 <form method="post" action="/?page=settings/external-ops-handler" class="portal-authority-grid"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="set-portal-workspace-link"><label class="field"><span class="label">Integration profile</span><select class="input" name="profile_id" required><?php foreach($portalProfiles as$p):?><option value="<?=(int)$p['id']?>"><?=$h($p['display_label'])?></option><?php endforeach;?></select></label><label class="field"><span class="label">Workspace</span><select class="input" name="workspace_public_id" required><?php foreach($portalWorkspaces as$w):?><option value="<?=$h($w['public_id'])?>"><?=$h($w['display_name'])?> — <?=$h($w['root_type'])?></option><?php endforeach;?></select></label><label class="field"><span class="label">Link state</span><select class="input" name="link_state"><option value="link">Linked (authorized)</option><option value="unlink">Unlinked (deny)</option></select></label><div><button class="btn">Apply allowlist change</button></div></form>
 <div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Profile</th><th>Workspace</th><th>State</th></tr></thead><tbody><?php foreach($portalWorkspaceLinks as$link):?><tr><td><?=$h($link['display_label'])?></td><td><?=$h($link['workspace_label'])?></td><td><?=!empty($link['active'])?'Linked':'Unlinked'?></td></tr><?php endforeach;?><?php if(!$portalWorkspaceLinks):?><tr><td colspan="3"><strong>No workspaces authorized.</strong> Profiles cannot project, price, or create drafts until explicitly linked.</td></tr><?php endif;?></tbody></table></div>
</div>
<div class="settings-card"><h3>Manager appointment, replacement, and recovery</h3><p>Managers receive the closed manager capability set only at the selected scope. Replacing a manager deactivates the old authority in the same database transaction as the new authority, audit event, and queued complete snapshot.</p>
 <form method="post" action="/?page=settings/external-ops-handler" class="portal-authority-grid"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="appoint-portal-manager">
  <label class="field"><span class="label">Integration profile</span><select class="input" name="profile_id" required><?php foreach($portalProfiles as$p):?><option value="<?=(int)$p['id']?>"><?=$h($p['display_label'])?></option><?php endforeach;?></select></label>
  <label class="field"><span class="label">Workspace</span><select class="input" name="workspace_public_id" required><?php foreach($portalWorkspaces as$w):?><option value="<?=$h($w['public_id'])?>"><?=$h($w['display_name'])?> — <?=$h($w['root_type'])?></option><?php endforeach;?></select></label>
  <label class="field"><span class="label">Manager principal</span><select class="input" name="principal_id" required><?php foreach($portalPrincipals as$p):?><option value="<?=(int)$p['id']?>"><?=$h($p['display_name'])?> — <?=$h($p['email_hint'])?></option><?php endforeach;?></select></label>
  <label class="field"><span class="label">Replace manager (optional)</span><select class="input" name="replace_principal_id"><option value="">Do not replace</option><?php foreach($portalPrincipals as$p):?><option value="<?=(int)$p['id']?>"><?=$h($p['display_name'])?></option><?php endforeach;?></select></label>
  <label class="field"><span class="label">Scope type</span><select class="input" name="scope_type"><option value="workspace">Workspace administrator</option><option value="organization">Organization administrator</option><option value="department">Department head</option><option value="project">Project manager</option></select></label>
  <label class="field"><span class="label">Opaque scope public ID</span><input class="input" name="scope_public_id" required></label>
  <div><button class="btn btn-primary">Save manager authority</button></div>
 </form>
 <div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Manager</th><th>Effective scope</th><th>State</th><th>Offboard</th></tr></thead><tbody><?php foreach($portalManagers as$m):?><tr><td><strong><?=$h($m['display_name'])?></strong><small><?=$h($m['email_hint'])?></small></td><td><?=$h($m['scope_type'])?> <code><?=$h($m['scope_public_id'])?></code></td><td>Active PA authority</td><td><details><summary class="btn btn-sm">Offboard</summary><form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Remove this manager authority? The scope locks if no other manager remains.')"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="offboard-portal-manager"><label class="field"><span class="label">Profile</span><select class="input" name="profile_id" required><?php foreach($portalProfiles as$p):?><option value="<?=(int)$p['id']?>"><?=$h($p['display_label'])?></option><?php endforeach;?></select></label><label class="field"><span class="label">Workspace</span><select class="input" name="workspace_public_id" required><?php foreach($portalWorkspaces as$w):?><option value="<?=$h($w['public_id'])?>"><?=$h($w['display_name'])?></option><?php endforeach;?></select></label><input type="hidden" name="principal_id" value="<?=(int)$m['portal_principal_id']?>"><input type="hidden" name="scope_type" value="<?=$h($m['scope_type'])?>"><input type="hidden" name="scope_public_id" value="<?=$h($m['scope_public_id'])?>"><button class="btn">Confirm offboarding</button></form></details></td></tr><?php endforeach;?><?php if(!$portalManagers):?><tr><td colspan="4"><strong>Management is locked.</strong> Appoint a manager above; contacts and public links cannot recover authority.</td></tr><?php endif;?></tbody></table></div>
</div>
<div class="settings-card"><h3>Projection recovery</h3><p>Queueing a complete snapshot is safe and idempotent at the receiver. It does not activate the client portal feature.</p><?php foreach($portalWorkspaces as$w):?><form method="post" action="/?page=settings/external-ops-handler" style="display:inline-block;margin:4px"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="queue-portal-snapshot"><input type="hidden" name="workspace_public_id" value="<?=$h($w['public_id'])?>"><select class="input" name="profile_id" required style="display:inline-block;width:auto"><?php foreach($portalProfiles as$p):?><option value="<?=(int)$p['id']?>"><?=$h($p['display_label'])?></option><?php endforeach;?></select><button class="btn">Queue <?=$h($w['display_name'])?></button></form><?php endforeach;?></div>
<style>.portal-authority-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:start}.portal-authority-flags{grid-column:1/-1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;border:1px solid var(--border);border-radius:10px;padding:12px}.portal-profile-edit{display:grid;gap:10px;min-width:min(520px,80vw);padding:12px}@media(max-width:640px){.portal-authority-grid,.portal-authority-flags{grid-template-columns:minmax(0,1fr)}.portal-authority-grid .input{width:100%;min-width:0}.pa-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}.portal-profile-edit{min-width:0;width:calc(100vw - 56px)}}@media(max-width:390px){.settings-card{padding-left:12px;padding-right:12px}.portal-profile-edit{width:calc(100vw - 40px);padding:8px}.portal-authority-grid{gap:12px}}</style>
<?php endif;?>
<?php if(!empty($config['configured_enabled'])&&empty($config['delivery_ready'])):?>
<div class="settings-alert settings-alert-warning" role="alert"><strong>Outbound signed-event delivery is paused.</strong> Complete or replace the following settings: <?=$h(implode(', ',(array)$config['delivery_issues']))?>. Project Alpha continues recording authoritative events for later delivery, and existing API-key pull synchronization and access administration remain available.</div>
<?php endif;?>
<?php if(empty($config['configured_enabled'])):?><div class="settings-alert settings-alert-info">Outbound signed-event delivery is disabled. Existing API-key pull synchronization is separate and remains available. Enable delivery only after its receiver settings and contract are ready.</div><?php return;endif;?>
<?php if($directoryError):?><div class="settings-alert settings-alert-danger" role="alert"><strong>Integration settings could not be loaded.</strong> Verify that all database migrations have completed, then reload this page.</div><?php return;endif;?>
<div class="settings-card">
 <div class="settings-section-heading"><h3><?=$h($label)?> access</h3><p>Only users explicitly granted access can sign in. Exact PA administrators become global external administrators; everyone else is an assigned-work operator and sees only assigned Projects, Operations, and Tasks.</p></div>
 <form method="get" class="settings-form-grid"><input type="hidden" name="page" value="settings"><input type="hidden" name="tab" value="external-ops"><label class="field"><span class="label">Search selected users</span><input class="input" name="access_search" value="<?=$h($search)?>" placeholder="Name or email"></label><div><button class="btn">Search</button></div></form>
 <div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>User</th><th>Selection</th><th>External role</th><th>Account</th><th></th></tr></thead><tbody>
 <?php foreach($accessUsers as $user):$userEffective=!empty($user['enabled'])&&!empty($user['manual_enabled'])&&empty($user['is_disabled'])&&empty($user['deleted_at'])&&!in_array((string)($user['worker_status']??''),['inactive','terminated'],true)&&!in_array((string)($user['employment_status']??''),['inactive','terminated'],true);?><tr><td><strong><?=$h($user['display_name'])?></strong><small><?=$h(strtolower((string)$user['email']))?></small></td><td><strong style="color:<?=$userEffective?'#047857':'#92400e'?>"><?=$userEffective?'Access granted':'Not granted'?></strong></td><td><?=$user['role']==='admin'?'Global external administrator':'Assigned-work operator'?></td><td><?=$userEffective?'Active':'Inactive / inconsistent'?></td><td><a class="btn btn-sm" data-external-ops-detail-link href="/?page=settings&amp;tab=external-ops&amp;access_user_id=<?=(int)$user['user_id']?>#integration-access-detail">Details</a></td></tr><?php endforeach;?>
 <?php if(!$accessUsers):?><tr><td colspan="5">No selected users match this search.</td></tr><?php endif;?></tbody></table></div>
 <div style="display:flex;justify-content:space-between;margin-top:12px"><span><?=$accessCount?> enabled access records</span><span><?php if($pageNumber>1):?><a class="btn btn-sm" href="/?page=settings&amp;tab=external-ops&amp;access_page=<?=$pageNumber-1?>&amp;access_search=<?=rawurlencode($search)?>">Previous</a><?php endif;?> <?php if($offset+$pageSize<$accessCount):?><a class="btn btn-sm" href="/?page=settings&amp;tab=external-ops&amp;access_page=<?=$pageNumber+1?>&amp;access_search=<?=rawurlencode($search)?>">Next</a><?php endif;?></span></div>
</div>
<div data-external-ops-detail-region aria-live="polite">
<?php if($accessDetail):$effectiveAccess=!empty($accessDetail['enabled'])&&!empty($accessDetail['manual_enabled'])&&empty($accessDetail['is_disabled'])&&empty($accessDetail['deleted_at'])&&!in_array((string)($accessDetail['worker_status']??''),['inactive','terminated'],true)&&!in_array((string)($accessDetail['employment_status']??''),['inactive','terminated'],true);?><div class="settings-card" id="integration-access-detail" tabindex="-1"><h3><?=$h($accessDetail['display_name'])?> access details</h3><p><strong>Assigned Projects:</strong> <?=$projectSources?$h(implode(', ',array_column($projectSources,'name'))):'None'?></p><p><strong>External role:</strong> <?=$accessDetail['role']==='admin'?'Global external administrator':'Assigned-work operator'?></p><p><strong>Effective access:</strong> <?=$effectiveAccess?'Access granted':'Not granted'?></p><?php if($effectiveAccess):?><div style="display:flex;gap:8px;flex-wrap:wrap"><form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Resend the current external operations access state? This does not change the grant or expand visibility.')"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="resend-access"><input type="hidden" name="user_id" value="<?=(int)$accessDetail['user_id']?>"><button class="btn">Resend <?=$h($grantAccessLabel)?> access</button></form><form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Revoke external operations access? The Project Alpha account will remain active.')"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="revoke-access"><input type="hidden" name="user_id" value="<?=(int)$accessDetail['user_id']?>"><button class="btn"><?=$h($revokeAccessLabel)?></button></form></div><?php elseif(!empty($accessDetail['enabled'])):?><form method="post" action="/?page=settings/external-ops-handler" onsubmit="return confirm('Revoke external operations access? The Project Alpha account will remain active.')"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="revoke-access"><input type="hidden" name="user_id" value="<?=(int)$accessDetail['user_id']?>"><button class="btn"><?=$h($revokeAccessLabel)?></button></form><?php endif;?></div><?php endif;?>
</div>
<details class="settings-card" data-external-ops-access><summary><strong><?=$h($grantAccessLabel)?></strong></summary><p>Type the PA account you intend to provision. Inactive accounts are reactivated after confirmation. Assignments determine what non-administrators can see.</p><form method="post" action="/?page=settings/external-ops-handler" data-external-ops-grant-form><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="grant-access"><input type="hidden" name="user_id" value="" data-external-ops-user-id><div class="settings-form-grid"><label class="field"><span class="label">User</span><input class="input" type="search" list="external-ops-eligible-users" autocomplete="off" required data-external-ops-user-search placeholder="Type a name or email"><datalist id="external-ops-eligible-users"><?php foreach($eligibleUsers as $user):$eligibleLabel=$user['name'].' - '.$user['email'].(!empty($user['is_disabled'])?' - inactive account':'');?><option value="<?=$h($eligibleLabel)?>" data-user-id="<?=(int)$user['id']?>"></option><?php endforeach;?></datalist><small>Choose the exact account from the matching suggestions.</small></label></div><button class="btn btn-primary"><?=$h($grantAccessLabel)?></button></form></details>
<div class="settings-card"><h3>Synchronization status</h3><div class="settings-form-grid"><div><span class="label">Pending</span><strong><?=(int)($status['pending']??0)?></strong></div><div><span class="label">Retry errors</span><strong><?=(int)($status['failed']??0)?></strong></div><div><span class="label">Last delivered</span><strong><?=$h($status['last_delivered_at']??'Never')?></strong></div></div><form method="post" action="/?page=settings/external-ops-handler"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="send-now"><button class="btn" <?=empty($config['delivery_ready'])?'disabled aria-disabled="true" title="Outbound delivery is paused"':''?>>Send due outbound events</button></form></div>
<script>
(function(){
function initExternalAccessDirectory(context){
  var root=context&&context.root?context.root:document;
  root.querySelectorAll('[data-external-ops-grant-form]').forEach(function(form){
    if(form.dataset.directoryReady==='1')return;
    form.dataset.directoryReady='1';
    var search=form.querySelector('[data-external-ops-user-search]');
    var userId=form.querySelector('[data-external-ops-user-id]');
    var list=search?root.querySelector('#'+search.getAttribute('list')):null;
    var options=list?Array.from(list.querySelectorAll('option')):[];
    function syncSelectedUser(){
      var value=search.value.trim().toLowerCase();
      var match=options.find(function(option){return option.value.trim().toLowerCase()===value;});
      userId.value=match?match.dataset.userId:'';
      search.setCustomValidity(value!==''&&!match?'Choose an account from the suggestions.':'');
      return !!match;
    }
    search.addEventListener('input',syncSelectedUser);
    search.addEventListener('change',syncSelectedUser);
    form.addEventListener('submit',function(event){
      if(!syncSelectedUser()){event.preventDefault();search.reportValidity();return;}
      if(!window.confirm('Grant custom integrations access? If this PA account is inactive, it will also be reactivated. Project and Task visibility will not be expanded.'))event.preventDefault();
    });
  });

  var detailRegion=root.querySelector('[data-external-ops-detail-region]');
  root.querySelectorAll('[data-external-ops-detail-link]').forEach(function(link){
    if(link.dataset.detailReady==='1')return;
    link.dataset.detailReady='1';
    link.addEventListener('click',async function(event){
      if(!detailRegion||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey)return;
      event.preventDefault();
      event.stopPropagation();
      detailRegion.setAttribute('aria-busy','true');
      try{
        var response=await fetch(link.href,{headers:{'Accept':'text/html','X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
        if(!response.ok)throw new Error('Unable to load access details.');
        var parsed=new DOMParser().parseFromString(await response.text(),'text/html');
        var loadedRegion=parsed.querySelector('[data-external-ops-detail-region]');
        if(!loadedRegion||!loadedRegion.querySelector('#integration-access-detail'))throw new Error('Access details were not returned.');
        detailRegion.innerHTML=loadedRegion.innerHTML;
        var detail=detailRegion.querySelector('#integration-access-detail');
        if(detail){detail.focus({preventScroll:true});detail.scrollIntoView({block:'nearest',behavior:'smooth'});}
      }catch(error){
        detailRegion.innerHTML='<div class="settings-alert settings-alert-danger" role="alert">Access details could not be loaded. Please try again.</div>';
      }finally{
        detailRegion.removeAttribute('aria-busy');
      }
    });
  });
}
initExternalAccessDirectory.pageInitializerId='external-operations-access-directory';
if(window.ProjectAlpha&&typeof window.ProjectAlpha.registerPage==='function'){window.ProjectAlpha.registerPage('settings',initExternalAccessDirectory);}else{initExternalAccessDirectory();}
})();
</script>
