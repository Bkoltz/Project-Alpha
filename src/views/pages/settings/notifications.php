<?php
// src/views/pages/settings/notifications.php
?>

<div style="max-width:900px">
  <h2 style="margin:0 0 8px 0">Notifications & Automation</h2>
  <p style="margin:0 0 24px 0;color:var(--muted)">Configure automated emails, reminders, and system tasks</p>

  <!-- ============================================================================ -->
  <!-- SYSTEM AUTOMATION -->
  <!-- ============================================================================ -->
  
  <fieldset style="border:1px solid #eee;border-radius:8px;padding:16px;margin-bottom:20px">
    <legend style="padding:0 8px;font-weight:600">System Automation</legend>
    
    <!-- Recurring Invoice Generation -->
    <div style="margin-bottom:20px">
      <h3 style="margin:0 0 8px 0;font-size:15px">Recurring Invoice Generation</h3>
      <p style="margin:0 0 12px;color:var(--muted);font-size:13px">Automatically generate invoices for active long-term contracts</p>
      
      <label style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
        <input type="checkbox" name="cron_enabled" value="1" <?php echo !empty($appConfig['cron_enabled']) ? 'checked' : ''; ?>>
        <span style="font-weight:600">Enable Automatic Invoice Generation</span>
      </label>

      <div id="cronScheduleSection" style="<?php echo empty($appConfig['cron_enabled']) ? 'display:none' : ''; ?>">
        <label>
          <div style="margin-bottom:4px;font-weight:500">Schedule</div>
          <select name="cron_schedule" style="width:100%;max-width:400px;padding:10px;border-radius:8px;border:1px solid #ddd">
            <?php $cronSched = $appConfig['cron_schedule'] ?? 'daily_2am'; ?>
            <option value="hourly" <?php echo $cronSched === 'hourly' ? 'selected' : ''; ?>>Every Hour</option>
            <option value="every_6hours" <?php echo $cronSched === 'every_6hours' ? 'selected' : ''; ?>>Every 6 Hours</option>
            <option value="daily_midnight" <?php echo $cronSched === 'daily_midnight' ? 'selected' : ''; ?>>Daily at Midnight</option>
            <option value="daily_2am" <?php echo $cronSched === 'daily_2am' ? 'selected' : ''; ?>>Daily at 2:00 AM (Recommended)</option>
            <option value="daily_6am" <?php echo $cronSched === 'daily_6am' ? 'selected' : ''; ?>>Daily at 6:00 AM</option>
            <option value="daily_noon" <?php echo $cronSched === 'daily_noon' ? 'selected' : ''; ?>>Daily at Noon</option>
            <option value="custom" <?php echo $cronSched === 'custom' ? 'selected' : ''; ?>>Custom Cron Expression</option>
          </select>
        </label>
        
        <div id="customCronSection" style="margin-top:12px;<?php echo $cronSched !== 'custom' ? 'display:none' : ''; ?>">
          <label>
            <div style="margin-bottom:4px;font-weight:500">Custom Cron Expression</div>
            <input type="text" name="cron_custom" value="<?php echo htmlspecialchars($appConfig['cron_custom'] ?? '0 2 * * *'); ?>" style="width:100%;max-width:400px;padding:10px;border-radius:8px;border:1px solid #ddd;font-family:monospace" placeholder="0 2 * * *">
            <div style="margin-top:4px;color:var(--muted);font-size:12px">Format: minute hour day month weekday (e.g., "0 2 * * *" = 2:00 AM daily)</div>
          </label>
        </div>
        
        <div style="margin-top:12px;padding:10px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;font-size:13px">
          <strong>Current Status:</strong>
          <div style="margin-top:4px">
            <?php 
              $lastRun = $appConfig['cron_last_run'] ?? null;
              if ($lastRun) {
                echo 'Last run: ' . date('M j, Y g:i A', strtotime($lastRun));
              } else {
                echo 'Never run';
              }
            ?>
          </div>
        </div>
        
        <div style="margin-top:12px;padding:10px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px;font-size:12px;line-height:1.5">
          <strong>✅ Automatically Configured:</strong> The cron job runs automatically in the Docker container using the schedule above.
        </div>
      </div>
    </div>

    <!-- Contract Auto-Termination -->
    <div style="padding-top:16px;border-top:1px solid #eee">
      <h3 style="margin:0 0 8px 0;font-size:15px">Contract Auto-Termination</h3>
      <p style="margin:0 0 12px;color:var(--muted);font-size:13px">Automatically terminate contracts when end date is reached</p>
      
      <label style="display:flex;align-items:center;gap:10px">
        <input type="checkbox" name="auto_terminate_contracts" value="1" <?php echo !empty($appConfig['auto_terminate_contracts']) ? 'checked' : ''; ?>>
        <span style="font-weight:600">Enable Auto-Termination</span>
      </label>
    </div>

    <!-- Link Expiration Checker -->
    <div style="padding-top:16px;border-top:1px solid #eee;margin-top:16px">
      <h3 style="margin:0 0 8px 0;font-size:15px">Link Expiration Checker</h3>
      <p style="margin:0 0 12px;color:var(--muted);font-size:13px">Daily check for expired client/organization links</p>
      
      <label style="display:flex;align-items:center;gap:10px">
        <input type="checkbox" name="link_expiration_checker" value="1" <?php echo !empty($appConfig['link_expiration_checker']) ? 'checked' : ''; ?>>
        <span style="font-weight:600">Enable Link Expiration Checker</span>
      </label>
    </div>
  </fieldset>

  <!-- ============================================================================ -->
  <!-- EMAIL NOTIFICATIONS -->
  <!-- ============================================================================ -->
  
  <fieldset style="border:1px solid #eee;border-radius:8px;padding:16px">
    <legend style="padding:0 8px;font-weight:600">Email Notifications</legend>
    
    <!-- Invoice Reminders -->
    <div style="margin-bottom:16px">
      <h3 style="margin:0 0 12px 0;font-size:15px">Invoice Reminders</h3>
      
      <label style="display:flex;align-items:start;gap:10px;margin-bottom:12px">
        <input type="checkbox" name="invoice_auto_send_due_7days" value="1" <?php echo !empty($appConfig['invoice_auto_send_due_7days']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div>
          <div style="font-weight:600">Send reminder 7 days before due</div>
          <div style="font-size:13px;color:var(--muted)">Email clients one week before an invoice is due</div>
        </div>
      </label>

      <label style="display:flex;align-items:start;gap:10px">
        <input type="checkbox" name="invoice_auto_send_overdue_weekly" value="1" <?php echo !empty($appConfig['invoice_auto_send_overdue_weekly']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div>
          <div style="font-weight:600">Send weekly reminders for overdue invoices</div>
          <div style="font-size:13px;color:var(--muted)">Email clients for overdue invoices at most once every 7 days</div>
        </div>
      </label>
    </div>

    <!-- Contract Notifications -->
    <div style="padding-top:16px;border-top:1px solid #eee;margin-bottom:16px">
      <h3 style="margin:0 0 12px 0;font-size:15px">Contract Notifications</h3>
      
      <label style="display:flex;align-items:start;gap:10px;margin-bottom:12px">
        <input type="checkbox" name="contract_expiring_warning" value="1" <?php echo !empty($appConfig['contract_expiring_warning']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div style="flex:1">
          <div style="font-weight:600">Contract expiration warning</div>
          <div style="font-size:13px;color:var(--muted);margin-bottom:6px">Notify when contracts are approaching end date</div>
          <div style="display:flex;align-items:center;gap:8px">
            <span style="font-size:13px">Send warning</span>
            <input type="number" name="contract_expiring_days" value="<?php echo $appConfig['contract_expiring_days'] ?? 30; ?>" min="1" max="90" style="width:70px;padding:6px;border-radius:4px;border:1px solid #ddd">
            <span style="font-size:13px">days before expiration</span>
          </div>
        </div>
      </label>

      <label style="display:flex;align-items:start;gap:10px">
        <input type="checkbox" name="contract_expired_alert" value="1" <?php echo !empty($appConfig['contract_expired_alert']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div>
          <div style="font-weight:600">Contract expired alert</div>
          <div style="font-size:13px;color:var(--muted)">Send notification when contract has expired</div>
        </div>
      </label>
    </div>

    <!-- Payment Notifications -->
    <div style="padding-top:16px;border-top:1px solid #eee;margin-bottom:16px">
      <h3 style="margin:0 0 12px 0;font-size:15px">Payment Notifications</h3>
      
      <label style="display:flex;align-items:start;gap:10px;margin-bottom:12px">
        <input type="checkbox" name="payment_failure_alert" value="1" <?php echo !empty($appConfig['payment_failure_alert']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div>
          <div style="font-weight:600">Auto-pay failure alert</div>
          <div style="font-size:13px;color:var(--muted)">Notify when automatic payment fails</div>
        </div>
      </label>

      <label style="display:flex;align-items:start;gap:10px">
        <input type="checkbox" name="payment_received_notification" value="1" <?php echo !empty($appConfig['payment_received_notification']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div>
          <div style="font-weight:600">Payment received confirmation</div>
          <div style="font-size:13px;color:var(--muted)">Send confirmation email to clients when payment is received</div>
        </div>
      </label>
    </div>

    <!-- Link Notifications -->
    <div style="padding-top:16px;border-top:1px solid #eee">
      <h3 style="margin:0 0 12px 0;font-size:15px">Link Expiration Warnings</h3>
      
      <label style="display:flex;align-items:start;gap:10px">
        <input type="checkbox" name="link_expiration_warning" value="1" <?php echo !empty($appConfig['link_expiration_warning']) ? 'checked' : ''; ?> style="margin-top:3px">
        <div style="flex:1">
          <div style="font-weight:600">Notify before link expiration</div>
          <div style="font-size:13px;color:var(--muted);margin-bottom:6px">Send warning before client/org links expire</div>
          <div style="display:flex;align-items:center;gap:8px">
            <span style="font-size:13px">Send warning</span>
            <input type="number" name="link_expiration_warning_days" value="<?php echo $appConfig['link_expiration_warning_days'] ?? 30; ?>" min="1" max="90" style="width:70px;padding:6px;border-radius:4px;border:1px solid #ddd">
            <span style="font-size:13px">days before expiration</span>
          </div>
        </div>
      </label>
    </div>
  </fieldset>
</div>

<script>
(function() {
  // Toggle cron schedule section
  var cronEnabled = document.querySelector('input[name="cron_enabled"]');
  var schedSection = document.getElementById('cronScheduleSection');
  if (cronEnabled && schedSection) {
    cronEnabled.addEventListener('change', function() {
      schedSection.style.display = cronEnabled.checked ? '' : 'none';
    });
  }
  
  // Toggle custom cron section
  var schedSelect = document.querySelector('select[name="cron_schedule"]');
  var customSection = document.getElementById('customCronSection');
  if (schedSelect && customSection) {
    schedSelect.addEventListener('change', function() {
      customSection.style.display = schedSelect.value === 'custom' ? '' : 'none';
    });
  }
})();
</script>
