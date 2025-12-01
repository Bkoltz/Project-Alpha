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

// Get clients not in any organization for the "Add Client" dropdown
$availableStmt = $pdo->query('SELECT id, name FROM clients WHERE organization_id IS NULL AND archived = 0 ORDER BY name ASC');
$availableClients = $availableStmt->fetchAll();
?>
<section>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <h2><?php echo htmlspecialchars($org['name']); ?></h2>
    <div>
      <a href="/?page=organization/organizations-edit&id=<?php echo $id; ?>" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;text-decoration:none;font-size:small">Edit Organization</a>

      <!-- Upload tax-exempt form directly from the organization view -->
      <form method="post" action="/?page=organization/organizations_upload" enctype="multipart/form-data" style="display:inline-block;margin-left:8px">
        <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <label style="display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff;font-size:small;cursor:pointer">
          <input type="file" name="tax_exempt_file" accept="application/pdf,image/*" style="display:none" onchange="this.form.submit()">
          <span style="pointer-events:none">Upload Tax Exempt</span>
        </label>
      </form>

      <a href="/?page=organization/organizations-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff;text-decoration:none;margin-left:8px;font-size:small">Back to List</a>
    </div>
  </div>

  <?php if (!empty($_GET['client_added'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Client added to organization.</div>
  <?php elseif (!empty($_GET['client_removed'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Client removed from organization.</div>
  <?php endif; ?>

  <div style="background:#fff;border-radius:8px;padding:16px;box-shadow:0 6px 18px rgba(11,18,32,0.06);margin-bottom:24px">
    <h3 style="margin-top:0">Organization Details</h3>
    <div style="display:grid;gap:12px">
      <div>
        <strong>Name:</strong> <?php echo htmlspecialchars($org['name']); ?>
      </div>
      <div>
        <strong>Total Clients:</strong> <?php echo count($clients); ?>
      </div>
      <?php if (!empty($org['notes'])): ?>
        <div>
          <strong>Notes:</strong><br>
          <div style="margin-top:4px;color:var(--muted)"><?php echo nl2br(htmlspecialchars($org['notes'])); ?></div>
        </div>
      <?php endif; ?>
      <?php if (!empty($org['tax_exempt_file'])): ?>
        <div>
          <strong>Tax Exempt Form:</strong>
          <div style="margin-top:4px"><a href="/?page=serve-upload&file=<?php echo rawurlencode('organizations/' . $org['tax_exempt_file']); ?>" target="_blank">View / Download</a>
            <?php if (!empty($org['tax_exempt_uploaded_at'])): ?> &nbsp; <span style="color:var(--muted);font-size:small">(uploaded <?php echo htmlspecialchars(date('F j, Y', strtotime($org['tax_exempt_uploaded_at']))); ?>)</span><?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
      <div>
        <strong>Created:</strong> <?php echo htmlspecialchars(date('F j, Y', strtotime($org['created_at']))); ?>
      </div>
    </div>
  </div>

  <div style="background:#fff;border-radius:8px;padding:16px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <h3 style="margin:0">Clients (<?php echo count($clients); ?>)</h3>
      <?php if (!empty($availableClients)): ?>
        <div>
          <form method="post" action="/?page=organization/organization-add-client" style="display:inline-flex;gap:8px;align-items:center">
            <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="organization_id" value="<?php echo $id; ?>">
            <select name="client_id" required style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;font-size:small">
              <option value="">Select a client...</option>
              <?php foreach ($availableClients as $ac): ?>
                <option value="<?php echo (int)$ac['id']; ?>"><?php echo htmlspecialchars($ac['name']); ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" style="padding:6px 12px;border:1px solid #ddd;border-radius:8px;background:var(--nav-accent);color:#fff;font-size:small">Add Client</button>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <?php if (empty($clients)): ?>
      <div style="padding:20px;text-align:center;color:var(--muted);border:1px dashed #ddd;border-radius:8px">
        No clients in this organization yet. <?php echo !empty($availableClients) ? 'Use the form above to add clients.' : 'All clients are already assigned to organizations.'; ?>
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
