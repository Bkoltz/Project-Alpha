<?php
// src/views/pages/organization/organizations-edit.php
require_once __DIR__ . '/../../../config/db.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM organizations WHERE id = ?');
$stmt->execute([$id]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$org) {
    echo '<p>Organization not found.</p>';
    return;
}
?>
<section>
  <h2>Edit Organization</h2>
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff3f3;color:#991b1b;border:1px solid #fecaca"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>
  <form method="post" action="/?page=organization/organizations-update" enctype="multipart/form-data" style="display:grid;gap:12px;max-width:520px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="id" value="<?php echo $id; ?>">
    <label>
      <div>Organization Name</div>
      <input required type="text" name="name" value="<?php echo htmlspecialchars($org['name']); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Notes</div>
      <textarea name="notes" rows="4" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"><?php echo htmlspecialchars($org['notes'] ?? ''); ?></textarea>
    </label>
    <label>
      <div>Tax Exempt Form (PDF, JPG, PNG)</div>
      <?php if (!empty($org['tax_exempt_file'])): ?>
        <div style="margin-bottom:6px">
          Current file: <a href="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['tax_exempt_file']); ?>" target="_blank">View</a>
          <label style="margin-left:12px;font-size:small"><input type="checkbox" name="remove_tax_file" value="1"> Remove file</label>
        </div>
      <?php endif; ?>
      <input type="file" name="tax_exempt_file" accept="application/pdf,image/jpeg,image/png">
      <div style="font-size:small;color:var(--muted);margin-top:4px">Upload a PDF, JPG, or PNG to attach a tax-exempt document to this organization.</div>
    </label>
    <div style="display:flex;gap:8px">
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Save Changes</button>
      <a href="/?page=organization/organization-view&id=<?php echo $id; ?>" style="padding:10px 14px;border-radius:8px;border:1px solid #ddd;background:#fff;text-decoration:none">Cancel</a>
      <button type="button" id="deleteOrgBtn" style="padding:10px 14px;border-radius:8px;border:0;background:#fee2e2;color:#991b1b;cursor:pointer">Delete Organization</button>
    </div>
  </form>

  <?php
  // Include links section
  $entityType = 'organization';
  $entityId = (int)$org['id'];
  include __DIR__ . '/../../components/links_section.php';
  ?>
</section>

<!-- Delete organization form (outside main form to avoid nesting) -->
<form id="deleteOrgForm" method="post" action="/?page=organization/organizations-delete" style="display:none">
  <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
  <input type="hidden" name="id" value="<?php echo $id; ?>">
</form>

<script>
  (function() {
    function initDeleteButton() {
      const btn = document.getElementById('deleteOrgBtn');
      const form = document.getElementById('deleteOrgForm');
      
      if (!btn || !form) {
        console.warn('Delete button or form not found, retrying...');
        setTimeout(initDeleteButton, 50);
        return;
      }
      
      // Check if already initialized
      if (btn.dataset.deleteInitialized === 'true') {
        console.log('✓ Delete button already initialized');
        return;
      }
      
      // Mark as initialized FIRST before attaching listener
      btn.dataset.deleteInitialized = 'true';
      
      // Attach listener without cloning
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        if (confirm('Delete this organization? Clients will not be deleted, but will no longer be associated with this organization.')) {
          console.log('Submitting delete form');
          form.submit();
        }
      });
      
      console.log('✓ Delete button initialized');
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initDeleteButton);
    } else {
      initDeleteButton();
    }
    
    // Re-initialize on AJAX navigation - just call initDeleteButton without resetting flag
    // The guard check in initDeleteButton will handle whether we need to re-initialize
    document.addEventListener('pageLoaded', function() {
      console.log('pageLoaded: checking delete button');
      setTimeout(initDeleteButton, 50);
    });
  })();
</script>
