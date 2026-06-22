<?php
// src/views/pages/settings/system.php
?>
<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
  <legend style="padding:0 6px;color:var(--muted)">Domain &amp; Public Access</legend>
  
  <label style="display:block;margin-bottom:12px">
    <div style="margin-bottom:4px;font-weight:500">Application Domain <span style="color:#666;font-weight:normal">(Optional)</span></div>
    <input type="text" name="app_host" value="<?php echo htmlspecialchars($appConfig['app_host'] ?? ''); ?>" placeholder="invoices.example.com" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    <div style="font-size:0.85em;color:#666;margin-top:4px">If set, public links and emails will use this domain. Must be a valid domain that points to this server.</div>
  </label>
  
  <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;margin-bottom:8px">
    <input type="checkbox" name="public_links_in_email" value="1" <?php echo !empty($appConfig['public_links_in_email']) ? 'checked' : ''; ?> style="margin-top:3px;width:18px;height:18px">
    <div>
      <div style="font-weight:500">Include public payment links in emails</div>
      <div style="font-size:0.85em;color:#666">When enabled, invoice emails will include a secure link for clients to view and pay online. Only works when a valid domain is configured above.</div>
    </div>
  </label>
  
  <?php if (empty($appConfig['app_host'])): ?>
  <div style="margin-top:12px;padding:12px;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;font-size:13px;color:#92400e">
    <strong>⚠️ Domain Required:</strong> Public links are disabled until you configure a domain. Set up a domain pointing to this server, then enter it above.
  </div>
  <?php endif; ?>
</fieldset>

<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
  <legend style="padding:0 6px;color:var(--muted)">Brand</legend>
  <label>
    <div>Brand Name</div>
    <input type="text" name="brand_name" value="<?php echo htmlspecialchars(($appConfig['brand_name'] ?? 'Project Alpha')); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
  </label>

  <div>
    <div>Logo (PNG, JPG, WEBP)</div>
    <?php 
    $logoPreview = $appConfig['logo_path'] ?? '';
    if (empty($logoPreview)) {
      $logoPreview = '/assets/default-logo.png';
    }
    ?>
    <div style="margin:8px 0">
      <img alt="Current logo" src="<?php echo htmlspecialchars($logoPreview); ?>" style="max-width:240px;max-height:120px;object-fit:contain;border-radius:6px;background:#fff;padding:8px">
      <?php if (empty($appConfig['logo_path'])): ?>
        <div style="font-size:0.8rem;color:#6c757d;margin-top:4px;">Default logo shown. Upload a custom logo to replace it.</div>
      <?php endif; ?>
    </div>
    <input type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/webp">
  </div>
</fieldset>

<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
  <legend style="padding:0 6px;color:var(--muted)">User Info (From)</legend>
  <label>
    <div>Name</div><input name="from_name" value="<?php echo htmlspecialchars($appConfig['from_name'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
  </label>
  <label>
    <div>Address line 1</div><input name="from_address_line1" value="<?php echo htmlspecialchars($appConfig['from_address_line1'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
  </label>
  <label>
    <div>Address line 2</div><input name="from_address_line2" value="<?php echo htmlspecialchars($appConfig['from_address_line2'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
  </label>
  <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr 1fr">
    <label>
      <div>City</div><input name="from_city" value="<?php echo htmlspecialchars($appConfig['from_city'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>State</div><input name="from_state" value="<?php echo htmlspecialchars($appConfig['from_state'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Postal</div><input name="from_postal" value="<?php echo htmlspecialchars($appConfig['from_postal'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
  </div>
  <div style="margin-top:12px">
    <label>
      <div>Primary State (default for new clients)</div>
      <input name="primary_state" value="<?php echo htmlspecialchars($appConfig['primary_state'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="WI">
    </label>
  </div>
  <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr">
    <label>
      <div>Email</div><input name="from_email" value="<?php echo htmlspecialchars($appConfig['from_email'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Phone</div><input name="from_phone" value="<?php echo htmlspecialchars($appConfig['from_phone'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="(123) 456-7890">
    </label>
  </div>
</fieldset>

<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
  <legend style="padding:0 6px;color:var(--muted)">Timezone</legend>
  <?php $tzCurrent = $appConfig['timezone'] ?? date_default_timezone_get();
  $zones = function_exists('timezone_identifiers_list') ? timezone_identifiers_list() : []; ?>
  <label>
    <div>Select Timezone</div>
    <select name="timezone" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      <?php foreach ($zones as $z): ?>
        <option value="<?php echo htmlspecialchars($z); ?>" <?php echo ($tzCurrent === $z) ? 'selected' : ''; ?>><?php echo htmlspecialchars($z); ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</fieldset>

<?php if (!empty($_GET['email_test']) && $_GET['email_test'] === '1'): ?>
  <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">Test email sent.</div>
<?php elseif (!empty($_GET['email_err'])): ?>
  <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Test email failed: <?php echo htmlspecialchars($_GET['email_err']); ?></div>
<?php endif; ?>
<fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
  <legend style="padding:0 6px;color:var(--muted)">Outgoing Email (SMTP)</legend>
  <p style="margin:0 0 8px;color:var(--muted)">Configure SMTP to send emails from your own account. For Gmail, enable 2-Step Verification and create an App Password.</p>
  <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr 1fr">
    <label>
      <div>SMTP Host</div><input name="smtp_host" value="<?php echo htmlspecialchars($appConfig['smtp_host'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="smtp.gmail.com">
    </label>
    <label>
      <div>Port</div><input type="number" name="smtp_port" value="<?php echo htmlspecialchars((string)($appConfig['smtp_port'] ?? 587)); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Security</div>
      <select name="smtp_secure" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <?php $sec = strtolower((string)($appConfig['smtp_secure'] ?? 'tls')); ?>
        <option value="tls" <?php echo $sec === 'tls' ? 'selected' : ''; ?>>TLS</option>
        <option value="ssl" <?php echo $sec === 'ssl' ? 'selected' : ''; ?>>SSL</option>
        <option value="none" <?php echo $sec === 'none' ? 'selected' : ''; ?>>None</option>
      </select>
    </label>
  </div>
  <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr">
    <label>
      <div>Username (email)</div><input name="smtp_username" value="<?php echo htmlspecialchars($appConfig['smtp_username'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="you@gmail.com">
    </label>
    <label>
      <div>App Password</div><input type="password" name="smtp_password" value="" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Enter to update (leave blank to keep)">
    </label>
  </div>
  <p style="margin:6px 0 0;color:var(--muted);font-size:12px">For Gmail: host smtp.gmail.com, port 587 (TLS) or 465 (SSL); use an App Password (not your normal password).</p>
  <div style="margin-top:12px">
    <button type="button" id="btnEmailTest" style="padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">Send Test Email</button>
    <div id="emailTestResult" style="margin-top:8px;font-size:13px"></div>
  </div>
</fieldset>
