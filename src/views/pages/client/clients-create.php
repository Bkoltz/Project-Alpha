<?php
// src/views/pages/clients-create.php
require_once __DIR__ . '/../../../config/db.php';
?>
<section>
  <h2>Create Client</h2>
  <?php if (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff3f3;color:#991b1b;border:1px solid #fecaca"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>
  <div id="orgValidationBanner" style="display:none;padding:12px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;margin-bottom:16px;color:#856404">
    <strong>⚠️ Organization doesn't exist yet.</strong> You can create it using the button below.
  </div>
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
          <input type="hidden" id="createOrgCsrf" name="csrf" value="">
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
  
    <script>
    function initializeOrgCreate() {
      const orgInput = document.getElementById('orgInput');
      const orgId = document.getElementById('orgId');
      const orgSuggest = document.getElementById('orgSuggest');
      const createOrgBtn = document.getElementById('createOrgBtn');
      const createOrgModal = document.getElementById('createOrgModal');
      const closeCreateOrgModal = document.getElementById('closeCreateOrgModal');
      const cancelCreateOrgModal = document.getElementById('cancelCreateOrgModal');
      const createOrgForm = document.getElementById('createOrgForm');
      const createOrgCsrf = document.getElementById('createOrgCsrf');
      const createOrgNameInput = document.getElementById('createOrgNameInput');
      const clientForm = document.querySelector('form[action="/?page=clients-create"]');
      const orgValidationBanner = document.getElementById('orgValidationBanner');

      // If elements don't exist yet, retry
      if (!orgInput || !orgSuggest) {
        console.warn('Org create elements not found, retrying in 50ms...');
        setTimeout(initializeOrgCreate, 50);
        return;
      }

      console.log('✓ Org create script initialized');

      // Get CSRF token from meta tag
      function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
      }

      function debounce(fn, ms){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; }

      function clearSuggestions(){ orgSuggest.style.display='none'; orgSuggest.innerHTML=''; }

      async function fetchOrgs(term){
        if (!term) { clearSuggestions(); orgValidationBanner.style.display='none'; return; }
        try{
          const res = await fetch('/?page=org-search&term='+encodeURIComponent(term));
          if (!res.ok) { clearSuggestions(); return; }
          const items = await res.json();
          renderSuggestions(items, term);
        }catch(e){ clearSuggestions(); }
      }

      function renderSuggestions(items, term){
        orgSuggest.innerHTML='';
        if (!items || items.length === 0) { 
          orgSuggest.style.display='none';
          if (term && term.trim().length > 0) {
            orgValidationBanner.style.display='block';
          }
          return; 
        }
        orgValidationBanner.style.display='none';
        items.forEach(it=>{
          const div = document.createElement('div');
          div.textContent = it.name;
          div.style.padding = '8px 10px';
          div.style.cursor = 'pointer';
          div.addEventListener('click', ()=>{
            orgInput.value = it.name;
            orgId.value = it.id;
            clearSuggestions();
            orgValidationBanner.style.display='none';
          });
          orgSuggest.appendChild(div);
        });
        orgSuggest.style.display='block';
      }

      const debouncedFetch = debounce((e)=>{
        orgId.value = '';
        fetchOrgs(e.target.value);
      }, 250);

      orgInput.addEventListener('input', debouncedFetch);

      document.addEventListener('click', function(ev){
        if (!orgSuggest.contains(ev.target) && ev.target !== orgInput) clearSuggestions();
      });

      // Quick-create modal or full-page redirect when no matches
      createOrgBtn.addEventListener('click', function(){
        // Update CSRF token before showing modal
        const token = getCsrfToken();
        createOrgCsrf.value = token;
        createOrgNameInput.value = orgInput.value || '';
        createOrgModal.style.display = 'flex';
        createOrgNameInput.focus();
      });

      closeCreateOrgModal.addEventListener('click', ()=>{ createOrgModal.style.display='none'; });
      cancelCreateOrgModal.addEventListener('click', ()=>{ createOrgModal.style.display='none'; });

      createOrgForm.addEventListener('submit', async function(ev){
        ev.preventDefault();
        // Update CSRF token from meta tag
        const token = getCsrfToken();
        createOrgCsrf.value = token;
        const data = new FormData(createOrgForm);
        try{
          const res = await fetch('/?page=organization/org-create', { method:'POST', body: data });
          const j = await res.json();
          if (j && j.success) {
            // set the org on the client form
            orgInput.value = j.name || createOrgNameInput.value;
            orgId.value = j.id || '';
            createOrgModal.style.display = 'none';
            orgValidationBanner.style.display = 'none';
            // clear any saved draft (we haven't redirected)
            localStorage.removeItem('clientCreateDraft');
          } else {
            alert(j && j.error ? j.error : 'Failed to create organization');
          }
        }catch(e){ alert('Failed to create organization'); }
      });

      function saveDraft(){
        if (!clientForm) return;
        const data = {};
        Array.from(clientForm.elements).forEach(el=>{
          if (!el.name) return;
          if (el.type === 'checkbox' || el.type === 'radio') return;
          data[el.name] = el.value;
        });
        try { localStorage.setItem('clientCreateDraft', JSON.stringify(data)); } catch(e){}
      }

      function restoreDraft(){
        try{
          const raw = localStorage.getItem('clientCreateDraft');
          if (!raw) return;
          const data = JSON.parse(raw);
          if (!clientForm) return;
          Object.keys(data).forEach(k=>{
            const el = clientForm.elements[k];
            if (!el) return;
            el.value = data[k];
          });
        }catch(e){}
      }

      // Restore draft on load and handle return from full create
      function handleDraftRestore(){
        restoreDraft();
        const params = new URLSearchParams(window.location.search);
        if (params.get('org_created') && params.get('org_id')){
          // set org fields from query params
          const id = params.get('org_id');
          const name = params.get('org_name') ? decodeURIComponent(params.get('org_name')) : '';
          if (name) orgInput.value = name;
          if (id) orgId.value = id;
          // clear draft now that it was consumed
          localStorage.removeItem('clientCreateDraft');
          // remove org_* query params from URL
          params.delete('org_created'); params.delete('org_id'); params.delete('org_name');
          const newUrl = window.location.pathname + '?' + params.toString();
          window.history.replaceState({}, document.title, newUrl);
        }
      }

      handleDraftRestore();

      // before navigating away via the client form submission, clear draft
      if (clientForm) {
        clientForm.addEventListener('submit', function(){
          localStorage.removeItem('clientCreateDraft');
        });
      }
    }

    // Initialize with small delay to allow DOM to settle
    setTimeout(initializeOrgCreate, 10);
    
    // Also re-initialize when pages are loaded via AJAX
    document.addEventListener('pageLoaded', ()=>{ setTimeout(initializeOrgCreate, 10); });
    </script>

