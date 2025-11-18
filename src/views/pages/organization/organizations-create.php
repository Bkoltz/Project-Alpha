<?php
// src/views/pages/organization/organizations-create.php
?>
<section>
  <h2>Create Organization</h2>
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff3f3;color:#991b1b;border:1px solid #fecaca"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>
  <form method="post" action="/?page=organization/organizations-create" style="display:grid;gap:12px;max-width:520px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <label>
      <div>Organization Name</div>
      <input required type="text" name="name" placeholder="Organization name" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Notes</div>
      <textarea name="notes" rows="4" placeholder="Internal notes about this organization" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></textarea>
    </label>
    <div style="display:flex;gap:8px">
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Create Organization</button>
      <a href="/?page=organization/organizations-list" style="padding:10px 14px;border-radius:8px;border:1px solid #ddd;background:#fff;text-decoration:none">Cancel</a>
    </div>
  </form>
</section>
