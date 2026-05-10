<?php
// src/views/pages/organization/organization-view.php
require_once __DIR__ . '/../../../config/db.php';

$id = (int)($_GET['id'] ?? 0);
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

// Get ALL clients not in this organization (including those with no org assigned)
$availableStmt = $pdo->prepare('SELECT id, name, email FROM clients WHERE (organization_id IS NULL OR organization_id != ?) AND archived = 0 ORDER BY name ASC');
$availableStmt->execute([$id]);
$availableClients = $availableStmt->fetchAll();
?>
<section>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <h2><?php echo htmlspecialchars($org['name']); ?></h2>
    <div>
      <a href="/?page=organization/organizations-edit&id=<?php echo $id; ?>" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;text-decoration:none;font-size:small">Edit Organization</a>

      <!-- Upload tax-exempt form directly from the organization view -->
      <form method="post" action="/?page=organization/organization-document-upload" enctype="multipart/form-data" style="display:inline-block;margin-left:8px">
        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="doc_type" value="tax_exempt">
        <label style="display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff;font-size:small;cursor:pointer">
          <input type="file" name="tax_exempt_file" accept="application/pdf,image/*" style="display:none" onchange="this.form.submit()">
          <span style="pointer-events:none">Upload Tax Exempt</span>
        </label>
      </form>

      <!-- Upload COI -->
      <form method="post" action="/?page=organization/organization-document-upload" enctype="multipart/form-data" style="display:inline-block;margin-left:8px">
        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="doc_type" value="coi">
        <label style="display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff;font-size:small;cursor:pointer">
          <input type="file" name="coi_file" accept="application/pdf,image/*" style="display:none" onchange="this.form.submit()">
          <span style="pointer-events:none">Upload COI</span>
        </label>
      </form>

      <!-- Upload W-9 -->
      <form method="post" action="/?page=organization/organization-document-upload" enctype="multipart/form-data" style="display:inline-block;margin-left:8px">
        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="doc_type" value="w9">
        <label style="display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff;font-size:small;cursor:pointer">
          <input type="file" name="w9_file" accept="application/pdf,image/*" style="display:none" onchange="this.form.submit()">
          <span style="pointer-events:none">Upload W-9</span>
        </label>
      </form>

      <a href="/?page=organization/organizations-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;text-decoration:none;margin-left:8px;font-size:small">Back to List</a>
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
  <?php elseif (!empty($_GET['error'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff3f3;color:#991b1b;border:1px solid #fecaca"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>

  <div style="background:#fff;border-radius:8px;padding:16px;box-shadow:0 6px 18px rgba(11,18,32,0.06);margin-bottom:24px">
    <div style="display:grid;grid-template-columns:<?php echo !empty($org['tax_exempt_file']) ? '1fr 300px' : '1fr'; ?>;gap:20px">
      <div>
        <h3 style="margin-top:0">Organization Details</h3>
        <div style="display:grid;gap:12px">
          <div>
            <strong>Name:</strong> <?php echo htmlspecialchars($org['name']); ?>
          </div>
          <div>
            <strong>Total Clients:</strong> <?php echo count($clients); ?>
          </div>
          <div>
            <strong>Notes:</strong>
            <div id="notesDisplay" style="margin-top:4px;color:var(--muted);min-height:40px;padding:8px;border:1px solid transparent;border-radius:4px;cursor:pointer" onclick="toggleNotesEdit()">
              <?php echo !empty($org['notes']) ? nl2br(htmlspecialchars($org['notes'])) : '<em style="color:#999">Click to add notes...</em>'; ?>
            </div>
            <form id="notesForm" method="post" action="/?page=organization/organization-update-notes" style="display:none;margin-top:4px">
              <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
              <input type="hidden" name="id" value="<?php echo $id; ?>">
              <textarea name="notes" id="notesTextarea" rows="4" style="width:100%;padding:8px;border-radius:8px;border:1px solid #ddd;font-family:inherit"><?php echo htmlspecialchars($org['notes'] ?? ''); ?></textarea>
              <div style="display:flex;gap:8px;margin-top:8px">
                <button type="submit" style="padding:6px 12px;border:0;border-radius:8px;background:var(--nav-accent);color:#fff;font-size:small;cursor:pointer">Save</button>
                <button type="button" onclick="toggleNotesEdit()" style="padding:6px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;font-size:small;cursor:pointer">Cancel</button>
              </div>
            </form>
          </div>
          <div>
            <strong>Created:</strong> <?php echo htmlspecialchars(date('F j, Y', strtotime($org['created_at']))); ?>
          </div>
        </div>
      </div>
      <?php if (!empty($org['tax_exempt_file'])): ?>
        <div>
          <h3 style="margin-top:0">Tax Exempt Form</h3>
          <?php
          $filePath = __DIR__ . '/../../../../uploads/organizations/' . $org['tax_exempt_file'];
          $isPdf = strtolower(pathinfo($org['tax_exempt_file'], PATHINFO_EXTENSION)) === 'pdf';
          ?>
          <?php if ($isPdf): ?>
            <div style="border:1px solid #ddd;border-radius:8px;overflow:hidden">
              <embed src="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['tax_exempt_file']); ?>" type="application/pdf" width="100%" height="350px" />
            </div>
          <?php else: ?>
            <div style="border:1px solid #ddd;border-radius:8px;overflow:hidden">
              <img src="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['tax_exempt_file']); ?>" style="width:100%;height:auto" alt="Tax Exempt Form" />
            </div>
          <?php endif; ?>
          <div style="display:flex;gap:8px;margin-top:8px;font-size:small">
            <a href="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['tax_exempt_file']); ?>" target="_blank" style="padding:6px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;text-decoration:none;color:inherit">View Full</a>
            <a href="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['tax_exempt_file']); ?>" download style="padding:6px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;text-decoration:none;color:inherit">Download</a>
          </div>
          <?php if (!empty($org['tax_exempt_uploaded_at'])): ?>
            <div style="font-size:small;color:var(--muted);margin-top:8px">Uploaded <?php echo htmlspecialchars(date('F j, Y', strtotime($org['tax_exempt_uploaded_at']))); ?></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Document Section: COI, W-9, Tax Exempt -->
  <div style="background:#fff;border-radius:8px;padding:16px;box-shadow:0 6px 18px rgba(11,18,32,0.06);margin-bottom:24px">
    <h3 style="margin-top:0;margin-bottom:16px">Documents</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px">
      
      <!-- Tax Exempt -->
      <div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h4 style="margin:0">Tax Exempt</h4>
          <form method="post" action="/?page=organization/organization-document-upload" enctype="multipart/form-data" style="display:inline">
            <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <input type="hidden" name="doc_type" value="tax_exempt">
            <label style="display:inline-flex;align-items:center;gap:4px;padding:4px 8px;border:1px solid #ddd;border-radius:6px;background:#fff;font-size:small;cursor:pointer">
              <input type="file" name="tax_exempt_file" accept="application/pdf,image/*" style="display:none" onchange="this.form.submit()">
              <span style="pointer-events:none"><?php echo !empty($org['tax_exempt_file']) ? 'Replace' : 'Upload'; ?></span>
            </label>
          </form>
        </div>
        <?php if (!empty($org['tax_exempt_file'])): ?>
          <?php $isPdf = strtolower(pathinfo($org['tax_exempt_file'], PATHINFO_EXTENSION)) === 'pdf'; ?>
          <div style="border:1px solid #ddd;border-radius:8px;overflow:hidden">
            <?php if ($isPdf): ?
              <embed src="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['tax_exempt_file']); ?>" type="application/pdf" width="100%" height="250px" />
            <?php else: ?
              <img src="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['tax_exempt_file']); ?>" style="width:100%;height:auto" alt="Tax Exempt Form" />
            <?php endif; ?>
          </div>
          <div style="display:flex;gap:8px;margin-top:8px;font-size:small">
            <a href="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['tax_exempt_file']); ?>" target="_blank" style="padding:4px 8px;border:1px solid #ddd;border-radius:6px;background:#fff;text-decoration:none;color:inherit">View</a>
            <a href="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['tax_exempt_file']); ?>" download style="padding:4px 8px;border:1px solid #ddd;border-radius:6px;background:#fff;text-decoration:none;color:inherit">Download</a>
          </div>
          <?php if (!empty($org['tax_exempt_uploaded_at'])): ?
            <div style="font-size:small;color:var(--muted);margin-top:4px">Uploaded <?php echo htmlspecialchars(date('F j, Y', strtotime($org['tax_exempt_uploaded_at']))); ?></div>
          <?php endif; ?
        <?php else: ?
          <div style="padding:16px;text-align:center;color:#999;border:1px dashed #ddd;border-radius:8px;font-size:small">No tax exempt form uploaded</div>
        <?php endif; ?>
      </div>

      <!-- COI -->
      <div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h4 style="margin:0">Certificate of Insurance (COI)</h4>
          <form method="post" action="/?page=organization/organization-document-upload" enctype="multipart/form-data" style="display:inline">
            <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <input type="hidden" name="doc_type" value="coi">
            <label style="display:inline-flex;align-items:center;gap:4px;padding:4px 8px;border:1px solid #ddd;border-radius:6px;background:#fff;font-size:small;cursor:pointer">
              <input type="file" name="coi_file" accept="application/pdf,image/*" style="display:none" onchange="this.form.submit()">
              <span style="pointer-events:none"><?php echo !empty($org['coi_file']) ? 'Replace' : 'Upload'; ?></span>
            </label>
          </form>
        </div>
        <?php if (!empty($org['coi_file'])): ?>
          <?php $isPdf = strtolower(pathinfo($org['coi_file'], PATHINFO_EXTENSION)) === 'pdf'; ?>
          <div style="border:1px solid #ddd;border-radius:8px;overflow:hidden">
            <?php if ($isPdf): ?
              <embed src="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['coi_file']); ?>" type="application/pdf" width="100%" height="250px" />
            <?php else: ?
              <img src="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['coi_file']); ?>" style="width:100%;height:auto" alt="COI" />
            <?php endif; ?>
          </div>
          <div style="display:flex;gap:8px;margin-top:8px;font-size:small">
            <a href="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['coi_file']); ?>" target="_blank" style="padding:4px 8px;border:1px solid #ddd;border-radius:6px;background:#fff;text-decoration:none;color:inherit">View</a>
            <a href="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['coi_file']); ?>" download style="padding:4px 8px;border:1px solid #ddd;border-radius:6px;background:#fff;text-decoration:none;color:inherit">Download</a>
          </div>
          <?php if (!empty($org['coi_uploaded_at'])): ?
            <div style="font-size:small;color:var(--muted);margin-top:4px">Uploaded <?php echo htmlspecialchars(date('F j, Y', strtotime($org['coi_uploaded_at']))); ?></div>
          <?php endif; ?
        <?php else: ?
          <div style="padding:16px;text-align:center;color:#999;border:1px dashed #ddd;border-radius:8px;font-size:small">No COI uploaded</div>
        <?php endif; ?>
      </div>

      <!-- W-9 -->
      <div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <h4 style="margin:0">W-9</h4>
          <form method="post" action="/?page=organization/organization-document-upload" enctype="multipart/form-data" style="display:inline">
            <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <input type="hidden" name="doc_type" value="w9">
            <label style="display:inline-flex;align-items:center;gap:4px;padding:4px 8px;border:1px solid #ddd;border-radius:6px;background:#fff;font-size:small;cursor:pointer">
              <input type="file" name="w9_file" accept="application/pdf,image/*" style="display:none" onchange="this.form.submit()">
              <span style="pointer-events:none"><?php echo !empty($org['w9_file']) ? 'Replace' : 'Upload'; ?></span>
            </label>
          </form>
        </div>
        <?php if (!empty($org['w9_file'])): ?>
          <?php $isPdf = strtolower(pathinfo($org['w9_file'], PATHINFO_EXTENSION)) === 'pdf'; ?>
          <div style="border:1px solid #ddd;border-radius:8px;overflow:hidden">
            <?php if ($isPdf): ?
              <embed src="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['w9_file']); ?>" type="application/pdf" width="100%" height="250px" />
            <?php else: ?
              <img src="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['w9_file']); ?>" style="width:100%;height:auto" alt="W-9" />
            <?php endif; ?>
          </div>
          <div style="display:flex;gap:8px;margin-top:8px;font-size:small">
            <a href="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['w9_file']); ?>" target="_blank" style="padding:4px 8px;border:1px solid #ddd;border-radius:6px;background:#fff;text-decoration:none;color:inherit">View</a>
            <a href="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['w9_file']); ?>" download style="padding:4px 8px;border:1px solid #ddd;border-radius:6px;background:#fff;text-decoration:none;color:inherit">Download</a>
          </div>
          <?php if (!empty($org['w9_uploaded_at'])): ?
            <div style="font-size:small;color:var(--muted);margin-top:4px">Uploaded <?php echo htmlspecialchars(date('F j, Y', strtotime($org['w9_uploaded_at']))); ?></div>
          <?php endif; ?
        <?php else: ?
          <div style="padding:16px;text-align:center;color:#999;border:1px dashed #ddd;border-radius:8px;font-size:small">No W-9 uploaded</div>
        <?php endif; ?>
      </div>

    </div>
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
      <div style="padding:20px;text-align:center;color:var(--muted);border:1px dashed #ddd;border-radius:8px">
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
                  <form method="post" action="/?page=organization/organization-remove-client" style="display:inline" onsubmit="return confirm('Remove <?php echo addslashes($client['name']); ?> from this organization?')">
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
</section>

<script>
  const orgId = <?php echo $id; ?>;
  const availableClients = <?php echo json_encode($availableClients); ?>;
</script>

<script src="js/organization-view-logic.js" defer></script>
