<?php
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$defaultUnitId=(int)($pdo->query('SELECT config_value FROM app_config WHERE organization_id=0 AND config_key="default_business_unit_id" LIMIT 1')->fetchColumn()?:0);
$units=$pdo->query('SELECT bu.*,
 (SELECT COUNT(*) FROM business_unit_memberships bum WHERE bum.business_unit_id=bu.id AND (bum.ended_at IS NULL OR bum.ended_at>UTC_TIMESTAMP(6))) member_count,
 (SELECT COUNT(*) FROM client_business_units cbu WHERE cbu.business_unit_id=bu.id) client_count,
 (SELECT COUNT(*) FROM projects p WHERE p.business_unit_id=bu.id) project_count
 FROM business_units bu ORDER BY bu.is_active DESC,bu.name')->fetchAll(PDO::FETCH_ASSOC);
$selectedId=max(0,(int)($_GET['unit_id']??0));$selected=null;$members=[];$availableUsers=[];$unitClients=[];$unitProjects=[];
foreach($units as $unit)if((int)$unit['id']===$selectedId)$selected=$unit;
if($selected){
 $memberStmt=$pdo->prepare('SELECT bum.*,u.email,u.is_disabled,
  COALESCE(NULLIF(wp.display_name,""),NULLIF(tm.display_name,""),NULLIF(u.username,""),u.email) display_name
  FROM business_unit_memberships bum JOIN users u ON u.id=bum.user_id
  LEFT JOIN worker_profiles wp ON wp.user_id=u.id LEFT JOIN team_members tm ON tm.user_id=u.id
  WHERE bum.business_unit_id=? ORDER BY (bum.ended_at IS NOT NULL),bum.is_primary DESC,bum.membership_role="head" DESC,display_name,bum.id DESC');
 $memberStmt->execute([$selectedId]);$members=$memberStmt->fetchAll(PDO::FETCH_ASSOC);
 $availableStmt=$pdo->prepare('SELECT u.id,u.email,COALESCE(NULLIF(wp.display_name,""),NULLIF(tm.display_name,""),NULLIF(u.username,""),u.email) display_name
  FROM users u LEFT JOIN worker_profiles wp ON wp.user_id=u.id LEFT JOIN team_members tm ON tm.user_id=u.id
  WHERE u.is_disabled=0 AND u.deleted_at IS NULL AND NOT EXISTS (
   SELECT 1 FROM business_unit_memberships bum WHERE bum.business_unit_id=? AND bum.user_id=u.id AND (bum.ended_at IS NULL OR bum.ended_at>UTC_TIMESTAMP(6))
  ) ORDER BY display_name LIMIT 500');
 $availableStmt->execute([$selectedId]);$availableUsers=$availableStmt->fetchAll(PDO::FETCH_ASSOC);
 $stmt=$pdo->prepare('SELECT c.name FROM client_business_units cbu JOIN clients c ON c.id=cbu.client_id WHERE cbu.business_unit_id=? ORDER BY c.name');$stmt->execute([$selectedId]);$unitClients=$stmt->fetchAll(PDO::FETCH_COLUMN);
 $stmt=$pdo->prepare('SELECT p.id,p.name FROM projects p WHERE p.business_unit_id=? ORDER BY p.name');$stmt->execute([$selectedId]);$unitProjects=$stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<div class="settings-alert settings-alert-info"><strong>Business Units describe your company structure.</strong> Use them for a branch, geographic division, department, region, or crew—for example, Green Bay or Chippewa Falls. Organizational membership does not grant workforce permissions or external-application access.</div>

<div class="settings-card">
 <h3><?=$selected?'Edit Business Unit':'Add Business Unit'?></h3>
 <p>Projects can use a Business Unit for operational context. A default Unit is suggested on new Projects when the selected Project Manager has no primary Unit.</p>
 <form method="post" action="/?page=settings/workforce-catalog-handler">
  <input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="save-business-unit"><input type="hidden" name="return_tab" value="business-units-divisions"><input type="hidden" name="id" value="<?=(int)($selected['id']??0)?>">
  <div class="settings-form-grid">
   <label class="field"><span class="label">Name</span><input class="input" name="name" required value="<?=$h($selected['name']??'')?>" placeholder="Green Bay"></label>
   <label class="field"><span class="label">Code</span><input class="input" name="code" required value="<?=$h($selected['code']??'')?>" placeholder="GREEN_BAY"></label>
   <label class="field" style="grid-column:1/-1"><span class="label">Description</span><textarea class="input" name="description" rows="2" placeholder="Area, department, or crew covered by this Unit"><?=$h($selected['description']??'')?></textarea></label>
   <label class="check-row"><input type="checkbox" name="is_active" value="1" <?=!$selected||!empty($selected['is_active'])?'checked':''?>> Active</label>
   <label class="check-row"><input type="checkbox" name="is_default" value="1" <?=$selected&&(int)$selected['id']===$defaultUnitId?'checked':''?>> Default for new Projects</label>
  </div>
  <button class="btn btn-primary"><?=$selected?'Save Unit':'Add Unit'?></button><?php if($selected):?> <a class="btn" href="/?page=settings&amp;tab=business-units-divisions">Cancel</a><?php endif;?>
 </form>
</div>

<div class="settings-card"><h3>Business Units &amp; Divisions</h3><div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>Unit</th><th>People</th><th>Clients</th><th>Projects</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($units as $unit):?><tr><td><strong><?=$h($unit['name'])?></strong><small><?=$h($unit['code'])?><?=(int)$unit['id']===$defaultUnitId?' · Default':''?></small></td><td><?=(int)$unit['member_count']?></td><td><?=(int)$unit['client_count']?></td><td><?=(int)$unit['project_count']?></td><td><?=$unit['is_active']?'Active':'Inactive'?></td><td class="text-right"><a class="btn btn-sm" href="/?page=settings&amp;tab=business-units-divisions&amp;unit_id=<?=(int)$unit['id']?>">Manage</a></td></tr><?php endforeach;?>
<?php if(!$units):?><tr><td colspan="6">No Business Units yet. New Projects may remain visibly unassigned.</td></tr><?php endif;?></tbody></table></div></div>

<?php if($selected):?>
<div class="settings-card">
 <h3><?=$h($selected['name'])?> people</h3>
 <p>Add existing Project Alpha users as organizational Members or Heads. A user may belong to several Units but has only one primary Unit.</p>
 <?php if(!empty($selected['is_active'])&&$availableUsers):?>
 <form method="post" action="/?page=settings/workforce-catalog-handler" class="settings-form-grid">
  <input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="save-unit-membership"><input type="hidden" name="return_tab" value="business-units-divisions"><input type="hidden" name="business_unit_id" value="<?=$selectedId?>">
  <label class="field"><span class="label">User</span><select class="input" name="user_id" required><option value="">Select a user</option><?php foreach($availableUsers as $account):?><option value="<?=(int)$account['id']?>"><?=$h($account['display_name'])?> · <?=$h($account['email'])?></option><?php endforeach;?></select></label>
  <label class="field"><span class="label">Designation</span><select class="input" name="membership_role"><option value="member">Member</option><option value="head">Head</option></select></label>
  <label class="check-row"><input type="checkbox" name="is_primary" value="1"> Make this the user’s primary Business Unit</label>
  <div><button class="btn btn-primary">Add to Unit</button></div>
 </form>
 <?php elseif(empty($selected['is_active'])):?><div class="settings-alert">Activate this Unit before adding people.</div><?php else:?><p>Every active account is already assigned to this Unit.</p><?php endif;?>
 <div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>User</th><th>Designation</th><th>Primary</th><th>Status</th><th></th></tr></thead><tbody>
 <?php foreach($members as $membership):$active=empty($membership['ended_at'])||strtotime((string)$membership['ended_at'])>time();?>
 <tr><td><strong><?=$h($membership['display_name'])?></strong><small><?=$h($membership['email'])?></small></td><td>
 <?php if($active):?><form method="post" action="/?page=settings/workforce-catalog-handler" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="save-unit-membership"><input type="hidden" name="return_tab" value="business-units-divisions"><input type="hidden" name="business_unit_id" value="<?=$selectedId?>"><input type="hidden" name="user_id" value="<?=(int)$membership['user_id']?>"><select class="input" name="membership_role"><option value="member" <?=$membership['membership_role']==='member'?'selected':''?>>Member</option><option value="head" <?=$membership['membership_role']==='head'?'selected':''?>>Head</option></select><label class="check-row"><input type="checkbox" name="is_primary" value="1" <?=!empty($membership['is_primary'])?'checked':''?>> Primary</label><button class="btn btn-sm">Save</button></form><?php else:?><?=ucfirst($h($membership['membership_role']))?><?php endif;?>
 </td><td><?=!empty($membership['is_primary'])?'Yes':'—'?></td><td><?=$active?'Active':'Former'?><?=!empty($membership['is_disabled'])?' · account disabled':''?></td><td class="text-right"><?php if($active):?><form method="post" action="/?page=settings/workforce-catalog-handler"><input type="hidden" name="csrf" value="<?=$h(csrf_token())?>"><input type="hidden" name="action" value="end-unit-membership"><input type="hidden" name="return_tab" value="business-units-divisions"><input type="hidden" name="membership_id" value="<?=(int)$membership['id']?>"><button class="btn btn-sm" onclick="return confirm('End this Business Unit membership?')">End</button></form><?php endif;?></td></tr>
 <?php endforeach;?><?php if(!$members):?><tr><td colspan="5">No current or former members.</td></tr><?php endif;?></tbody></table></div>
</div>
<div class="settings-card"><h3><?=$h($selected['name'])?> activity</h3><div class="settings-form-grid"><div><span class="label">Clients (<?=count($unitClients)?>)</span><p><?=$unitClients?$h(implode(', ',$unitClients)):'None assigned'?></p></div><div><span class="label">Projects (<?=count($unitProjects)?>)</span><p><?php if($unitProjects):foreach($unitProjects as $unitProject):?><a href="/?page=project/projects-details&amp;id=<?=(int)$unitProject['id']?>"><?=$h($unitProject['name'])?></a><?php if($unitProject!==end($unitProjects)):?>, <?php endif;?><?php endforeach;else:?>None assigned<?php endif;?></p></div></div><p><small>Workforce Business Unit scope is managed separately on worker profiles. It may be narrower than this organizational membership.</small></p></div>
<?php endif;?>
