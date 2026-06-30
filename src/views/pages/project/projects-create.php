<?php
// src/views/pages/project/projects-create.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/acl.php';

$userId = (int)($_SESSION['user']['id'] ?? 0);
$activeOrgId = get_active_org_id();
$isAdmin = (($_SESSION['user']['role'] ?? '') === 'admin');
if ($isAdmin) {
    $clients = $pdo->query('SELECT id, name, email FROM clients WHERE archived = 0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
} elseif ($activeOrgId > 0) {
    if (acl_user_has_org_wide_scope($pdo, $userId, $activeOrgId)) {
        $stmt = $pdo->prepare('
            SELECT id, name, email
            FROM clients
            WHERE organization_id = ? AND archived = 0
            ORDER BY name
        ');
        $stmt->execute([$activeOrgId]);
    } else {
        $stmt = $pdo->prepare('
            SELECT id, name, email
            FROM clients
            WHERE organization_id = ? AND created_by = ? AND archived = 0
            ORDER BY name
        ');
        $stmt->execute([$activeOrgId, $userId]);
    }
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare('
        SELECT id, name, email
        FROM clients
        WHERE organization_id IS NULL AND created_by = ? AND archived = 0
        ORDER BY name
    ');
    $stmt->execute([$userId]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Organizations are selected via search in the form; no need to prefetch list.
// no parent projects in this view — no need to fetch projects list

$departments = [];
if ($activeOrgId > 0) {
    $deptStmt = $pdo->prepare('
        SELECT id, name, folder_name
        FROM organization_departments
        WHERE organization_id = ?
        ORDER BY name
    ');
    $deptStmt->execute([$activeOrgId]);
    $departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
<section>
  <h2>Create Project</h2>
  <h3 id="projectNamePreview" style="margin-top:8px;margin-bottom:16px;color:#333;font-size:18px"></h3>
  <form method="post" action="/?page=project/projects-create" style="display:grid;gap:12px;max-width:680px">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    
    <label>
      <div>Project Name</div>
      <input id="projectNameInput" type="text" name="name" required placeholder="Project name" style="padding:8px;border-radius:8px;border:1px solid #ddd">
    </label>

    <label style="position:relative">
      <div>Organization</div>
      <input id="orgInputProject" type="text" name="organization_search" placeholder="Search organization..." autocomplete="off" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
      <input type="hidden" name="organization_id" id="organization_id_create" value="">
      <div id="orgSuggestProject" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:220px;overflow:auto"></div>
    </label>

    <label>
      <div>Department</div>
      <select id="projectDepartmentSelect" name="department_id" data-empty-label="No department / org-level project" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
        <option value="">No department / org-level project</option>
        <?php foreach ($departments as $department): ?>
          <option value="<?php echo (int)$department['id']; ?>">
            <?php echo htmlspecialchars($department['name'] . (!empty($department['folder_name']) ? ' — ' . $department['folder_name'] : '')); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div style="font-size:12px;color:var(--muted);margin-top:4px">
        Choose a department only when this project belongs to a specific group within the organization. Leave blank for organization-level work.
      </div>
    </label>

     <label style="grid-column:1/2;position:relative">
        <div>Client</div>
        <input id="clientInput" type="text" placeholder="Type client name..." autocomplete="off" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd">
        <input id="clientId" type="hidden" name="client_id">
        <div id="clientSuggest" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:200px;overflow:auto"></div>
      </label>

    <label>
      <div>Additional Project Clients</div>
      <select name="project_client_ids[]" multiple size="5" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
        <?php foreach ($clients as $client): ?>
          <option value="<?php echo (int)$client['id']; ?>"><?php echo htmlspecialchars($client['name'] . (!empty($client['email']) ? ' - ' . $client['email'] : '')); ?></option>
        <?php endforeach; ?>
      </select>
      <div style="font-size:12px;color:var(--muted);margin-top:4px">The primary client above is included automatically. This project will not automatically include every contact in the organization.</div>
    </label>

    <label>
      <div>Project Invoice Email Recipients</div>
      <select name="project_invoice_email_client_ids[]" multiple size="5" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
        <?php foreach ($clients as $client): ?>
          <option value="<?php echo (int)$client['id']; ?>" selected><?php echo htmlspecialchars($client['name'] . (!empty($client['email']) ? ' - ' . $client['email'] : ' - no email')); ?></option>
        <?php endforeach; ?>
      </select>
      <div style="font-size:12px;color:var(--muted);margin-top:4px">Only attached project contacts with email addresses can receive project invoice emails. You can fine-tune recipients on the project details page.</div>
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

    <div style="padding:12px;border:1px solid #dbeafe;border-radius:8px;background:#eff6ff">
      <div style="font-weight:600;margin-bottom:8px">Project Invoice Billing</div>
      <label style="display:block;margin-bottom:8px">
        <div>Billing Period</div>
        <select name="invoice_billing_period" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
          <option value="monthly" selected>Monthly project billing</option>
          <option value="per_invoice">Each invoice is due on its own terms</option>
        </select>
      </label>
      <label>
        <div>Project NET Days</div>
        <input type="number" name="invoice_net_terms_days" min="0" step="1" placeholder="Use system default" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
      </label>
      <label style="display:flex;gap:8px;align-items:flex-start;margin-top:10px">
        <input type="checkbox" name="project_invoice_auto_email" value="1" checked style="margin-top:3px">
        <span>
          <span style="font-weight:600">Auto-email monthly project invoices</span>
          <span style="display:block;font-size:12px;color:var(--muted)">Cron-generated project invoices are emailed to the selected project invoice recipients.</span>
        </span>
      </label>
      <div style="font-size:13px;color:#4b5563;margin-top:8px">Monthly project billing sets invoice due dates from the end of the work month plus NET days.</div>
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
<script src="/assets/js/project-form.js" defer></script>
