<?php
// src/views/pages/clients-edit.php
require_once __DIR__ . '/../../../config/db.php';
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT c.*, o.name AS organization_name FROM clients c LEFT JOIN organizations o ON o.id = c.organization_id WHERE c.id=?');
$st->execute([$id]);
$client = $st->fetch(PDO::FETCH_ASSOC);
if (!$client) { echo '<p>Client not found.</p>'; return; }

// Fetch all organizations for dropdown
$orgStmt = $pdo->query('SELECT id, name FROM organizations ORDER BY name ASC');
$organizations = $orgStmt->fetchAll();
?>
<section>
  <h2>Edit Client</h2>
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff3f3;color:#991b1b;border:1px solid #fecaca"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>
  <form method="post" action="/?page=clients-update" style="display:grid;gap:12px;max-width:520px">
    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="id" value="<?php echo (int)$client['id']; ?>">
    <label>
      <div>Name</div>
      <input required type="text" name="name" value="<?php echo htmlspecialchars($client['name']); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Email</div>
      <input type="email" name="email" value="<?php echo htmlspecialchars($client['email'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label>
      <div>Phone</div>
      <input type="text" name="phone" value="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
    </label>
    <label style="position:relative">
      <div>Organization</div>
      <input type="text" id="orgInputEdit" placeholder="Type to search organizations (leave blank for none)..." autocomplete="off" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd" value="<?php echo htmlspecialchars($client['organization_name'] ?? ''); ?>">
      <input type="hidden" id="orgIdEdit" name="organization_id" value="<?php echo (int)($client['organization_id'] ?? 0); ?>">
      <div id="orgSuggestEdit" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #ddd;border-radius:8px;display:none;max-height:200px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1)"></div>
      <div style="font-size:small;color:var(--muted);margin-top:4px">You can <a href="/?page=organization/organizations-create" target="_blank">create a new organization</a> if needed.</div>
    </label>
    <fieldset style="border:1px solid #eee;border-radius:8px;padding:12px">
      <legend style="padding:0 6px;color:var(--muted)">Address</legend>
      <label><div>Address line 1</div><input name="address_line1" value="<?php echo htmlspecialchars($client['address_line1'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
      <label><div>Address line 2</div><input name="address_line2" value="<?php echo htmlspecialchars($client['address_line2'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
      <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr 1fr">
        <label><div>City</div><input name="city" value="<?php echo htmlspecialchars($client['city'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
        <label><div>State</div><input name="state" value="<?php echo htmlspecialchars($client['state'] ?? 'WI'); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
        <label><div>Postal (zip)</div><input name="postal" value="<?php echo htmlspecialchars($client['postal'] ?? ''); ?>" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></label>
      </div>
    </fieldset>
    <label>
      <div>Notes</div>
      <textarea name="notes" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"><?php echo htmlspecialchars($client['notes'] ?? ''); ?></textarea>
    </label>
    <div style="display:flex;gap:8px">
      <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:var(--nav-accent);color:#fff;font-weight:600;font-size:small">Save</button>
      <a href="/?page=client/clients-list" style="padding:10px 14px;border-radius:8px;border:1px solid #ddd;background:#fff;font-size:small">Cancel</a>
      <form method="post" action="/?page=clients-delete" onsubmit="return confirm('Archive this client and all associated documents? This will remove them from active lists.');" style="display:inline-block;margin-left:auto">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$client['id']; ?>">
        <button type="submit" style="padding:10px 14px;border-radius:8px;border:0;background:#fee2e2;color:#991b1b;font-size:small">Archive Client</button>
      </form>
      <form method="post" action="/?page=clients-purge" onsubmit="return confirm('PERMANENTLY delete this client and ALL related quotes, contracts, invoices, and payments? This cannot be undone.');" style="display:inline-block;margin-left:8px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$client['id']; ?>">
      </form>
    </div>
  </form>
</section>

<script>
  (function(){
    const orgInput = document.getElementById('orgInputEdit');
    const orgId = document.getElementById('orgIdEdit');
    const orgSuggest = document.getElementById('orgSuggestEdit');
    function debounce(fn, ms){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; }
    function clearSuggestions(){ orgSuggest.style.display='none'; orgSuggest.innerHTML=''; }
    async function fetchOrgs(term){
      if (!term) { clearSuggestions(); return; }
      try{
        const res = await fetch('/?page=org-search&term='+encodeURIComponent(term));
        if (!res.ok) { clearSuggestions(); return; }
        const items = await res.json();
        renderSuggestions(items);
      }catch(e){ clearSuggestions(); }
    }
    function renderSuggestions(items){
      orgSuggest.innerHTML='';
      if (!items || items.length === 0) { orgSuggest.style.display='none'; return; }
      items.forEach(it=>{
        const div = document.createElement('div');
        div.textContent = it.name;
        div.style.padding = '8px 10px';
        div.style.cursor = 'pointer';
        div.addEventListener('click', ()=>{
          orgInput.value = it.name;
          orgId.value = it.id;
          clearSuggestions();
        });
        orgSuggest.appendChild(div);
      });
      orgSuggest.style.display='block';
    }
    const debouncedFetch = debounce((e)=>{ orgId.value=''; fetchOrgs(e.target.value); }, 200);
    orgInput.addEventListener('input', debouncedFetch);
    document.addEventListener('click', function(ev){ if (!orgSuggest.contains(ev.target) && ev.target !== orgInput) clearSuggestions(); });
  })();
</script>
