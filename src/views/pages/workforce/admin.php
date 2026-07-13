<?php
$employees = $pdo->query("SELECT u.id,u.email,u.username,u.is_disabled,ep.* FROM users u JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.role='employee' ORDER BY ep.employment_status,ep.first_name,ep.last_name,u.email")->fetchAll(PDO::FETCH_ASSOC);
$projects = $pdo->query("SELECT id,name,status FROM projects WHERE status NOT IN ('completed','cancelled') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$business = $pdo->query('SELECT * FROM business_settings WHERE singleton=1')->fetch(PDO::FETCH_ASSOC);
$selectedId = (int) ($_GET['employee'] ?? 0);
$selected = null;
$assignedIds = [];
$assignmentRates = [];
if ($selectedId) {
    $stmt = $pdo->prepare("SELECT u.id,u.email,u.username,u.is_disabled,ep.* FROM users u JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id=? AND u.role='employee'");
    $stmt->execute([$selectedId]);
    $selected = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $stmt = $pdo->prepare('SELECT project_id,pay_rate_override FROM project_assignments WHERE user_id=?');
    $stmt->execute([$selectedId]);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $assignment){$assignedIds[]=(int)$assignment['project_id'];$assignmentRates[(int)$assignment['project_id']]=$assignment['pay_rate_override'];}
}
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<div class="page-header"><div><p class="eyebrow">Administration</p><h1>Workforce</h1><p>Employee accounts, pay settings, and project assignments.</p></div></div>
<?php if (!empty($_GET['success'])): ?><div class="al-alert"><?= $h($_GET['success']) ?></div><?php endif; ?>
<?php if (!empty($_GET['error'])): ?><div class="al-alert error"><?= $h($_GET['error']) ?></div><?php endif; ?>
<div class="al-grid">
  <section class="al-card"><h2>Business time settings</h2><form class="al-form" method="post" action="/workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="business-settings"><label>Business name<input name="business_name" value="<?= $h($business['business_name']??'My Business') ?>"></label><label>Timezone<input name="timezone" value="<?= $h($business['timezone']??'UTC') ?>" required></label><label>Currency<input name="currency" maxlength="3" value="<?= $h($business['currency']??'USD') ?>" required></label><label>Default employee pay rate<input name="default_hourly_rate" value="<?= $h($business['default_hourly_rate']??'') ?>" inputmode="decimal"></label><label>Default customer billing rate<input name="default_billing_rate" value="<?= $h($business['default_billing_rate']??'') ?>" inputmode="decimal"></label><label><span><input style="width:auto" type="checkbox" name="require_project" value="1" <?= !empty($business['require_project'])?'checked':'' ?>> Require project</span></label><label><span><input style="width:auto" type="checkbox" name="require_description" value="1" <?= !empty($business['require_description'])?'checked':'' ?>> Require description</span></label><button class="btn">Save business settings</button></form></section>
  <section class="al-card"><h2>Add employee</h2><p class="al-muted">Employees sign in through Project Alpha and must change this temporary password.</p>
    <form class="al-form" method="post" action="/workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="employee-create">
      <label>First name<input name="first_name" required></label><label>Last name<input name="last_name"></label><label>Email<input type="email" name="email" required></label><label>Username<input name="username"></label><label>Temporary password<input type="password" name="password" required autocomplete="new-password"></label><label>Hourly pay rate<input name="hourly_rate" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,4})?"></label><button class="btn btn-primary">Create employee account</button>
    </form>
  </section>
  <section class="al-card"><h2>Employees</h2><table class="al-table"><thead><tr><th>Name</th><th>Status</th><th></th></tr></thead><tbody><?php foreach ($employees as $employee): ?><tr><td><strong><?= $h(trim($employee['first_name'].' '.$employee['last_name']) ?: $employee['email']) ?></strong><small class="al-muted" style="display:block"><?= $h($employee['email']) ?></small></td><td><?= $h(ucfirst($employee['employment_status'])) ?></td><td><a class="btn" href="/workforce?employee=<?= (int) $employee['user_id'] ?>">Manage</a></td></tr><?php endforeach; ?><?php if (!$employees): ?><tr><td colspan="3">No employee accounts.</td></tr><?php endif; ?></tbody></table></section>
</div>
<?php if ($selected): ?><section class="al-card"><h2>Manage <?= $h(trim($selected['first_name'].' '.$selected['last_name']) ?: $selected['email']) ?></h2>
  <form class="al-form" method="post" action="/workforce/action"><input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="action" value="employee-update"><input type="hidden" name="user_id" value="<?= (int) $selected['user_id'] ?>">
    <div class="al-grid"><label>First name<input name="first_name" value="<?= $h($selected['first_name']) ?>"></label><label>Last name<input name="last_name" value="<?= $h($selected['last_name']) ?>"></label><label>Employment status<select name="employment_status"><?php foreach (['active','inactive','terminated'] as $status): ?><option value="<?= $status ?>" <?= $selected['employment_status']===$status?'selected':'' ?>><?= ucfirst($status) ?></option><?php endforeach; ?></select></label><label>Hourly pay rate<input name="hourly_rate" value="<?= $h($selected['hourly_rate']) ?>" inputmode="decimal"></label></div>
    <label><span><input type="checkbox" name="employee_can_view_pay" value="1" style="width:auto" <?= $selected['employee_can_view_pay']?'checked':'' ?>> Employee may view their own pay accruals</span></label>
    <fieldset><legend>Project assignments</legend><div class="al-grid"><?php foreach ($projects as $project): ?><div><label style="display:block;font-weight:400"><input style="width:auto" type="checkbox" name="project_ids[]" value="<?= (int) $project['id'] ?>" <?= in_array((int)$project['id'],$assignedIds,true)?'checked':'' ?>> <?= $h($project['name']) ?></label><label class="al-muted">Pay-rate override<input name="project_rates[<?= (int)$project['id'] ?>]" value="<?= $h($assignmentRates[(int)$project['id']]??'') ?>" inputmode="decimal"></label></div><?php endforeach; ?></div></fieldset>
    <button class="btn btn-primary">Save employee</button>
  </form>
</section><?php endif; ?>
