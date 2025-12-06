<?php
// src/views/pages/settings/documents.php
// Sub-tab for documents - default to quotes
$docTab = isset($_GET['doc_tab']) ? preg_replace('/[^a-z]/i', '', $_GET['doc_tab']) : 'quotes';
?>

<div style="display:flex;gap:12px;margin-bottom:16px;border-bottom:2px solid #e5e7eb">
  <a href="/?page=settings&tab=documents&doc_tab=quotes" data-skip-nav style="padding:10px 16px;font-weight:<?php echo $docTab === 'quotes' ? '600' : '400'; ?>;color:<?php echo $docTab === 'quotes' ? 'var(--nav-accent)' : '#6b7280'; ?>;border-bottom:<?php echo $docTab === 'quotes' ? '2px solid var(--nav-accent)' : '2px solid transparent'; ?>;margin-bottom:-2px;text-decoration:none">Quotes</a>
  <a href="/?page=settings&tab=documents&doc_tab=contracts" data-skip-nav style="padding:10px 16px;font-weight:<?php echo $docTab === 'contracts' ? '600' : '400'; ?>;color:<?php echo $docTab === 'contracts' ? 'var(--nav-accent)' : '#6b7280'; ?>;border-bottom:<?php echo $docTab === 'contracts' ? '2px solid var(--nav-accent)' : '2px solid transparent'; ?>;margin-bottom:-2px;text-decoration:none">Contracts</a>
  <a href="/?page=settings&tab=documents&doc_tab=invoices" data-skip-nav style="padding:10px 16px;font-weight:<?php echo $docTab === 'invoices' ? '600' : '400'; ?>;color:<?php echo $docTab === 'invoices' ? 'var(--nav-accent)' : '#6b7280'; ?>;border-bottom:<?php echo $docTab === 'invoices' ? '2px solid var(--nav-accent)' : '2px solid transparent'; ?>;margin-bottom:-2px;text-decoration:none">Invoices</a>
</div>

<?php if ($docTab === 'quotes'): ?>
  <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
    <legend style="padding:0 6px;color:var(--muted)">Quote Options</legend>
    <div style="display:grid;gap:12px">
      <label>
        <input type="checkbox" name="quote_scope_enabled" value="1" <?php echo !empty($appConfig['quote_scope_enabled']) ? 'checked' : ''; ?>>
        <span style="font-weight:600">Enable "Scope of Project" field on quotes</span>
        <div style="margin-top:4px;color:var(--muted);font-size:12px">If enabled, quotes will have a scope field. If left blank, it will be excluded from PDF.</div>
      </label>
    </div>
  </fieldset>
  
  <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
    <legend style="padding:0 6px;color:var(--muted)">Auto-generation on Quote Approval</legend>
    <p style="margin:0 0 12px;color:var(--muted);font-size:13px">Configure what gets automatically created when a quote is approved</p>
    <div style="display:grid;gap:12px">
      <label>
        <input type="checkbox" name="quote_auto_create_contract" value="1" <?php echo !empty($appConfig['quote_auto_create_contract']) || !isset($appConfig['quote_auto_create_contract']) ? 'checked' : ''; ?>>
        <span style="font-weight:600">Auto-create Contract on approval</span>
      </label>
      <label>
        <input type="checkbox" name="quote_auto_create_invoice" value="1" <?php echo !empty($appConfig['quote_auto_create_invoice']) || !isset($appConfig['quote_auto_create_invoice']) ? 'checked' : ''; ?>>
        <span style="font-weight:600">Auto-create Invoice on approval</span>
      </label>
    </div>
  </fieldset>
<?php endif; ?>

<?php if ($docTab === 'contracts'): ?>
  <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
    <legend style="padding:0 6px;color:var(--muted)">Contract Options</legend>
    <div style="display:grid;gap:12px">
      <label>
        <input type="checkbox" name="contract_scope_enabled" value="1" <?php echo !empty($appConfig['contract_scope_enabled']) || !isset($appConfig['contract_scope_enabled']) ? 'checked' : ''; ?>>
        <span style="font-weight:600">Enable "Scope of Contract" field</span>
        <div style="margin-top:4px;color:var(--muted);font-size:12px">Available for both regular and long-term contracts. If left blank, excluded from PDF.</div>
      </label>
      <label>
        <input type="checkbox" name="contract_memo_enabled" value="1" <?php echo !empty($appConfig['contract_memo_enabled']) ? 'checked' : ''; ?>>
        <span style="font-weight:600">Enable "Memo" field</span>
        <div style="margin-top:4px;color:var(--muted);font-size:12px">Add a memo/notes section to contracts for additional context.</div>
      </label>
    </div>
  </fieldset>
  
  <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
    <legend style="padding:0 6px;color:var(--muted)">Signature Agreement Text</legend>
    <p style="margin:0 0 8px;color:var(--muted);font-size:13px">This text appears above the signature line on all contracts</p>
    <textarea name="signature_agreement" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" placeholder="Enter signature agreement text..."><?php echo htmlspecialchars($appConfig['signature_agreement'] ?? 'By signing below, I acknowledge that this is a multi-page contract and that I have read and agree to the terms and conditions.'); ?></textarea>
  </fieldset>
  
  <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px;margin-top:16px">
    <legend style="padding:0 6px;color:var(--muted)">Advanced: Custom Contract Sections</legend>
    <p style="margin:0 0 12px;color:var(--muted);font-size:13px">Define custom sections that appear on all contracts. Drag to reorder. Leave blank to exclude from PDF.</p>
    <div style="padding:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:13px;margin-bottom:12px">
      ⚠️ <strong>Coming Soon:</strong> Custom section builder with drag-and-drop ordering will be available in a future update.
    </div>
    <div style="opacity:0.5;pointer-events:none">
      <div style="padding:10px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;margin-bottom:8px">
        <div style="display:flex;align-items:center;gap:8px">
          <span style="cursor:move">⋮⋮</span>
          <strong>Scope of Work</strong>
          <span style="margin-left:auto;font-size:12px;color:var(--muted)">Built-in</span>
        </div>
      </div>
      <div style="padding:10px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;margin-bottom:8px">
        <div style="display:flex;align-items:center;gap:8px">
          <span style="cursor:move">⋮⋮</span>
          <strong>Terms & Conditions</strong>
          <span style="margin-left:auto;font-size:12px;color:var(--muted)">Built-in</span>
        </div>
      </div>
    </div>
  </fieldset>
<?php endif; ?>

<?php if ($docTab === 'invoices'): ?>
  <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
    <legend style="padding:0 6px;color:var(--muted)">Recurring Invoice Generation</legend>
    <p style="margin:0 0 12px;color:var(--muted);font-size:13px">Configure when the system automatically generates recurring invoices for active long-term contracts.</p>
    
    <div style="display:grid;gap:12px;max-width:600px">
      <label>
        <input type="checkbox" name="cron_enabled" value="1" <?php echo !empty($appConfig['cron_enabled']) ? 'checked' : ''; ?>>
        <span style="font-weight:600">Enable Automatic Invoice Generation</span>
      </label>

      <div id="cronScheduleSection" style="<?php echo empty($appConfig['cron_enabled']) ? 'display:none' : ''; ?>">
        <label>
          <div style="margin-bottom:4px">Schedule</div>
          <select name="cron_schedule" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
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
        
        <div id="customCronSection" style="<?php echo $cronSched !== 'custom' ? 'display:none' : ''; ?>">
          <label>
            <div style="margin-bottom:4px">Custom Cron Expression</div>
            <input type="text" name="cron_custom" value="<?php echo htmlspecialchars($appConfig['cron_custom'] ?? '0 2 * * *'); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd;font-family:monospace" placeholder="0 2 * * *">
            <div style="margin-top:4px;color:var(--muted);font-size:12px">Format: minute hour day month weekday (e.g., "0 2 * * *" = 2:00 AM daily)</div>
          </label>
        </div>
        
        <div style="padding:10px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;font-size:13px">
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
        
        <div style="padding:10px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px;font-size:12px;line-height:1.5">
          <strong>✅ Automatically Configured:</strong> The cron job runs automatically in the Docker container using the schedule above. No manual setup needed!
        </div>
        
        <details style="margin-top:8px">
          <summary style="cursor:pointer;color:var(--muted);font-size:12px">Advanced: Manual Cron Setup</summary>
          <div style="padding:10px;margin-top:8px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;line-height:1.5">
            If running outside Docker, add this to your crontab:<br>
            <code style="display:block;margin-top:6px;padding:6px;background:#fff;border-radius:4px;font-family:monospace">
              <?php 
                $cronExpr = '0 2 * * *'; // default
                switch($cronSched) {
                  case 'hourly': $cronExpr = '0 * * * *'; break;
                  case 'every_6hours': $cronExpr = '0 */6 * * *'; break;
                  case 'daily_midnight': $cronExpr = '0 0 * * *'; break;
                  case 'daily_2am': $cronExpr = '0 2 * * *'; break;
                  case 'daily_6am': $cronExpr = '0 6 * * *'; break;
                  case 'daily_noon': $cronExpr = '0 12 * * *'; break;
                  case 'custom': $cronExpr = $appConfig['cron_custom'] ?? '0 2 * * *'; break;
                }
                echo htmlspecialchars($cronExpr);
              ?> php /var/www/src/cron/generate_recurring_invoices.php &gt;&gt; /var/log/recurring-invoices.log 2&gt;&amp;1
            </code>
          </div>
        </details>
      </div>
    </div>
  </fieldset>
  
  <script>
    (function() {
      var cronEnabled = document.querySelector('input[name="cron_enabled"]');
      var schedSection = document.getElementById('cronScheduleSection');
      var schedSelect = document.querySelector('select[name="cron_schedule"]');
      var customSection = document.getElementById('customCronSection');
      
      if (cronEnabled && schedSection) {
        cronEnabled.addEventListener('change', function() {
          schedSection.style.display = cronEnabled.checked ? '' : 'none';
        });
      }
      
      if (schedSelect && customSection) {
        schedSelect.addEventListener('change', function() {
          customSection.style.display = schedSelect.value === 'custom' ? '' : 'none';
        });
      }
    })();
  </script>
  
  <fieldset style="margin-top:18px;padding:12px;border-radius:8px;border:1px solid #eee">
    <legend style="padding:0 6px;color:var(--muted)">Automatic Invoice Emails</legend>
    <div style="display:flex;flex-direction:column;gap:8px">
      <label style="display:flex;align-items:center;gap:10px">
        <input type="checkbox" name="invoice_auto_send_due_7days" value="1" <?php echo !empty($appConfig['invoice_auto_send_due_7days']) ? 'checked' : ''; ?>>
        <div>
          <div style="font-weight:600">Send reminder 7 days before due</div>
          <div style="font-size:13px;color:var(--muted)">When enabled, the system will email clients one week before an invoice is due.</div>
        </div>
      </label>

      <label style="display:flex;align-items:center;gap:10px">
        <input type="checkbox" name="invoice_auto_send_overdue_weekly" value="1" <?php echo !empty($appConfig['invoice_auto_send_overdue_weekly']) ? 'checked' : ''; ?>>
        <div>
          <div style="font-weight:600">Send weekly reminders for overdue invoices</div>
          <div style="font-size:13px;color:var(--muted)">When enabled, the system will email clients for overdue invoices at most once every 7 days.</div>
        </div>
      </label>
    </div>
  </fieldset>
<?php endif; ?>
