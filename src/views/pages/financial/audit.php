<?php
// src/views/pages/financial/audit.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/csrf_sf.php';
require_once __DIR__ . '/../../../utils/acl.php';

// Get current year and set default date range
$currentYear = (int)date('Y');
$startDate = $currentYear . '-01-01';
$endDate = $currentYear . '-12-31';
?>

<section style="padding: 24px;">
  <div style="display:flex;gap:8px;margin-bottom:18px">
    <a class="btn btn-primary" href="/?page=financial/audit">Audit Export</a>
    <a class="btn" href="/?page=financial/expense-report">Expense Reports</a>
  </div>
  <div style="margin-bottom: 24px;">
    <h2 style="margin: 0 0 8px 0;">Financial Audit Export</h2>
    <p style="color: #6b7280; margin: 0;">Generate and download a complete financial audit report with invoices, contracts, and optional PDFs.</p>
    <p style="margin: 12px 0 0 0;"><a class="btn" href="#auditSchedulePanel">Schedule audit emails</a></p>
  </div>

  <form id="auditForm" method="POST" action="/?page=financial/audit-export" style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_sf_token('audit')); ?>">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="action" id="formAction" value="generate">
    <input type="hidden" name="report_type" value="audit">

    <fieldset style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin-bottom:24px">
      <legend style="padding:0 8px;font-weight:600;color:#1f2937">Accounting Basis</legend>
      <select name="accounting_basis" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
        <option value="cash" selected>Cash basis - use successful payment dates and amounts</option>
        <option value="accrual">Accrual basis - use finalized invoice dates and totals</option>
      </select>
    </fieldset>

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

  <form id="auditScheduleForm" method="POST" action="/?page=financial/audit-schedule-handler" style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-top: 24px;">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="report_type" value="audit">
    <input type="hidden" name="include_invoices" value="1">

    <div id="auditSchedulePanel" style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px;">
      <div>
        <h3 style="margin:0 0 6px 0;color:#1f2937;">Scheduled Audit Emails</h3>
        <p style="margin:0;color:#6b7280;">Send recurring audit reports automatically with separate schedule settings.</p>
      </div>
      <button type="submit" style="padding: 10px 20px; background: var(--nav-accent); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; white-space: nowrap;">
        Save Schedule
      </button>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:16px;">
      <label>
        <div style="margin-bottom: 8px; font-weight: 500; color: #374151;">Schedule Frequency</div>
        <select name="schedule_frequency" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
          <option value="weekly">Weekly (every Monday)</option>
          <option value="monthly" selected>Monthly (first day of month)</option>
          <option value="quarterly">Quarterly (Jan, Apr, Jul, Oct)</option>
          <option value="annually">Annually (January 1st)</option>
        </select>
      </label>
      <label>
        <div style="margin-bottom: 8px; font-weight: 500; color: #374151;">Date Range for Reports</div>
        <select name="schedule_date_range" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
          <option value="last_week">Previous Week</option>
          <option value="last_month">Previous Month</option>
          <option value="last_quarter">Previous Quarter</option>
          <option value="last_year">Previous Year</option>
          <option value="current_year" selected>Current Year to Date</option>
          <option value="all_time">All Time</option>
        </select>
      </label>
      <label>
        <div style="margin-bottom: 8px; font-weight: 500; color: #374151;">Accounting Basis</div>
        <select name="accounting_basis" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px">
          <option value="cash" selected>Cash basis</option>
          <option value="accrual">Accrual basis</option>
        </select>
      </label>
    </div>

    <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
      <legend style="padding: 0 8px; font-weight: 600; color: #1f2937;">Scheduled Report Contents</legend>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
          <input type="checkbox" name="include_unpaid_invoices" value="1">
          <span style="color:#374151;">Include unpaid invoices</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
          <input type="checkbox" name="include_contracts" value="1">
          <span style="color:#374151;">Include contracts</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
          <input type="checkbox" name="include_quotes" value="1">
          <span style="color:#374151;">Include quotes</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
          <input type="checkbox" name="include_pdfs" value="1">
          <span style="color:#374151;">Generate PDFs</span>
        </label>
      </div>
    </fieldset>

    <div style="margin-bottom: 8px;">
      <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #374151;">Email Addresses (up to 5)</label>
      <div id="emailContainer" style="display: grid; gap: 8px;">
        <input type="email" name="schedule_email[]" placeholder="email@example.com" style="padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
      </div>
      <button type="button" id="addEmailBtn" style="margin-top: 8px; padding: 8px 12px; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; font-size: 14px;">+ Add Email</button>
    </div>
  </form>

  <!-- Active Schedules -->
  <?php
  $auditOrgId = (int)(function_exists('get_active_org_id') ? get_active_org_id() : ($_SESSION['active_org_id'] ?? 0));
  $scheduleStmt = $pdo->prepare('SELECT * FROM audit_schedules WHERE (?=0 OR organization_id=?) AND report_type="audit" ORDER BY created_at DESC LIMIT 10');
  $scheduleStmt->execute([$auditOrgId, $auditOrgId]);
  $schedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);
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
              <th style="padding: 12px; text-align: left; font-weight: 600;">Basis</th>
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
                <td style="padding: 12px; text-transform: capitalize; color: #6b7280;"><?php echo htmlspecialchars($schedule['accounting_basis'] ?? 'cash'); ?></td>
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

<script src="/assets/js/audit-logic.js" defer></script>
