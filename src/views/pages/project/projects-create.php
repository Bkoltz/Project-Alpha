<?php
// src/views/pages/project/projects-create.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

// Organizations are selected via search in the form; no need to prefetch list.
// no parent projects in this view — no need to fetch projects list

?>
<section>
  <h2>Create Project</h2>
  <h3 id="projectNamePreview" style="margin-top:8px;margin-bottom:16px;color:#333;font-size:18px"></h3>
  <form method="post" action="/?page=project/projects-create" style="display:grid;gap:12px;max-width:680px">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf'] ?? ''); ?>">
    
    <label>
      <div>Project Name</div>
      <input id="projectNameInput" type="text" name="name" required placeholder="Project name" style="padding:8px;border-radius:8px;border:1px solid #ddd">
    </label>

    <label>
      <div>Organization</div>
      <input id="orgInputProject" type="text" name="organization_search" placeholder="Search organization..." autocomplete="off" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
      <input type="hidden" name="organization_id" id="organization_id_create" value="">
      <div id="orgSuggestProject" style="position:relative;z-index:60;left:0;right:0;top:0;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:220px;overflow:auto"></div>
    </label>

     <label style="grid-column:1/2;position:relative">
        <div>Client</div>
        <input id="clientInput" type="text" placeholder="Type client name..." autocomplete="off" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <input id="clientId" type="hidden" name="client_id">
        <div id="clientSuggest" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
      </label>

    <!-- Parent projects removed per spec: Projects have no parents -->

    <div style="display:flex;gap:8px">
      <label style="flex:1">
        <div>Estimated Start</div>
        <input type="date" name="estimated_start" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
      </label>
      <label style="flex:1">
        <div>Estimated End</div>
        <input type="date" name="estimated_end" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
      </label>
    </div>

    <label>
      <div>Notes</div>
      <textarea name="notes" rows="5" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%" placeholder="Optional project notes"></textarea>
    </label>

    <div>
      <button type="submit" style="padding:8px 12px;border-radius:8px;background:var(--nav-accent);border:0;color:#fff">Create Project</button>
      <a href="/?page=project/projects-list" style="padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff;margin-left:8px;">Cancel</a>
    </div>
  </form>
</section>

<script src="/assets/js/client-selection-dropdown-logic.js" defer></script>
