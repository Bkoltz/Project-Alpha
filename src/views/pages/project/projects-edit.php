<?php
// src/views/pages/project/projects-edit.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';
require_once __DIR__ . '/../../../utils/acl.php';
require_once __DIR__ . '/../../../utils/project_invoice_billing.php';

$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0) {
    header('Location: /?page=project/projects-list');
    exit;
}
require_record_ownership($pdo, 'projects', $projectId);

$stmt = $pdo->prepare('
    SELECT p.*, c.name AS client_name, o.name AS organization_name, od.name AS department_name
    FROM projects p
    LEFT JOIN clients c ON c.id = p.client_id
    LEFT JOIN organizations o ON o.id = p.organization_id
    LEFT JOIN organization_departments od ON od.id = p.department_id
    WHERE p.id = ?
');
$stmt->execute([$projectId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) {
    header('Location: /?page=project/projects-list');
    exit;
}

$projectOrganizationId = (int)($project['organization_id'] ?? 0);
$projectDepartments = [];
if ($projectOrganizationId > 0) {
    $deptStmt = $pdo->prepare('
        SELECT id, name, folder_name
        FROM organization_departments
        WHERE organization_id = ?
        ORDER BY name
    ');
    $deptStmt->execute([$projectOrganizationId]);
    $projectDepartments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
}

$projectClientsSendSelect = project_invoice_table_has_column($pdo, 'project_clients', 'send_project_invoices')
    ? 'pc.send_project_invoices'
    : '1 AS send_project_invoices';
$projectClientsLinkSelect = project_invoice_table_has_column($pdo, 'project_clients', 'can_view_invoice_links')
    ? 'pc.can_view_invoice_links'
    : '1 AS can_view_invoice_links';
$stmt = $pdo->prepare("
    SELECT c.id, c.name, c.email, pc.is_primary_billing, {$projectClientsSendSelect}, {$projectClientsLinkSelect}
    FROM project_clients pc
    JOIN clients c ON c.id = pc.client_id
    WHERE pc.project_id = ?
    ORDER BY pc.is_primary_billing DESC, pc.sort_order ASC, c.name ASC
");
$stmt->execute([$projectId]);
$projectClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($projectOrganizationId > 0) {
    $allClientsStmt = $pdo->prepare('
        SELECT id, name, email
        FROM clients
        WHERE organization_id = ? AND archived = 0
        ORDER BY name
    ');
    $allClientsStmt->execute([$projectOrganizationId]);
    $allClients = $allClientsStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $activeOrgId = get_active_org_id();
    if ($activeOrgId > 0) {
        $allClientsStmt = $pdo->prepare('
            SELECT id, name, email
            FROM clients
            WHERE organization_id = ? AND archived = 0
            ORDER BY name
        ');
        $allClientsStmt->execute([$activeOrgId]);
        $allClients = $allClientsStmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif (($_SESSION['user']['role'] ?? '') === 'admin') {
        $allClients = $pdo->query('SELECT id, name, email FROM clients WHERE archived = 0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $allClientsStmt = $pdo->prepare('
            SELECT id, name, email
            FROM clients
            WHERE organization_id IS NULL AND created_by = ? AND archived = 0
            ORDER BY name
        ');
        $allClientsStmt->execute([(int)($_SESSION['user']['id'] ?? 0)]);
        $allClients = $allClientsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$selectedProjectClientIds = array_map(static fn($row) => (int)$row['id'], $projectClients);
$selectedProjectInvoiceRecipientIds = array_map(
    static fn($row) => (int)$row['id'],
    array_values(array_filter($projectClients, static fn($row) => !empty($row['send_project_invoices'])))
);
$selectedProjectInvoiceLinkClientIds = array_map(
    static fn($row) => (int)$row['id'],
    array_values(array_filter($projectClients, static fn($row) => !empty($row['can_view_invoice_links'])))
);

$projectDepartmentContactIds = [];
$projectDepartmentPrimaryContactIds = [];
if (!empty($project['department_id'])) {
    $deptContactStmt = $pdo->prepare('SELECT client_id, is_primary FROM organization_department_contacts WHERE department_id = ?');
    $deptContactStmt->execute([(int)$project['department_id']]);
    foreach ($deptContactStmt->fetchAll(PDO::FETCH_ASSOC) as $deptContact) {
        $clientId = (int)$deptContact['client_id'];
        $projectDepartmentContactIds[] = $clientId;
        if (!empty($deptContact['is_primary'])) {
            $projectDepartmentPrimaryContactIds[] = $clientId;
        }
    }
}

$projectSettingsClients = [];
$projectSettingsClientIds = [];
foreach ($allClients as $client) {
    $clientId = (int)$client['id'];
    $projectSettingsClientIds[$clientId] = true;
    $projectSettingsClients[] = [
        'id' => $clientId,
        'name' => (string)($client['name'] ?? ''),
        'email' => (string)($client['email'] ?? ''),
        'is_selected' => in_array($clientId, $selectedProjectClientIds, true) ? 1 : 0,
        'is_invoice_recipient' => in_array($clientId, $selectedProjectInvoiceRecipientIds, true) ? 1 : 0,
        'can_view_links' => in_array($clientId, $selectedProjectInvoiceLinkClientIds, true) ? 1 : 0,
        'is_primary' => (int)($project['client_id'] ?? 0) === $clientId ? 1 : 0,
        'is_department_contact' => in_array($clientId, $projectDepartmentContactIds, true) ? 1 : 0,
        'is_primary_department_contact' => in_array($clientId, $projectDepartmentPrimaryContactIds, true) ? 1 : 0,
    ];
}
foreach ($projectClients as $client) {
    $clientId = (int)$client['id'];
    if (isset($projectSettingsClientIds[$clientId])) {
        continue;
    }
    $projectSettingsClients[] = [
        'id' => $clientId,
        'name' => (string)($client['name'] ?? ''),
        'email' => (string)($client['email'] ?? ''),
        'is_selected' => 1,
        'is_invoice_recipient' => in_array($clientId, $selectedProjectInvoiceRecipientIds, true) ? 1 : 0,
        'can_view_links' => in_array($clientId, $selectedProjectInvoiceLinkClientIds, true) ? 1 : 0,
        'is_primary' => (int)($project['client_id'] ?? 0) === $clientId ? 1 : 0,
        'is_department_contact' => in_array($clientId, $projectDepartmentContactIds, true) ? 1 : 0,
        'is_primary_department_contact' => in_array($clientId, $projectDepartmentPrimaryContactIds, true) ? 1 : 0,
    ];
}

$autoEmailEnabled = !array_key_exists('project_invoice_auto_email', $project) || !empty($project['project_invoice_auto_email']);
?>

<style>
.project-edit-page{max-width:1120px;margin:0 auto;padding:24px}.project-edit-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}.project-edit-header h1{margin:0;font-size:26px}.project-edit-subtitle{margin-top:4px;color:var(--muted);font-size:13px}.project-edit-layout{display:grid;grid-template-columns:260px minmax(0,1fr);gap:18px;align-items:start}.project-edit-nav{position:sticky;top:16px;display:grid;gap:8px;border:1px solid #dfe3e8;border-radius:8px;background:#fff;padding:10px}.project-edit-nav a{padding:9px 10px;border-radius:6px;text-decoration:none;color:#111827;font-weight:650;font-size:13px}.project-edit-nav a:hover{background:#f3f4f6}.project-edit-form{display:grid;gap:16px}.project-edit-section{border:1px solid #dfe3e8;border-radius:8px;background:#fff;padding:16px;display:grid;gap:12px}.project-edit-section h2{margin:0;font-size:17px}.project-edit-section p{margin:0;color:var(--muted);font-size:13px;line-height:1.45}.project-edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.project-field{display:grid;gap:5px}.project-field>span,.project-field>div:first-child{font-size:13px;font-weight:700}.project-field input,.project-field select,.project-field textarea{width:100%;padding:9px 10px;border:1px solid #cfd5dc;border-radius:6px;background:#fff}.project-field small{color:var(--muted);font-size:12px;line-height:1.4}.project-check{display:flex;align-items:flex-start;gap:8px;font-size:13px}.project-check input{width:auto;margin-top:2px}.project-info-box{padding:10px 12px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;color:#1e3a8a;font-size:12px;line-height:1.45}.project-pill{display:inline-flex;align-items:center;border-radius:999px;padding:2px 7px;font-size:11px;font-weight:700;background:#dbeafe;color:#1d4ed8;white-space:nowrap}.project-pill--primary{background:#dcfce7;color:#166534}.project-settings-picker{display:grid;gap:8px}.project-settings-picker__selected{display:grid;gap:8px;min-height:42px}.project-settings-picker__empty{padding:10px;border:1px dashed #d1d5db;border-radius:8px;color:var(--muted);background:#fff;font-size:13px}.project-settings-picker__item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px;border:1px solid #dfe3e8;border-radius:8px;background:#fff}.project-settings-picker__name{font-weight:700}.project-settings-picker__meta{display:block;color:var(--muted);font-size:12px;margin-top:2px}.project-settings-picker__remove{border:0;background:#f3f4f6;color:#111827;border-radius:999px;width:28px;height:28px;cursor:pointer;font-weight:800}.project-settings-picker__search{position:relative}.project-settings-picker__suggestions{position:absolute;left:0;right:0;top:100%;z-index:40;display:none;max-height:210px;overflow:auto;border:1px solid #dfe3e8;border-radius:8px;background:#fff;box-shadow:0 12px 24px rgba(15,23,42,.12)}.project-settings-picker__suggestion{padding:9px 10px;border-bottom:1px solid #eef2f7;cursor:pointer}.project-settings-picker__suggestion:hover{background:#f8fafc}.project-edit-actions{position:sticky;bottom:12px;z-index:5;display:flex;justify-content:flex-end;gap:8px;padding:12px;border:1px solid #dfe3e8;border-radius:8px;background:rgba(255,255,255,.96);box-shadow:0 10px 24px rgba(15,23,42,.12)}@media(max-width:900px){.project-edit-layout{grid-template-columns:1fr}.project-edit-nav{position:static;display:flex;flex-wrap:wrap}.project-edit-grid{grid-template-columns:1fr}}@media(max-width:640px){.project-edit-page{padding:16px}.project-edit-header{display:grid}.project-edit-actions{position:static;display:grid}}
</style>

<div class="project-edit-page">
  <div class="project-edit-header">
    <div>
      <a href="/?page=project/projects-details&amp;id=<?php echo $projectId; ?>" style="color:var(--nav-accent);text-decoration:none;font-size:14px">Back to Project</a>
      <h1>Edit Project</h1>
      <div class="project-edit-subtitle"><?php echo htmlspecialchars((string)$project['name']); ?></div>
    </div>
  </div>

  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger" style="margin-bottom:14px"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
  <?php endif; ?>

  <div class="project-edit-layout">
    <nav class="project-edit-nav" aria-label="Project edit sections">
      <a href="#project-basics">Basics</a>
      <a href="#project-contacts">Contacts</a>
      <a href="#project-billing">Invoice Defaults</a>
      <a href="#project-schedule">Schedule &amp; Notes</a>
    </nav>

    <form method="post" action="/?page=project/projects-update" class="project-edit-form">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="id" value="<?php echo $projectId; ?>">
      <input type="hidden" name="organization_id" value="<?php echo (int)($project['organization_id'] ?? 0); ?>">

      <section id="project-basics" class="project-edit-section">
        <h2>Basics</h2>
        <p>Set the project name and its organization or department context.</p>
        <div class="project-edit-grid">
          <label class="project-field">
            <span>Project Name</span>
            <input name="name" required value="<?php echo htmlspecialchars((string)$project['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          </label>
          <label class="project-field">
            <span>Organization</span>
            <input value="<?php echo htmlspecialchars((string)($project['organization_name'] ?? 'No organization'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" disabled>
          </label>
        </div>
        <label class="project-field">
          <span>Department</span>
          <select name="department_id">
            <option value="">No department / org-level project</option>
            <?php foreach ($projectDepartments as $department): ?>
              <?php $departmentId = (int)$department['id']; ?>
              <option value="<?php echo $departmentId; ?>" <?php echo (int)($project['department_id'] ?? 0) === $departmentId ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($department['name'] . (!empty($department['folder_name']) ? ' - ' . $department['folder_name'] : ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small>Department selection controls department link inheritance for project invoices.</small>
        </label>
      </section>

      <section id="project-contacts" class="project-edit-section" data-project-settings-contact-manager>
        <h2>Contacts</h2>
        <p>Use search boxes to add clients. Remove any row with the x. Invoice recipients and content-link viewers remain attached to the project automatically.</p>
        <script type="application/json" data-project-settings-clients><?php echo json_encode($projectSettingsClients, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?></script>
        <div class="project-info-box">Primary department contacts are marked when the current department has one saved on the organization.</div>
        <label class="project-field">
          <span>Primary invoice receiver</span>
          <select name="client_id" data-project-primary-select></select>
          <small>This client appears as the primary billed contact for generated project invoices.</small>
        </label>
        <div class="project-edit-grid">
          <div class="project-settings-picker" data-project-settings-picker="project" data-empty-text="No project contacts selected.">
            <div class="label">Project contacts</div>
            <div class="project-settings-picker__selected" data-picker-selected></div>
            <div class="project-settings-picker__search">
              <input type="text" class="input" data-picker-search placeholder="Type a client name or email..." autocomplete="off">
              <div class="project-settings-picker__suggestions" data-picker-suggestions></div>
            </div>
            <div data-picker-hidden></div>
          </div>
          <div class="project-settings-picker" data-project-settings-picker="invoice" data-empty-text="No invoice email recipients selected.">
            <div class="label">Project invoice email recipients</div>
            <div class="project-settings-picker__selected" data-picker-selected></div>
            <div class="project-settings-picker__search">
              <input type="text" class="input" data-picker-search placeholder="Type a client name or email..." autocomplete="off">
              <div class="project-settings-picker__suggestions" data-picker-suggestions></div>
            </div>
            <div data-picker-hidden></div>
          </div>
        </div>
        <div class="project-settings-picker" data-project-settings-picker="links" data-empty-text="No invoice content-link viewers selected.">
          <div class="label">Invoice content-link viewers</div>
          <div class="project-settings-picker__selected" data-picker-selected></div>
          <div class="project-settings-picker__search">
            <input type="text" class="input" data-picker-search placeholder="Type a client name or email..." autocomplete="off">
            <div class="project-settings-picker__suggestions" data-picker-suggestions></div>
          </div>
          <div data-picker-hidden></div>
        </div>
      </section>

      <section id="project-billing" class="project-edit-section">
        <h2>Invoice Defaults</h2>
        <p>These settings control generated project invoices and automatic delivery.</p>
        <div class="project-edit-grid">
          <label class="project-field">
            <span>Billing Period</span>
            <select name="invoice_billing_period">
              <option value="monthly" <?php echo ($project['invoice_billing_period'] ?? 'monthly') === 'monthly' ? 'selected' : ''; ?>>Monthly project billing</option>
              <option value="per_invoice" <?php echo ($project['invoice_billing_period'] ?? '') === 'per_invoice' ? 'selected' : ''; ?>>Each invoice on its own</option>
            </select>
          </label>
          <label class="project-field">
            <span>Project NET Days</span>
            <input type="number" min="0" step="1" name="invoice_net_terms_days" value="<?php echo htmlspecialchars((string)($project['invoice_net_terms_days'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="System default">
          </label>
        </div>
        <label class="project-check">
          <input type="checkbox" name="project_invoice_auto_email" value="1" <?php echo $autoEmailEnabled ? 'checked' : ''; ?>>
          <span>
            <strong>Automatically email monthly project invoices</strong>
            <small style="display:block;color:var(--muted)">Uses the selected project invoice email recipients after the monthly invoice is generated.</small>
          </span>
        </label>
      </section>

      <section id="project-schedule" class="project-edit-section">
        <h2>Schedule &amp; Notes</h2>
        <div class="project-edit-grid">
          <label class="project-field">
            <span>Estimated Start</span>
            <input type="date" name="estimated_start" value="<?php echo htmlspecialchars((string)($project['estimated_start'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          </label>
          <label class="project-field">
            <span>Estimated End</span>
            <input type="date" name="estimated_end" value="<?php echo htmlspecialchars((string)($project['estimated_end'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          </label>
        </div>
        <label class="project-field">
          <span>Notes</span>
          <textarea name="notes" rows="5"><?php echo htmlspecialchars((string)($project['notes'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
        </label>
      </section>

      <div class="project-edit-actions">
        <a class="btn" href="/?page=project/projects-details&amp;id=<?php echo $projectId; ?>">Cancel</a>
        <button type="submit" class="btn btn-primary">Save Project</button>
      </div>
    </form>
  </div>
</div>

<script src="<?php echo htmlspecialchars(asset_url('/assets/js/project-settings.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
