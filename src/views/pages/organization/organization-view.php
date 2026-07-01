<?php
// src/views/pages/organization/organization-view.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../utils/escaper.php';
require_once __DIR__ . '/../../../utils/acl.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<p>Organization not found.</p>';
    return;
}
require_record_ownership($pdo, 'organizations', $id);
$stmt = $pdo->prepare('SELECT * FROM organizations WHERE id = ?');
$stmt->execute([$id]);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$org) {
    echo '<p>Organization not found.</p>';
    return;
}

// Get clients in this organization
$clientStmt = $pdo->prepare('SELECT id, name, email, phone FROM clients WHERE organization_id = ? AND archived = 0 ORDER BY name ASC');
$clientStmt->execute([$id]);
$clients = $clientStmt->fetchAll();

// Get clients that are safe to attach without leaking contacts from other organizations.
$currentUserId = (int)($_SESSION['user']['id'] ?? 0);
$canAttachAnyUnassignedClient = acl_user_has_org_wide_scope($pdo, $currentUserId, $id);
if (($_SESSION['user']['role'] ?? '') === 'admin' || $canAttachAnyUnassignedClient) {
    $availableStmt = $pdo->prepare('
        SELECT id, name, email
        FROM clients
        WHERE organization_id IS NULL AND archived = 0
        ORDER BY name ASC
    ');
    $availableStmt->execute();
} else {
    $availableStmt = $pdo->prepare('
        SELECT id, name, email
        FROM clients
        WHERE organization_id IS NULL AND created_by = ? AND archived = 0
        ORDER BY name ASC
    ');
    $availableStmt->execute([$currentUserId]);
}
$availableClients = $availableStmt->fetchAll();

$departmentStmt = $pdo->prepare('
    SELECT od.*,
           COUNT(DISTINCT odc.client_id) AS contact_count
    FROM organization_departments od
    LEFT JOIN organization_department_contacts odc ON odc.department_id = od.id
    WHERE od.organization_id = ?
    GROUP BY od.id
    ORDER BY od.name ASC
');
$departmentStmt->execute([$id]);
$departments = $departmentStmt->fetchAll(PDO::FETCH_ASSOC);

$departmentContacts = [];
if ($departments) {
    $ids = array_map(static fn($row) => (int)$row['id'], $departments);
    $contactStmt = $pdo->prepare('
        SELECT odc.department_id, c.id, c.name, c.email
        FROM organization_department_contacts odc
        JOIN clients c ON c.id = odc.client_id
        WHERE odc.department_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
        ORDER BY c.name ASC
    ');
    $contactStmt->execute($ids);
    foreach ($contactStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $departmentContacts[(int)$row['department_id']][] = $row;
    }
}

$departmentLinks = [];
if ($departments) {
    $ids = array_map(static fn($row) => (int)$row['id'], $departments);
    $linkStmt = $pdo->prepare('
        SELECT entity_id, id, title, url, link_type, include_on_invoices
        FROM entity_links
        WHERE entity_type = "department" AND entity_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
        ORDER BY include_on_invoices DESC, title ASC, id ASC
    ');
    $linkStmt->execute($ids);
    foreach ($linkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $departmentLinks[(int)$row['entity_id']][] = $row;
    }
}

$orgAddressLines = array_values(array_filter([
    trim((string)($org['address_line1'] ?? '')),
    trim((string)($org['address_line2'] ?? '')),
    trim(implode(', ', array_filter([
        trim((string)($org['city'] ?? '')),
        trim((string)($org['state'] ?? '')),
    ]))),
    trim((string)($org['postal_code'] ?? '')),
    trim((string)($org['country'] ?? '')),
], static fn($value) => $value !== ''));
$taxFileUrl = !empty($org['tax_exempt_file'])
    ? '/?page=serve-upload&file=' . e(rawurlencode('organizations/' . $org['tax_exempt_file']))
    : '';
?>
<style>
  .org-view { max-width: 1440px; margin: 0 auto; padding-bottom: 32px; }
  .org-view__header { display: flex; justify-content: space-between; gap: 18px; align-items: flex-start; margin-bottom: 18px; }
  .org-view__title { margin: 0; font-size: 30px; line-height: 1.15; }
  .org-view__meta { margin-top: 6px; color: var(--muted); font-size: 13px; }
  .org-view__actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
  .org-view__button { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; color: inherit; text-decoration: none; font-size: 13px; cursor: pointer; }
  .org-view__button--primary { background: var(--nav-accent); border-color: var(--nav-accent); color: #fff; }
  .org-view__stats { display: grid; grid-template-columns: repeat(4, minmax(140px, 1fr)); gap: 12px; margin: 0 0 18px; }
  .org-view__stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; box-shadow: 0 6px 18px rgba(11,18,32,0.05); }
  .org-view__stat span { display: block; color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
  .org-view__stat strong { display: block; margin-top: 6px; font-size: 24px; line-height: 1; }
  .org-view__layout { display: grid; grid-template-columns: minmax(280px, 360px) minmax(0, 1fr); gap: 18px; align-items: start; }
  .org-view__sidebar { display: grid; gap: 14px; position: sticky; top: 16px; }
  .org-view__main { min-width: 0; }
  .org-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; box-shadow: 0 6px 18px rgba(11,18,32,0.05); }
  .org-card + .org-card { margin-top: 18px; }
  .org-card__head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
  .org-card__title { margin: 0; font-size: 17px; }
  .org-detail-list { display: grid; gap: 12px; margin: 0; }
  .org-detail-list dt { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
  .org-detail-list dd { margin: 4px 0 0; line-height: 1.5; }
  .org-empty { padding: 20px; text-align: center; color: var(--muted); border: 1px dashed #d1d5db; border-radius: 8px; background: #f9fafb; }
  @media (max-width: 960px) {
    .org-view__header { display: grid; }
    .org-view__actions { justify-content: flex-start; }
    .org-view__stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .org-view__layout { grid-template-columns: 1fr; }
    .org-view__sidebar { position: static; order: -1; }
  }
</style>

<section class="org-view">
  <div class="org-view__header">
    <div>
      <h2 class="org-view__title"><?php echo htmlspecialchars($org['name']); ?></h2>
      <div class="org-view__meta">Created <?php echo htmlspecialchars(date('F j, Y', strtotime($org['created_at']))); ?></div>
    </div>
    <div class="org-view__actions">
      <a class="org-view__button org-view__button--primary" href="/?page=organization/organizations-edit&id=<?php echo $id; ?>">Edit Organization</a>
      <form method="post" action="/?page=organization/organizations-upload" enctype="multipart/form-data" style="display:inline-block;margin:0">
        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <label class="org-view__button" style="display:inline-flex;align-items:center;gap:8px;cursor:pointer">
          <input type="file" name="tax_exempt_file" accept="application/pdf,image/*" style="display:none" onchange="this.form.submit()">
          <span style="pointer-events:none">Upload Tax Exempt</span>
        </label>
      </form>
      <a class="org-view__button" href="/?page=organization/organizations-list">Back to List</a>
    </div>
  </div>

  <?php if (!empty($_GET['client_added'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Client added to organization.</div>
  <?php elseif (!empty($_GET['client_removed'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Client removed from organization.</div>
  <?php elseif (!empty($_GET['notes_updated'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Notes updated successfully.</div>
  <?php elseif (!empty($_GET['uploaded'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Tax exempt form uploaded successfully.</div>
  <?php elseif (!empty($_GET['department_created']) || !empty($_GET['department_saved'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Department saved.</div>
  <?php elseif (!empty($_GET['department_deleted'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Department deleted.</div>
  <?php elseif (!empty($_GET['department_contact_added']) || !empty($_GET['department_contact_removed'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Department contact updated.</div>
  <?php elseif (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff3f3;color:#991b1b;border:1px solid #fecaca"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>

  <div class="org-view__stats">
    <div class="org-view__stat"><span>Clients</span><strong><?php echo count($clients); ?></strong></div>
    <div class="org-view__stat"><span>Departments</span><strong><?php echo count($departments); ?></strong></div>
    <div class="org-view__stat"><span>Tax Exempt</span><strong><?php echo !empty($org['tax_exempt_file']) ? 'Yes' : 'No'; ?></strong></div>
    <div class="org-view__stat"><span>Available Adds</span><strong><?php echo count($availableClients); ?></strong></div>
  </div>

  <div class="org-view__layout">
    <aside class="org-view__sidebar">
      <div class="org-card">
        <div class="org-card__head">
          <h3 class="org-card__title">Details</h3>
        </div>
        <dl class="org-detail-list">
          <div>
            <dt>Name</dt>
            <dd><?php echo htmlspecialchars($org['name']); ?></dd>
          </div>
          <div>
            <dt>Address</dt>
            <dd>
              <?php if ($orgAddressLines): ?>
                <?php echo implode('<br>', array_map('htmlspecialchars', $orgAddressLines)); ?>
              <?php else: ?>
                <span style="color:var(--muted)">No organization address saved.</span>
              <?php endif; ?>
            </dd>
          </div>
          <div>
            <dt>Notes</dt>
            <dd>
              <div id="notesDisplay" style="color:var(--muted);min-height:40px;padding:8px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;background:#f9fafb" onclick="toggleNotesEdit()">
                <?php echo !empty($org['notes']) ? nl2br(htmlspecialchars($org['notes'])) : '<em style="color:#999">Click to add notes...</em>'; ?>
              </div>
              <form id="notesForm" method="post" action="/?page=organization/organization-update-notes" style="display:none;margin-top:8px">
                <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <textarea name="notes" id="notesTextarea" rows="5" style="width:100%;padding:8px;border-radius:8px;border:1px solid #ddd;font-family:inherit"><?php echo htmlspecialchars($org['notes'] ?? ''); ?></textarea>
                <div style="display:flex;gap:8px;margin-top:8px">
                  <button type="submit" class="org-view__button org-view__button--primary">Save</button>
                  <button type="button" onclick="toggleNotesEdit()" class="org-view__button">Cancel</button>
                </div>
              </form>
            </dd>
          </div>
        </dl>
      </div>

      <div class="org-card">
        <div class="org-card__head">
          <h3 class="org-card__title">Tax Exempt Form</h3>
        </div>
        <?php if (!empty($org['tax_exempt_file'])): ?>
          <?php $isPdf = strtolower(pathinfo($org['tax_exempt_file'], PATHINFO_EXTENSION)) === 'pdf'; ?>
          <?php if ($isPdf): ?>
            <div style="border:1px solid #ddd;border-radius:8px;overflow:hidden">
              <embed src="<?php echo $taxFileUrl; ?>" type="application/pdf" width="100%" height="260px" />
            </div>
          <?php else: ?>
            <div style="border:1px solid #ddd;border-radius:8px;overflow:hidden">
              <img src="<?php echo $taxFileUrl; ?>" style="width:100%;height:auto" alt="Tax Exempt Form" />
            </div>
          <?php endif; ?>
          <div style="display:flex;gap:8px;margin-top:8px;font-size:small">
            <a href="<?php echo $taxFileUrl; ?>" target="_blank" class="org-view__button">View Full</a>
            <a href="<?php echo $taxFileUrl; ?>" download class="org-view__button">Download</a>
          </div>
          <?php if (!empty($org['tax_exempt_uploaded_at'])): ?>
            <div style="font-size:small;color:var(--muted);margin-top:8px">Uploaded <?php echo htmlspecialchars(date('F j, Y', strtotime($org['tax_exempt_uploaded_at']))); ?></div>
          <?php endif; ?>
        <?php else: ?>
          <div class="org-empty">No tax exempt form uploaded.</div>
        <?php endif; ?>
      </div>
    </aside>

    <main class="org-view__main">
      <?php
        $entityType = 'organization';
        $entityId = $id;
        require __DIR__ . '/../../components/links_section.php';
      ?>

  <div style="background:#fff;border-radius:8px;padding:16px;box-shadow:0 6px 18px rgba(11,18,32,0.06);margin:24px 0">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px">
      <div>
        <h3 style="margin:0 0 4px">Departments</h3>
        <p style="margin:0;color:var(--muted);font-size:13px">Optional groups inside this organization, like Football, HighSchool, or Accounting.</p>
      </div>
    </div>

    <form method="post" action="/?page=organization/organization-departments" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end;margin-bottom:18px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb">
      <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
      <input type="hidden" name="action" value="save_department">
      <input type="hidden" name="organization_id" value="<?php echo (int)$id; ?>">
      <label>
        <div style="font-size:13px;font-weight:600;margin-bottom:4px">Department Name</div>
        <input name="name" required placeholder="Football" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:8px">
      </label>
      <label>
        <div style="font-size:13px;font-weight:600;margin-bottom:4px">Folder Name</div>
        <input name="folder_name" placeholder="Football" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:8px">
      </label>
      <label>
        <div style="font-size:13px;font-weight:600;margin-bottom:4px">Resolver Mode</div>
        <select name="resolver_mode" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:8px">
          <option value="manual_only">Manual links only</option>
          <option value="review">Review matches</option>
          <option value="auto_attach">Auto attach exact matches</option>
          <option value="excluded">Exclude from resolver</option>
        </select>
      </label>
      <label style="grid-column:1/-1">
        <div style="font-size:13px;font-weight:600;margin-bottom:4px">Folder Aliases <span style="font-weight:400;color:var(--muted)">(one per line)</span></div>
        <textarea name="folder_aliases" rows="2" placeholder="WHS Football&#10;Football Club" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:8px"></textarea>
      </label>
      <button type="submit" class="btn btn-sm" style="width:max-content">Add Department</button>
    </form>

    <?php if (empty($departments)): ?>
      <div class="org-empty">
        No departments yet. That is fine for simple organizations.
      </div>
    <?php else: ?>
      <div style="display:grid;gap:14px">
        <?php foreach ($departments as $department): ?>
          <?php
            $deptId = (int)$department['id'];
            $aliases = [];
            if (!empty($department['folder_aliases'])) {
                $decodedAliases = json_decode((string)$department['folder_aliases'], true);
                if (is_array($decodedAliases)) {
                    $aliases = array_values(array_filter(array_map('strval', $decodedAliases)));
                }
            }
          ?>
          <div style="border:1px solid #e5e7eb;border-radius:8px;padding:14px;background:#fff">
            <form method="post" action="/?page=organization/organization-departments" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end">
              <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
              <input type="hidden" name="action" value="save_department">
              <input type="hidden" name="organization_id" value="<?php echo (int)$id; ?>">
              <input type="hidden" name="department_id" value="<?php echo $deptId; ?>">
              <label>
                <div style="font-size:13px;font-weight:600;margin-bottom:4px">Name</div>
                <input name="name" value="<?php echo e((string)$department['name']); ?>" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:8px">
              </label>
              <label>
                <div style="font-size:13px;font-weight:600;margin-bottom:4px">Folder Name</div>
                <input name="folder_name" value="<?php echo e((string)($department['folder_name'] ?? '')); ?>" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:8px">
              </label>
              <label>
                <div style="font-size:13px;font-weight:600;margin-bottom:4px">Resolver Mode</div>
                <select name="resolver_mode" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:8px">
                  <?php foreach (['manual_only' => 'Manual links only', 'review' => 'Review matches', 'auto_attach' => 'Auto attach exact matches', 'excluded' => 'Exclude from resolver'] as $value => $label): ?>
                    <option value="<?php echo e($value); ?>" <?php echo (string)$department['resolver_mode'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label style="grid-column:1/-1">
                <div style="font-size:13px;font-weight:600;margin-bottom:4px">Folder Aliases</div>
                <textarea name="folder_aliases" rows="2" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:8px"><?php echo e(implode("\n", $aliases)); ?></textarea>
              </label>
              <label style="grid-column:1/-1">
                <div style="font-size:13px;font-weight:600;margin-bottom:4px">Notes</div>
                <textarea name="notes" rows="2" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:8px"><?php echo e((string)($department['notes'] ?? '')); ?></textarea>
              </label>
              <div style="display:flex;gap:8px;flex-wrap:wrap;grid-column:1/-1">
                <button type="submit" class="btn btn-sm">Save Department</button>
                <button type="button" class="btn btn-sm" onclick="showAddManualLinkModal('department', <?php echo $deptId; ?>)">Add Department Link</button>
              </div>
            </form>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:14px">
              <div>
                <div style="font-weight:700;margin-bottom:8px">Department Contacts</div>
                <?php if (empty($departmentContacts[$deptId])): ?>
                  <div style="color:var(--muted);font-size:13px">No contacts assigned. Contacts can stay organization-only.</div>
                <?php else: ?>
                  <div style="display:grid;gap:6px">
                    <?php foreach ($departmentContacts[$deptId] as $contact): ?>
                      <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;border:1px solid #eef2f7;border-radius:8px;padding:8px">
                        <span>
                          <strong><?php echo e((string)$contact['name']); ?></strong>
                          <?php if (!empty($contact['email'])): ?><span style="color:var(--muted);font-size:12px"> <?php echo e((string)$contact['email']); ?></span><?php endif; ?>
                        </span>
                        <form method="post" action="/?page=organization/organization-departments" style="margin:0">
                          <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                          <input type="hidden" name="action" value="remove_contact">
                          <input type="hidden" name="organization_id" value="<?php echo (int)$id; ?>">
                          <input type="hidden" name="department_id" value="<?php echo $deptId; ?>">
                          <input type="hidden" name="client_id" value="<?php echo (int)$contact['id']; ?>">
                          <button type="submit" style="padding:4px 8px;border:1px solid #fca5a5;border-radius:8px;background:#fff;color:#b91c1c;font-size:12px">Remove</button>
                        </form>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <form method="post" action="/?page=organization/organization-departments" style="display:flex;gap:8px;margin-top:10px">
                  <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                  <input type="hidden" name="action" value="assign_contact">
                  <input type="hidden" name="organization_id" value="<?php echo (int)$id; ?>">
                  <input type="hidden" name="department_id" value="<?php echo $deptId; ?>">
                  <select name="client_id" style="flex:1;padding:8px;border:1px solid #ddd;border-radius:8px">
                    <option value="">Assign org contact...</option>
                    <?php foreach ($clients as $client): ?>
                      <option value="<?php echo (int)$client['id']; ?>"><?php echo e((string)$client['name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm">Assign</button>
                </form>
              </div>
              <div>
                <div style="font-weight:700;margin-bottom:8px">Department Links</div>
                <?php if (empty($departmentLinks[$deptId])): ?>
                  <div style="color:var(--muted);font-size:13px">No department links yet.</div>
                <?php else: ?>
                  <div style="display:grid;gap:6px">
                    <?php foreach ($departmentLinks[$deptId] as $link): ?>
                      <a href="<?php echo e((string)$link['url']); ?>" target="_blank" rel="noopener" style="display:block;border:1px solid #eef2f7;border-radius:8px;padding:8px;text-decoration:none;color:inherit">
                        <strong><?php echo e((string)($link['title'] ?: $link['link_type'])); ?></strong>
                        <?php if (!empty($link['include_on_invoices'])): ?><span style="font-size:12px;color:#1d4ed8"> · invoices</span><?php endif; ?>
                        <span style="display:block;font-size:12px;color:var(--muted);word-break:break-all"><?php echo e((string)$link['url']); ?></span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <form method="post" action="/?page=organization/organization-departments" onsubmit="return confirm('Delete this department? Contacts and clients will not be deleted.')" style="margin-top:12px">
              <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
              <input type="hidden" name="action" value="delete_department">
              <input type="hidden" name="organization_id" value="<?php echo (int)$id; ?>">
              <input type="hidden" name="department_id" value="<?php echo $deptId; ?>">
              <button type="submit" style="padding:6px 10px;border:1px solid #fca5a5;border-radius:8px;background:#fff;color:#b91c1c;font-size:small">Delete Department</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div style="background:#fff;border-radius:8px;padding:16px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <h3 style="margin:0">Clients (<?php echo count($clients); ?>)</h3>
      <div style="position:relative">
        <input type="text" id="clientSearchInput" placeholder="Search clients to add..." autocomplete="off" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;font-size:small;min-width:250px">
        <div id="clientSearchResults" style="position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #ddd;border-radius:8px;display:none;max-height:200px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1);z-index:50;margin-top:4px"></div>
      </div>
    </div>

    <?php if (empty($clients)): ?>
      <div class="org-empty">
        No clients in this organization yet. Use the search above to add clients.
      </div>
    <?php else: ?>
      <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse">
          <thead>
            <tr style="text-align:left;border-bottom:1px solid #eee">
              <th style="padding:10px">Name</th>
              <th style="padding:10px">Email</th>
              <th style="padding:10px">Phone</th>
              <th style="padding:10px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($clients as $client): ?>
              <tr style="border-top:1px solid #f3f4f6">
                <td style="padding:10px">
                  <a href="/?page=client/clients-edit&id=<?php echo (int)$client['id']; ?>" style="text-decoration:none;color:inherit">
                    <?php echo htmlspecialchars($client['name']); ?>
                  </a>
                </td>
                <td style="padding:10px"><?php echo htmlspecialchars($client['email'] ?? ''); ?></td>
                <td style="padding:10px"><?php echo htmlspecialchars($client['phone'] ?? ''); ?></td>
                <td style="padding:10px">
                  <form method="post" action="/?page=organization/organization-remove-client" style="display:inline" onsubmit="return confirm('Remove <?php echo e(substr(json_encode((string)$client['name'], JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS), 1, -1)); ?> from this organization?')">
                    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="client_id" value="<?php echo (int)$client['id']; ?>">
                    <input type="hidden" name="organization_id" value="<?php echo $id; ?>">
                    <button type="submit" style="padding:6px 10px;border:1px solid #fca5a5;border-radius:8px;background:#fff;color:#b91c1c;font-size:small">Remove</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
    </main>
  </div>
</section>

<div id="organizationViewData"
     data-org-id="<?php echo $id; ?>"
     data-csrf="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>"
     data-available-clients="<?php echo htmlspecialchars(json_encode($availableClients), ENT_QUOTES, 'UTF-8'); ?>"
     hidden></div>

<script src="/assets/js/organization-view-logic.js" defer></script>
