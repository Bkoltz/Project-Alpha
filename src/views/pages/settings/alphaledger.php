<?php

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/api_scopes.php';
require_once __DIR__ . '/../../../utils/alphaledger_integration.php';

if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    echo '<div class="alert alert-danger">Only a PA administrator can configure AlphaLedger synchronization.</div>';
    return;
}

$policy = pa_al_policy($pdo);
$businessId = pa_al_business_id($pdo);
$eligibleKeys = [];
foreach ($pdo->query('SELECT id,name,key_prefix,scopes,allowed_ips,last_used_at FROM api_keys WHERE revoked_at IS NULL ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
    if (api_normalize_scopes($candidate['scopes'] ?? '') === ['alphaledger.sync']) {
        $eligibleKeys[] = $candidate;
    }
}
$approvedKeyIsEligible = !$policy['approved_api_key_id'] || count(array_filter($eligibleKeys, static fn($key) => (int)$key['id'] === (int)$policy['approved_api_key_id'])) === 1;
$installation = null;
if (!empty($policy['approved_api_key_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM alphaledger_installations WHERE api_key_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int) $policy['approved_api_key_id']]);
    $installation = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$counts = ['pending' => 0, 'attention' => 0, 'conflicts' => 0, 'time' => 0, 'pay' => 0, 'ledger' => 0];
try {
    $counts['pending'] = (int) $pdo->query("SELECT COUNT(*) FROM alphaledger_events WHERE delivery_state='pending'")->fetchColumn();
    $counts['attention'] = (int) $pdo->query("SELECT COUNT(*) FROM alphaledger_events WHERE delivery_state='attention'")->fetchColumn();
    $counts['conflicts'] = (int) $pdo->query("SELECT COUNT(*) FROM alphaledger_sync_conflicts WHERE status='open'")->fetchColumn();
    $counts['time'] = (int) $pdo->query("SELECT COUNT(*) FROM time_entries WHERE source_system='alphaledger'")->fetchColumn();
    $counts['pay'] = (int) $pdo->query('SELECT COUNT(*) FROM employee_pay_records')->fetchColumn();
    $counts['ledger'] = (int) $pdo->query('SELECT COUNT(*) FROM alphaledger_ledger_time_entries')->fetchColumn();
} catch (Throwable $ignored) {
}
$recentEvents = $pdo->query('SELECT event_type,aggregate_id,revision,delivery_state,delivery_attempts,last_error,created_at,delivered_at FROM alphaledger_events ORDER BY sequence_id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
$conflicts = $pdo->query("SELECT object_type,object_id,reason,created_at FROM alphaledger_sync_conflicts WHERE status='open' ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
$enabled = !empty($policy['enabled']);
$state = !$enabled
    ? 'Disconnected'
    : (!$installation || $installation['status'] === 'disabled'
        ? 'Authorized - AL reconnect required'
        : ucfirst((string)$installation['status']));
?>

<style>
.al-settings{display:grid;gap:16px;max-width:1000px}.al-card{border:1px solid #dfe3e8;border-radius:10px;background:#fff;padding:16px}.al-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.al-state{display:inline-flex;padding:5px 9px;border-radius:999px;background:<?php echo $enabled ? '#dcfce7' : '#f1f5f9'; ?>;color:<?php echo $enabled ? '#166534' : '#475569'; ?>;font-weight:700;font-size:12px}.al-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px}.al-stat{border:1px solid #e2e8f0;border-radius:8px;padding:12px;background:#f8fafc}.al-stat strong{display:block;font-size:22px}.al-field{display:grid;gap:5px;margin-bottom:12px}.al-field input,.al-field select{padding:10px;border:1px solid #cbd5e1;border-radius:7px;width:100%}.al-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere;background:#f8fafc;padding:10px;border-radius:7px;border:1px solid #e2e8f0}.al-table{width:100%;border-collapse:collapse;font-size:13px}.al-table th,.al-table td{padding:8px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}.al-danger{border-color:#fecaca;background:#fff7f7}@media(max-width:700px){.al-head{display:grid}}
</style>

<div class="al-settings">
  <?php if (!empty($_GET['success'])): ?><div class="alert alert-success"><?php echo htmlspecialchars((string)$_GET['success']); ?></div><?php endif; ?>
  <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?php echo htmlspecialchars((string)$_GET['error']); ?></div><?php endif; ?>
  <?php if ($enabled && !$approvedKeyIsEligible): ?><div class="alert alert-danger">The authorized API key was revoked or no longer has only the AlphaLedger scope. Synchronization is blocked until an administrator authorizes a valid dedicated key.</div><?php endif; ?>

  <div class="al-card al-head">
    <div><h2 style="margin:0 0 6px">AlphaLedger</h2><p style="margin:0;color:var(--muted)">Optional, tightly controlled synchronization of PA-owned projects and identities with AL-owned approved time and pay accruals.</p></div>
    <span class="al-state"><?php echo htmlspecialchars($state); ?></span>
  </div>

  <div class="al-grid">
    <div class="al-stat"><span>Pending events</span><strong><?php echo $counts['pending']; ?></strong></div>
    <div class="al-stat"><span>Needs attention</span><strong><?php echo $counts['attention']; ?></strong></div>
    <div class="al-stat"><span>Open conflicts</span><strong><?php echo $counts['conflicts']; ?></strong></div>
    <div class="al-stat"><span>Imported time</span><strong><?php echo $counts['time']; ?></strong></div>
    <div class="al-stat"><span>Pay records</span><strong><?php echo $counts['pay']; ?></strong></div>
    <div class="al-stat"><span>Ledger entries</span><strong><?php echo $counts['ledger']; ?></strong></div>
  </div>

  <div class="al-card">
    <h3>Connection identity</h3>
    <p>PA business ID</p><div class="al-code"><?php echo htmlspecialchars($businessId); ?></div>
    <?php if ($installation): ?>
      <p>Installation ID</p><div class="al-code"><?php echo htmlspecialchars((string)$installation['installation_id']); ?></div>
      <p style="color:var(--muted)">Last success: <?php echo htmlspecialchars((string)($installation['last_success_at'] ?: 'Never')); ?> &middot; Consecutive failures: <?php echo (int)$installation['consecutive_failures']; ?></p>
    <?php endif; ?>
  </div>

  <form class="al-card" method="post" action="/?page=settings/alphaledger-handler">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="enable">
    <h3><?php echo $enabled ? 'Reconfirm or change authorization' : 'Enable synchronization'; ?></h3>
    <p>Changing the key or callback disables the previous installation and requires AL to connect again.</p>
    <label class="al-field"><span>Dedicated API key</span><select name="api_key_id" required><option value="">Select a key</option><?php foreach ($eligibleKeys as $key): ?><option value="<?php echo (int)$key['id']; ?>" <?php echo (int)$policy['approved_api_key_id']===(int)$key['id']?'selected':''; ?>><?php echo htmlspecialchars((string)$key['name']); ?> (<?php echo htmlspecialchars((string)$key['key_prefix']); ?>)<?php echo trim((string)$key['allowed_ips'])===''?' - no IP allowlist':''; ?></option><?php endforeach; ?></select><small>Only keys whose sole scope is AlphaLedger integration are accepted. <a href="/?page=api-keys-new">Create one</a>.</small></label>
    <label class="al-field"><span>Exact AL callback URL</span><input type="url" name="callback_url" required value="<?php echo htmlspecialchars((string)($policy['approved_callback_url'] ?? '')); ?>" placeholder="https://ledger.example.com/api/v1/integrations/pa/events"><small>Must use HTTPS and end in <code>/api/v1/integrations/pa/events</code>.</small></label>
    <label class="al-field"><span>Current administrator password</span><input type="password" name="admin_password" required autocomplete="current-password"></label>
    <label class="al-field"><span>Current 6-digit TOTP code</span><input name="totp_code" required inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code"></label>
    <label style="display:flex;gap:8px;align-items:flex-start;margin-bottom:14px"><input type="checkbox" name="confirm_enable" value="1" required><span>I understand AL becomes authoritative for time entries, corrections, and pay-accrual snapshots while PA remains authoritative for projects, invoicing, and payment status.</span></label>
    <label style="display:flex;gap:8px;align-items:flex-start;margin-bottom:14px"><input type="checkbox" name="confirm_unrestricted_key" value="1"><span>If the selected key has no IP allowlist, I explicitly accept that reduced network restriction because this deployment cannot use a stable source IP.</span></label>
    <button class="btn btn-primary" type="submit"><?php echo $enabled ? 'Reconfirm Authorization' : 'Enable AlphaLedger'; ?></button>
  </form>

  <?php if ($enabled): ?>
    <div class="al-card"><h3>Operations</h3><form method="post" action="/?page=settings/alphaledger-handler"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="sync-now"><button class="btn" type="submit">Capture Changes &amp; Sync Now</button></form></div>
    <?php if ($installation): ?><form class="al-card" method="post" action="/?page=settings/alphaledger-handler" onsubmit="return confirm('Rotate the webhook secret and pause delivery until AlphaLedger reconnects?');"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="rotate-secret"><h3>Rotate webhook secret</h3><p>This invalidates the current shared secret and pauses delivery until AL repeats its installation handshake.</p><label class="al-field"><span>Current administrator password</span><input type="password" name="admin_password" required autocomplete="current-password"></label><label class="al-field"><span>Current TOTP code</span><input name="totp_code" required inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code"></label><button class="btn" type="submit">Rotate Secret</button></form><?php endif; ?>
    <form class="al-card al-danger" method="post" action="/?page=settings/alphaledger-handler" onsubmit="return confirm('Disable AlphaLedger synchronization? Pending delivery stops, but imported history remains.');">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="disable">
      <h3>Disable synchronization</h3><label class="al-field"><span>Current administrator password</span><input type="password" name="admin_password" required autocomplete="current-password"></label><label class="al-field"><span>Current TOTP code</span><input name="totp_code" required inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code"></label><button class="btn btn-danger" type="submit">Disable AlphaLedger</button>
    </form>
  <?php endif; ?>

  <?php if ($counts['ledger'] > 0 || $counts['pay'] > 0): ?>
    <form class="al-card al-danger" method="post" action="/?page=settings/alphaledger-handler" onsubmit="return confirm('Permanently purge the retained AlphaLedger operational Ledger? This cannot be undone.');">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"><input type="hidden" name="action" value="purge-ledger">
      <h3>Purge retained Ledger</h3><p>Deletes AL operational people, assignments, time, breaks, revisions, and pay mirrors. PA invoice-linked approved time remains as a financial record.</p><label class="al-field"><span>Current administrator password</span><input type="password" name="admin_password" required autocomplete="current-password"></label><label class="al-field"><span>Current TOTP code</span><input name="totp_code" required inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code"></label><button class="btn btn-danger" type="submit">Purge AlphaLedger Ledger</button>
    </form>
  <?php endif; ?>

  <?php if ($conflicts): ?><div class="al-card al-danger"><h3>Open ownership conflicts</h3><table class="al-table"><thead><tr><th>Object</th><th>Reason</th><th>Detected</th></tr></thead><tbody><?php foreach($conflicts as $row): ?><tr><td><?php echo htmlspecialchars($row['object_type'].' '.$row['object_id']); ?></td><td><?php echo htmlspecialchars((string)$row['reason']); ?></td><td><?php echo htmlspecialchars((string)$row['created_at']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  <div class="al-card"><h3>Recent delivery activity</h3><?php if(!$recentEvents): ?><p>No integration events yet.</p><?php else: ?><div style="overflow:auto"><table class="al-table"><thead><tr><th>Event</th><th>Revision</th><th>State</th><th>Attempts</th><th>Created</th><th>Error</th></tr></thead><tbody><?php foreach($recentEvents as $row): ?><tr><td><?php echo htmlspecialchars((string)$row['event_type']); ?><small style="display:block;color:var(--muted)"><?php echo htmlspecialchars((string)$row['aggregate_id']); ?></small></td><td><?php echo (int)$row['revision']; ?></td><td><?php echo htmlspecialchars((string)$row['delivery_state']); ?></td><td><?php echo (int)$row['delivery_attempts']; ?></td><td><?php echo htmlspecialchars((string)$row['created_at']); ?></td><td><?php echo htmlspecialchars((string)($row['last_error'] ?? '')); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
</div>
