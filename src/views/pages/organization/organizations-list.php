<?php
// src/views/pages/organization/organizations-list.php
require_once __DIR__ . '/../../../config/db.php';
// TODO: We need to add a way for the user to upload a tax exempt form for an org. This will be a file upload, and we will keep up to two files per org. This way if they update theirs, we will also have the old one to reference.
// Fetch all organizations with client counts
$sql = "SELECT o.id, o.name, o.notes, o.created_at, COUNT(c.id) as client_count 
        FROM organizations o
        LEFT JOIN clients c ON c.organization_id = o.id AND c.archived = 0
        GROUP BY o.id
        ORDER BY o.name ASC";
$stmt = $pdo->query($sql);
$organizations = $stmt->fetchAll();
?>
<section>
  <h2>Organizations</h2>
  <div style="margin:8px 0">
    <a href="/?page=organization/organizations-create" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:var(--nav-accent);color:#fff;text-decoration:none">Create Organization</a>
  </div>
  <?php if (!empty($_GET['created'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Organization created.</div>
  <?php elseif (!empty($_GET['updated'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Organization updated.</div>
  <?php elseif (!empty($_GET['deleted'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5">Organization deleted.</div>
  <?php endif; ?>
  
  <div style="overflow:auto;margin-top:16px">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">Name</th>
          <th style="padding:10px">Clients</th>
          <th style="padding:10px">Created</th>
          <th style="padding:10px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($organizations)): ?>
          <tr>
            <td colspan="4" style="padding:20px;text-align:center;color:var(--muted)">No organizations found. Create one to get started.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($organizations as $org): ?>
            <tr style="border-top:1px solid #f3f4f6">
              <td style="padding:10px">
                <a href="/?page=organization/organization-view&id=<?php echo (int)$org['id']; ?>" style="text-decoration:none;color:inherit;font-weight:600">
                  <?php echo htmlspecialchars($org['name']); ?>
                </a>
              </td>
              <td style="padding:10px"><?php echo (int)$org['client_count']; ?> client<?php echo $org['client_count'] != 1 ? 's' : ''; ?></td>
              <td style="padding:10px"><?php echo htmlspecialchars(date('M j, Y', strtotime($org['created_at']))); ?></td>
              <td style="padding:10px">
                <a href="/?page=organization/organizations-edit&id=<?php echo (int)$org['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff;font-size:small;text-decoration:none">Edit</a>
                <a href="/?page=organization/organization-view&id=<?php echo (int)$org['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff;font-size:small;margin-left:4px;text-decoration:none">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
