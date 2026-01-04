<?php
// src/views/pages/financial/audit.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';

// Get current year and set default date range
$currentYear = (int)date('Y');
$startDate = $currentYear . '-01-01';
$endDate = $currentYear . '-12-31';
?>

<section style="padding: 24px;">
  <div style="margin-bottom: 24px;">
    <h2 style="margin: 0 0 8px 0;">Financial Audit Export</h2>
    <p style="color: #6b7280; margin: 0;">Generate and download a complete financial audit report with invoices, contracts, and optional PDFs.</p>
  </div>

  <form id="auditForm" method="POST" action="/?page=financial/audit-export" style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="action" id="formAction" value="generate">

    <!-- Date Range Section -->
    <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
      <legend style="padding: 0 8px; font-weight: 600; color: #1f2937;">Date Range</legend>
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
        <label>
          <div style="margin-bottom: 8px; font-weight: 500; color: #374151;">Start Date</div>
          <input type="date" name="start_date" value="<?php echo $startDate; ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
        </label>
        <label>
          <div style="margin-bottom: 8px; font-weight: 500; color: #374151;">End Date</div>
          <input type="date" name="end_date" value="<?php echo $endDate; ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
        </label>
      </div>
      <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <button type="button" class="preset-btn" data-preset="last-month" style="padding: 8px 16px; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; font-size: 14px;">Last Month</button>
        <button type="button" class="preset-btn" data-preset="last-quarter" style="padding: 8px 16px; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; font-size: 14px;">Last Quarter</button>
        <button type="button" class="preset-btn" data-preset="all-time" style="padding: 8px 16px; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; font-size: 14px;">All Time</button>
        <button type="button" class="preset-btn" data-preset="this-year" style="padding: 8px 16px; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; font-size: 14px;">This Year</button>
      </div>
    </fieldset>

    <!-- Invoice Options Section -->
    <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
      <legend style="padding: 0 8px; font-weight: 600; color: #1f2937;">Content Options</legend>
      <div style="display: grid; gap: 12px;">
        <div style="border-bottom: 1px solid #e5e7eb; padding-bottom: 12px;">
          <div style="font-weight: 500; color: #374151; margin-bottom: 8px;">Include Invoices</div>
          <input type="hidden" name="include_invoices" value="1">
          <div style="display: grid; gap: 8px;">
            <label style="display: flex; align-items: center; gap: 8px; cursor: default;">
              <input type="checkbox" checked disabled style="cursor: not-allowed;">
              <span style="color: #374151;">Include paid and partially paid invoices (always enabled)</span>
            </label>
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
              <input type="checkbox" name="include_unpaid_invoices" value="1" style="cursor: pointer;">
              <span style="color: #374151;">Also include unpaid invoices</span>
            </label>
          </div>
        </div>
        <div style="border-bottom: 1px solid #e5e7eb; padding-bottom: 12px;">
          <div style="font-weight: 500; color: #374151; margin-bottom: 8px;">Include Contracts</div>
          <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
            <input type="checkbox" name="include_contracts" value="1" style="cursor: pointer;">
            <span style="color: #374151;">Include contracts in audit</span>
          </label>
        </div>
        <div style="border-bottom: 1px solid #e5e7eb; padding-bottom: 12px;">
          <div style="font-weight: 500; color: #374151; margin-bottom: 8px;">Include Quotes</div>
          <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
            <input type="checkbox" name="include_quotes" value="1" style="cursor: pointer;">
            <span style="color: #374151;">Include quotes in audit</span>
          </label>
        </div>
      </div>
    </fieldset>

    <!-- Additional Options Section -->
    <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
      <legend style="padding: 0 8px; font-weight: 600; color: #1f2937;">File Generation Options</legend>
      <div style="display: grid; gap: 12px; margin-bottom: 16px;">
        <div>
          <div style="font-weight: 500; color: #374151; margin-bottom: 8px;">CSV Report</div>
          <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
            <input type="checkbox" name="generate_csv" value="1" checked style="cursor: pointer;">
            <span style="color: #374151;">Generate CSV file (default: enabled)</span>
          </label>
        </div>
        <div>
          <div style="font-weight: 500; color: #374151; margin-bottom: 8px;">PDF Generation</div>
          <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
            <input type="checkbox" name="include_pdfs" value="1" style="cursor: pointer;">
            <span style="color: #374151;">Generate PDF files (default: disabled)</span>
          </label>
        </div>
      </div>
      <div style="padding: 12px; background: #f0f9ff; border-left: 4px solid #0284c7; border-radius: 4px;">
        <div style="font-size: 14px; color: #1e40af;">
          <strong>CSV Columns:</strong> Date, Client, Doc Number/ID, Invoice Tax, Invoice Tax County, Amount Paid, Payment Method, Discount, Running Total
        </div>
      </div>
    </fieldset>

    <!-- Email Scheduling Section -->
    <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
      <legend style="padding: 0 8px; font-weight: 600; color: #1f2937;">Automated Email Scheduling (Optional)</legend>
      <div style="margin-bottom: 16px;">
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 12px;">
          <input type="checkbox" name="enable_scheduling" value="1" id="enableScheduling" style="cursor: pointer;">
          <span style="color: #374151; font-weight: 500;">Enable automatic email scheduling</span>
        </label>
      </div>
      <div id="schedulingOptions" style="display: none; border-top: 1px solid #e5e7eb; padding-top: 16px;">
        <div style="margin-bottom: 16px;">
          <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #374151;">Schedule Frequency</label>
          <select name="schedule_frequency" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
            <option value="weekly">Weekly (every Monday)</option>
            <option value="monthly" selected>Monthly (first day of month)</option>
            <option value="quarterly">Quarterly (Jan, Apr, Jul, Oct)</option>
            <option value="annually">Annually (January 1st)</option>
          </select>
        </div>
        <div style="margin-bottom: 16px;">
          <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #374151;">Date Range for Reports</label>
          <select name="schedule_date_range" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
            <option value="last_week">Previous Week</option>
            <option value="last_month">Previous Month</option>
            <option value="last_quarter">Previous Quarter</option>
            <option value="last_year">Previous Year</option>
            <option value="current_year" selected>Current Year to Date</option>
            <option value="all_time">All Time</option>
          </select>
        </div>
        <div style="margin-bottom: 16px;">
          <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #374151;">Email Addresses (up to 5)</label>
          <div id="emailContainer" style="display: grid; gap: 8px;">
            <input type="email" name="schedule_email[]" placeholder="email@example.com" style="padding: 10px; border: 1px solid #ddd; border-radius: 8px;" required>
          </div>
          <button type="button" id="addEmailBtn" style="margin-top: 8px; padding: 8px 12px; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; font-size: 14px;">+ Add Email</button>
        </div>
      </div>
    </fieldset>

    <!-- Action Buttons -->
    <div style="display: flex; gap: 12px; justify-content: flex-end;">
      <button type="reset" style="padding: 10px 20px; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 8px; font-weight: 600; cursor: pointer;">
        Reset
      </button>
      <button type="submit" style="padding: 10px 20px; background: var(--nav-accent); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">
        Generate Audit Report
      </button>
    </div>
  </form>

  <!-- Active Schedules -->
  <?php
  $schedules = $pdo->query('SELECT * FROM audit_schedules ORDER BY created_at DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <div style="margin-top: 32px;">
    <h3 style="margin: 0 0 16px 0; color: #1f2937;">Active Schedules</h3>
    <?php if (empty($schedules)): ?>
      <div style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); text-align: center; color: #6b7280;">
        <p style="margin: 0;">No scheduled audits yet. Enable scheduling in the form above to create automatic reports.</p>
      </div>
    <?php else: ?>
      <div style="background: white; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
              <th style="padding: 12px; text-align: left; font-weight: 600;">Frequency</th>
              <th style="padding: 12px; text-align: left; font-weight: 600;">Date Range</th>
              <th style="padding: 12px; text-align: left; font-weight: 600;">Recipients</th>
              <th style="padding: 12px; text-align: center; font-weight: 600;">Next Run</th>
              <th style="padding: 12px; text-align: center; font-weight: 600;">Status</th>
              <th style="padding: 12px; text-align: center; font-weight: 600;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($schedules as $schedule): ?>
              <?php
                $emails = json_decode($schedule['email_addresses'], true);
                $emailList = is_array($emails) ? implode(', ', $emails) : '';
                $nextRun = $schedule['next_run_at'] ? date('M j, Y', strtotime($schedule['next_run_at'])) : 'Not scheduled';
              ?>
              <tr style="border-bottom: 1px solid #f3f4f6;">
                <td style="padding: 12px; text-transform: capitalize;"><?php echo htmlspecialchars($schedule['frequency']); ?></td>
                <td style="padding: 12px; text-transform: capitalize; color: #6b7280;"><?php echo str_replace('_', ' ', htmlspecialchars($schedule['date_range_type'])); ?></td>
                <td style="padding: 12px; color: #6b7280; font-size: 13px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($emailList); ?></td>
                <td style="padding: 12px; text-align: center; color: #374151; font-weight: 500;"><?php echo htmlspecialchars($nextRun); ?></td>
                <td style="padding: 12px; text-align: center;">
                  <span style="padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; <?php echo $schedule['is_active'] ? 'background: #d1fae5; color: #065f46;' : 'background: #f3f4f6; color: #6b7280;'; ?>">
                    <?php echo $schedule['is_active'] ? 'Active' : 'Paused'; ?>
                  </span>
                </td>
                <td style="padding: 12px; text-align: center;">
                  <form method="post" action="/?page=financial/audit-schedule-handler" style="display: inline;">
                    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?php echo $schedule['id']; ?>">
                    <button type="submit" style="padding: 6px 12px; background: #fff; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; margin-right: 4px; font-size: 13px;">
                      <?php echo $schedule['is_active'] ? 'Pause' : 'Resume'; ?>
                    </button>
                  </form>
                  <form method="post" action="/?page=financial/audit-schedule-handler" style="display: inline;" onsubmit="return confirm('Delete this schedule?');">
                    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo $schedule['id']; ?>">
                    <button type="submit" style="padding: 6px 12px; background: #fff; border: 1px solid #fca5a5; color: #dc2626; border-radius: 6px; cursor: pointer; font-size: 13px;">
                      Delete
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const presetButtons = document.querySelectorAll('.preset-btn');
  const startDateInput = document.querySelector('input[name="start_date"]');
  const endDateInput = document.querySelector('input[name="end_date"]');
  const enableSchedulingCheckbox = document.getElementById('enableScheduling');
  const schedulingOptions = document.getElementById('schedulingOptions');
  const addEmailBtn = document.getElementById('addEmailBtn');
  const emailContainer = document.getElementById('emailContainer');

  // Handle preset date buttons
  presetButtons.forEach(button => {
    button.addEventListener('click', function(e) {
      e.preventDefault();
      const preset = this.dataset.preset;
      const today = new Date();
      let startDate, endDate;

      switch(preset) {
        case 'last-month':
          startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
          endDate = new Date(today.getFullYear(), today.getMonth(), 0);
          break;
        case 'last-quarter':
          const quarter = Math.floor(today.getMonth() / 3);
          startDate = new Date(today.getFullYear(), (quarter - 1) * 3, 1);
          endDate = new Date(today.getFullYear(), quarter * 3, 0);
          break;
        case 'all-time':
          startDate = new Date(2020, 0, 1);
          endDate = today;
          break;
        case 'this-year':
          startDate = new Date(today.getFullYear(), 0, 1);
          endDate = today;
          break;
      }

      if (startDate && endDate) {
        startDateInput.value = startDate.toISOString().split('T')[0];
        endDateInput.value = endDate.toISOString().split('T')[0];
      }
    });
  });

  // Handle email scheduling toggle
  const form = document.getElementById('auditForm');
  enableSchedulingCheckbox.addEventListener('change', function() {
    schedulingOptions.style.display = this.checked ? 'block' : 'none';
    
    // Change form action and button text based on scheduling
    if (this.checked) {
      form.action = '/?page=financial/audit-schedule-handler';
      document.getElementById('formAction').value = 'create';
      document.querySelector('button[type="submit"]').textContent = 'Save Schedule';
    } else {
      form.action = '/?page=financial/audit-export';
      document.getElementById('formAction').value = 'generate';
      document.querySelector('button[type="submit"]').textContent = 'Generate Audit Report';
    }
  });

  // Handle add email button
  addEmailBtn.addEventListener('click', function(e) {
    e.preventDefault();
    const emailInputs = emailContainer.querySelectorAll('input[type="email"]');
    if (emailInputs.length < 5) {
      const newInput = document.createElement('input');
      newInput.type = 'email';
      newInput.name = 'schedule_email[]';
      newInput.placeholder = 'email@example.com';
      newInput.style.cssText = 'padding: 10px; border: 1px solid #ddd; border-radius: 8px;';
      emailContainer.appendChild(newInput);
      
      // Update button visibility
      updateAddEmailButton();
    }
  });

  function updateAddEmailButton() {
    const emailInputs = emailContainer.querySelectorAll('input[type="email"]');
    addEmailBtn.style.display = emailInputs.length >= 5 ? 'none' : 'block';
  }

  // Allow removing empty email fields by clicking backspace on empty input
  emailContainer.addEventListener('keydown', function(e) {
    if (e.key === 'Backspace' && e.target.value === '' && e.target.tagName === 'INPUT') {
      const emailInputs = emailContainer.querySelectorAll('input[type="email"]');
      if (emailInputs.length > 1) {
        e.target.remove();
        updateAddEmailButton();
      }
    }
  });

  updateAddEmailButton();
});
</script>
