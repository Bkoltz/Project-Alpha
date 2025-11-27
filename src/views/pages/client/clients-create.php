<?php
// src/views/pages/clients-create.php
require_once __DIR__ . '/../../../config/db.php';
// TODO: Issues creating a org from the client create page. "Create" button doesn't work.
?>
<section>
  <h2>Create Client</h2>
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff3f3;color:#991b1b;border:1px solid #fecaca"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>
  <form method="post" action="/?page=clients-create" style="display:grid;gap:12px;max-width:520px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <label>
      <div>Name</div>
      <input required type="text" name="name" placeholder="First Last" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Email</div>
      <input type="email" name="email" placeholder="email@example.com" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Phone</div>
      <input type="text" name="phone" placeholder="(555) 123-4567" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label style="position:relative">
      <div>Organization</div>
      <input type="text" id="orgInput" placeholder="Type to search organizations..." autocomplete="off" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
      <input type="hidden" id="orgId" name="organization_id" value="">
      <div id="orgSuggest" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #ddd;border-radius:8px;display:none;max-height:200px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1)"></div>
      <button type="button" id="createOrgBtn" style="margin-top:8px;padding:8px 12px;background:#f0f0f0;border:1px solid #ddd;border-radius:8px;cursor:pointer;font-size:14px">
        + Create New Organization
      </button>
    </label>
    

    <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
      <legend style="padding:0 6px;color:var(--muted)">Address</legend>
      <label><div>Address line 1</div><input name="address_line1" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
      <label><div>Address line 2</div><input name="address_line2" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
      <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr 1fr">
        <label><div>City</div><input name="city" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
        <label><div>State</div><input name="state" value="<?php echo htmlspecialchars($appConfig['primary_state'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
        <label><div>Postal (zip)</div><input name="postal" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
      </div>
    </fieldset>
    <label>
      <div>Notes</div>
      <textarea name="notes" rows="3" placeholder="Internal notes" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></textarea>
    </label>
    <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600">Create</button>
  </form>
    <!-- Create Organization Modal (moved outside the main form to avoid nesting) -->
    <div id="createOrgModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:100;align-items:center;justify-content:center;flex-direction:column;-webkit-align-items:center;-webkit-justify-content:center">
      <div style="background:#fff;padding:24px;border-radius:12px;max-width:400px;box-shadow:0 20px 25px rgba(0,0,0,0.15)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
          <h3 style="margin:0;font-size:18px">Create New Organization</h3>
          <button type="button" id="closeCreateOrgModal" style="background:none;border:none;font-size:24px;cursor:pointer;color:#999">&times;</button>
        </div>
        <form id="createOrgForm" style="display:grid;gap:12px">
          <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
          <label>
            <div style="font-weight:500;margin-bottom:4px">Organization Name</div>
            <input type="text" id="createOrgNameInput" name="name" required placeholder="Organization name" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
          </label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            <button type="button" id="cancelCreateOrgModal" style="padding:10px 14px;border-radius:8px;border:1px solid #ddd;background:#fff;cursor:pointer">Cancel</button>
            <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;cursor:pointer">Create</button>
          </div>
        </form>
      </div>
    </div>
  
