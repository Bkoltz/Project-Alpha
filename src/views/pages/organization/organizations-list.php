<?php
// src/views/pages/organization/organizations-list.php
require_once __DIR__ . '/../../../config/db.php';
// TODO: We need to add a way for the user to upload a tax exempt form for an org. This will be a file upload, and we will keep up to two files per org. This way if they update theirs, we will also have the old one to reference.
// Fetch all organizations with client counts
$search = trim($_GET['search'] ?? '');
$where = [];
$params = [];
if ($search !== '') {
  $where[] = 'o.name LIKE ?';
  $params[] = '%'.$search.'%';
}

$whereClause = '';
if (!empty($where)) {
  $whereClause = 'WHERE ' . implode(' AND ', $where);
}

$sql = "SELECT o.id, o.name, o.notes, o.created_at, COUNT(c.id) as client_count 
        FROM organizations o
        LEFT JOIN clients c ON c.organization_id = o.id AND c.archived = 0
        $whereClause
        GROUP BY o.id
        ORDER BY o.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$organizations = $stmt->fetchAll();
?>
<section>
  <h2>Organizations</h2>
  <div style="margin:8px 0">
    <a href="/?page=organization/organizations-create" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:var(--nav-accent);color:#fff;text-decoration:none">Create Organization</a>
  </div>
  
  <!-- Search Bar -->
  <form method="get" action="/" style="display:flex;gap:8px;align-items:end;margin:12px 0;position:relative">
    <input type="hidden" name="page" value="organization/organizations-list">
    <label style="flex:1;position:relative">
      <div>Search Organizations</div>
      <input id="orgSearchBox" type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Type to search..." autocomplete="off" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
      <div id="orgSearchSuggest" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:220px;overflow:auto"></div>
    </label>
    <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Filter</button>
    <a href="/?page=organization/organizations-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Reset</a>
  </form>
  <script>
    (function(){
      var orgBox = document.getElementById('orgSearchBox');
      var orgSug = document.getElementById('orgSearchSuggest');
      orgBox.addEventListener('input', function(){
        var t = this.value.trim();
        if(!t){orgSug.style.display='none';orgSug.innerHTML='';return;}
        fetch('/?page=organization/org-search&term='+encodeURIComponent(t))
          .then(r=>r.json())
          .then(list=>{
            if(!Array.isArray(list)||list.length===0){orgSug.style.display='none';orgSug.innerHTML='';return;}
            orgSug.innerHTML = list.map(x=>`<div data-name="${x.name}" style=\"padding:8px 10px;cursor:pointer\">${x.name}</div>`).join('');
            Array.from(orgSug.children).forEach(el=>{
              el.addEventListener('click', function(){
                window.location = '/?page=organization/organizations-list&search='+encodeURIComponent(this.dataset.name);
              });
            });
            orgSug.style.display='block';
          }).catch(()=>{orgSug.style.display='none'});
      });
      document.addEventListener('click', function(e){ if(!orgSug.contains(e.target) && e.target!==orgBox){ orgSug.style.display='none'; } });
    })();
  </script>
  
  <?php if (!empty($_GET['created'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Organization created.</div>
  <?php elseif (!empty($_GET['updated'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Organization updated.</div>
  <?php elseif (!empty($_GET['deleted'])): ?>
    <div class="alert alert-danger">Organization deleted.</div>
  <?php endif; ?>
  
  <div style="overflow:auto;margin-top:16px">
    <table class="pa-table">
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
