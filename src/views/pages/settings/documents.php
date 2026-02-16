<?php
// src/views/pages/settings/documents.php
// Sub-tab for documents - default to quotes
$docTab = isset($_GET['doc_tab']) ? preg_replace('/[^a-z]/i', '', $_GET['doc_tab']) : 'quotes';
?>

<div style="display:flex;gap:12px;margin-bottom:16px;border-bottom:2px solid #e5e7eb">
  <a href="/?page=settings&tab=documents&doc_tab=quotes" data-skip-nav style="padding:10px 16px;font-weight:<?php echo $docTab === 'quotes' ? '600' : '400'; ?>;color:<?php echo $docTab === 'quotes' ? 'var(--nav-accent)' : '#6b7280'; ?>;border-bottom:<?php echo $docTab === 'quotes' ? '2px solid var(--nav-accent)' : '2px solid transparent'; ?>;margin-bottom:-2px;text-decoration:none">Quotes</a>
  <a href="/?page=settings&tab=documents&doc_tab=contracts" data-skip-nav style="padding:10px 16px;font-weight:<?php echo $docTab === 'contracts' ? '600' : '400'; ?>;color:<?php echo $docTab === 'contracts' ? 'var(--nav-accent)' : '#6b7280'; ?>;border-bottom:<?php echo $docTab === 'contracts' ? '2px solid var(--nav-accent)' : '2px solid transparent'; ?>;margin-bottom:-2px;text-decoration:none">Contracts</a>
  <a href="/?page=settings&tab=documents&doc_tab=invoices" data-skip-nav style="padding:10px 16px;font-weight:<?php echo $docTab === 'invoices' ? '600' : '400'; ?>;color:<?php echo $docTab === 'invoices' ? 'var(--nav-accent)' : '#6b7280'; ?>;border-bottom:<?php echo $docTab === 'invoices' ? '2px solid var(--nav-accent)' : '2px solid transparent'; ?>;margin-bottom:-2px;text-decoration:none">Invoices</a>
  <a href="/?page=settings&tab=documents&doc_tab=customization" data-skip-nav style="padding:10px 16px;font-weight:<?php echo $docTab === 'customization' ? '600' : '400'; ?>;color:<?php echo $docTab === 'customization' ? 'var(--nav-accent)' : '#6b7280'; ?>;border-bottom:<?php echo $docTab === 'customization' ? '2px solid var(--nav-accent)' : '2px solid transparent'; ?>;margin-bottom:-2px;text-decoration:none">Customization</a>
</div>

<?php if ($docTab !== 'customization'): ?>
<form method="post" action="/?page=settings&tab=documents&doc_tab=<?php echo htmlspecialchars($docTab); ?>" enctype="multipart/form-data" style="display:grid;gap:16px;max-width:800px">
  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
  <input type="hidden" name="tab" value="documents">
<?php endif; ?>

<?php if ($docTab === 'quotes'): ?>
  <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
    <legend style="padding:0 6px;color:var(--muted)">Quote Options</legend>
    <div style="display:grid;gap:12px">
      <label>
        <input type="checkbox" name="quote_scope_enabled" value="1" <?php echo !empty($appConfig['quote_scope_enabled']) ? 'checked' : ''; ?>>
        <span style="font-weight:600">Enable "Scope of Project" field on quotes</span>
        <div style="margin-top:4px;color:var(--muted);font-size:12px">If enabled, quotes will have a scope field. If left blank, it will be excluded from PDF.</div>
      </label>
      <label style="display:block;margin-top:4px">
        <input type="checkbox" name="quotes_show_terms" value="1" <?php echo (!isset($appConfig['quotes_show_terms']) || (int)($appConfig['quotes_show_terms']) === 1) ? 'checked' : ''; ?>>
        <span style="font-weight:600">Show terms on Quotes</span>
        <div style="margin-top:4px;color:var(--muted);font-size:12px">When enabled, the standard terms will be included on quote PDFs.</div>
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
    <legend style="padding:0 6px;color:var(--muted)">Invoice Options</legend>
    <p style="margin:0;padding:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:13px">
      ⚙️ <strong>Invoice automation and notifications</strong> have been moved to the <a href="/?page=settings&tab=notifications" style="color:var(--nav-accent);font-weight:600">Notifications</a> tab for easier management.
    </p>
  </fieldset>
<?php endif; ?>

<?php if ($docTab !== 'customization'): ?>
  <div>
    <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Save</button>
  </div>
</form>
<?php endif; ?>

<?php if ($docTab === 'customization'): ?>
  <?php include __DIR__ . '/documents/customization.php'; ?>
<?php endif; ?>
