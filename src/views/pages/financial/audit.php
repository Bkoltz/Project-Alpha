<?php
// src/views/pages/financial/audit.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';

// Get current year and set default date range
$currentYear = (int)date('Y');
$minYear = 2020;
$maxYear = $currentYear;
?>

<section style="padding: 24px;">
  <div style="margin-bottom: 24px;">
    <h2 style="margin: 0 0 8px 0;">Financial Audit Export</h2>
    <p style="color: #6b7280; margin: 0;">Generate and download a complete financial audit report with invoices, contracts, and optional PDFs.</p>
  </div>

  <form id="auditForm" method="POST" action="/?page=financial/audit-export" style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">

    <!-- Date Range Section -->
    <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
      <legend style="padding: 0 8px; font-weight: 600; color: #1f2937;">Date Range (Years)</legend>
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
        <label>
          <div style="margin-bottom: 8px; font-weight: 500; color: #374151;">Start Year</div>
          <select name="start_year" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
            <?php for ($year = $minYear; $year <= $maxYear; $year++): ?>
              <option value="<?php echo $year; ?>" <?php echo $year === $minYear ? 'selected' : ''; ?>><?php echo $year; ?></option>
            <?php endfor; ?>
          </select>
        </label>
        <label>
          <div style="margin-bottom: 8px; font-weight: 500; color: #374151;">End Year</div>
          <select name="end_year" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
            <?php for ($year = $minYear; $year <= $maxYear; $year++): ?>
              <option value="<?php echo $year; ?>" <?php echo $year === $maxYear ? 'selected' : ''; ?>><?php echo $year; ?></option>
            <?php endfor; ?>
          </select>
        </label>
      </div>
    </fieldset>

    <!-- Invoice Options Section -->
    <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
      <legend style="padding: 0 8px; font-weight: 600; color: #1f2937;">Invoice Options</legend>
      <div style="display: grid; gap: 12px;">
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
          <input type="radio" name="invoice_status" value="paid_only" checked style="cursor: pointer;">
          <span style="color: #374151;">Paid invoices only (default)</span>
        </label>
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
          <input type="radio" name="invoice_status" value="paid_and_partial" style="cursor: pointer;">
          <span style="color: #374151;">Paid and partial invoices</span>
        </label>
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
          <input type="radio" name="invoice_status" value="unpaid_only" style="cursor: pointer;">
          <span style="color: #374151;">Unpaid invoices only</span>
        </label>
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
          <input type="radio" name="invoice_status" value="all" style="cursor: pointer;">
          <span style="color: #374151;">All invoices</span>
        </label>
      </div>
    </fieldset>

    <!-- Additional Options Section -->
    <fieldset style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
      <legend style="padding: 0 8px; font-weight: 600; color: #1f2937;">Additional Options</legend>
      <div style="display: grid; gap: 12px;">
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
          <input type="checkbox" name="include_contracts" value="1" style="cursor: pointer;">
          <span style="color: #374151;">Include contracts in audit</span>
        </label>
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
          <input type="checkbox" name="include_pdfs" value="1" style="cursor: pointer;">
          <span style="color: #374151;">Include PDF files for invoices</span>
        </label>
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
          <input type="checkbox" name="client_info_only" value="1" style="cursor: pointer;">
          <span style="color: #374151;">CSV with client info and summary only (no detailed line items)</span>
        </label>
      </div>
      <div style="margin-top: 16px; padding: 12px; background: #f0f9ff; border-left: 4px solid #0284c7; border-radius: 4px;">
        <div style="font-size: 14px; color: #1e40af;">
          <strong>Note:</strong> CSV will include Client Name, Doc ID (Invoice/Contract #), Project ID, and running total.
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

  <!-- Previous Audits (if any) -->
  <div style="margin-top: 32px;">
    <h3 style="margin: 0 0 16px 0; color: #1f2937;">Recent Audits</h3>
    <div style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); text-align: center; color: #6b7280;">
      <p style="margin: 0;">No previous audits generated yet. Use the form above to create your first audit report.</p>
    </div>
  </div>
</section>
