<?php
// src/views/pages/project/projects-list.php
require_once __DIR__ . '/../../../config/db.php';

// Get filter parameters
$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$client_id = trim($_GET['client_id'] ?? '');
$org_id = trim($_GET['org_id'] ?? '');

// Build WHERE clause
$where = [];
$params = [];
if ($q !== '') { $where[] = 'p.name LIKE ?'; $params[] = '%'.$q.'%'; }
if ($status !== '') { $where[] = 'p.status = ?'; $params[] = $status; }
if ($client_id !== '') { $where[] = 'p.client_id = ?'; $params[] = $client_id; }
if ($org_id !== '') { $where[] = 'p.organization_id = ?'; $params[] = $org_id; }

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT p.*, c.name AS client_name, o.name AS organization_name
        FROM projects p
        LEFT JOIN clients c ON c.id = p.client_id
        LEFT JOIN organizations o ON o.id = p.organization_id
        $whereClause
        ORDER BY p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Get all organizations for filter dropdown
$orgStmt = $pdo->query('SELECT id, name FROM organizations ORDER BY name');
$organizations = $orgStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all clients for filter dropdown
$clientStmt = $pdo->query('SELECT id, name FROM clients WHERE archived = 0 ORDER BY name');
$clients = $clientStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<section>
  <h2>Projects</h2>
  <div style="display:flex;gap:12px;align-items:center;margin-bottom:16px">
    <a href="/?page=project/projects-create" style="padding:8px 16px;border:1px solid #ddd;border-radius:8px;background:var(--nav-accent);color:#fff;text-decoration:none;font-weight:600">Create Project</a>
  </div>

  <!-- Filters -->
  <form method="get" action="/" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:24px">
    <input type="hidden" name="page" value="project/projects-list">
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:12px;margin-bottom:12px">
      <label>
        <div style="margin-bottom:4px;font-weight:600;font-size:14px">Search by Name</div>
        <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Type project name..." style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px">
      </label>
      
      <label>
        <div style="margin-bottom:4px;font-weight:600;font-size:14px">Status</div>
        <select name="status" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px">
          <option value="">All Statuses</option>
          <option value="not_started" <?php echo $status === 'not_started' ? 'selected' : ''; ?>>Not Started</option>
          <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
          <option value="overdue" <?php echo $status === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
          <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
          <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
        </select>
      </label>
      
      <label style="position:relative">
        <div style="margin-bottom:4px;font-weight:600;font-size:14px">Client</div>
        <input type="text" id="clientSearchInput" placeholder="Type to search clients..." autocomplete="off" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px">
        <input type="hidden" name="client_id" id="clientIdInput" value="<?php echo htmlspecialchars($client_id); ?>">
        <div id="clientSuggestions" style="display:none;position:absolute;z-index:100;left:0;right:0;top:100%;background:#fff;border:1px solid #d1d5db;border-radius:6px;max-height:200px;overflow-y:auto;margin-top:2px"></div>
      </label>
      
      <label style="position:relative">
        <div style="margin-bottom:4px;font-weight:600;font-size:14px">Organization</div>
        <input type="text" id="orgSearchInput" placeholder="Type to search organizations..." autocomplete="off" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px">
        <input type="hidden" name="org_id" id="orgIdInput" value="<?php echo htmlspecialchars($org_id); ?>">
        <div id="orgSuggestions" style="display:none;position:absolute;z-index:100;left:0;right:0;top:100%;background:#fff;border:1px solid #d1d5db;border-radius:6px;max-height:200px;overflow-y:auto;margin-top:2px"></div>
      </label>
    </div>
    
    <div style="display:flex;gap:8px">
      <button type="submit" style="padding:8px 16px;border-radius:6px;background:var(--nav-accent);color:#fff;border:0;font-weight:600;cursor:pointer">Apply Filters</button>
      <a href="/?page=project/projects-list" style="padding:8px 16px;border-radius:6px;background:#f3f4f6;color:#374151;border:0;font-weight:600;text-decoration:none;display:inline-block">Clear</a>
    </div>
  </form>

  <?php if (!$rows): ?>
    <div style="color:var(--muted)">No projects found matching your filters.</div>
  <?php else: ?>
    <div style="display:grid;gap:12px">
      <?php foreach ($rows as $r): ?>
        <div style="border:1px solid #eee;border-radius:8px;padding:10px;background:#fff;display:flex;justify-content:space-between;align-items:center">
          <div>
            <div style="font-weight:700">
              <?php echo htmlspecialchars($r['name']); ?>
              <?php if (!empty($r['client_name'])): ?> · <?php echo htmlspecialchars($r['client_name']); ?><?php endif; ?>
              <?php if (!empty($r['organization_name'])): ?> · <?php echo htmlspecialchars($r['organization_name']); ?><?php endif; ?>
            </div>
            <div style="font-size:13px;color:var(--muted)">
              Status: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $r['status']))); ?> · Created: <?php echo htmlspecialchars($r['created_at']); ?>
              <?php if (!empty($r['estimated_start'])): ?> · Start: <?php echo htmlspecialchars($r['estimated_start']); ?><?php endif; ?>
              <?php if (!empty($r['estimated_end'])): ?> · End: <?php echo htmlspecialchars($r['estimated_end']); ?><?php endif; ?>
            </div>
          </div>
          <div style="display:flex;gap:8px;align-items:center">
            <a href="/?page=project/projects-details&id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;background:#fff">View Details</a>
            <form method="post" action="/?page=project/projects-delete" onsubmit="return confirm('Delete this project and all mappings?');">
              <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
              <input type="hidden" name="redirect" value="/?page=project/projects-list">
              <button type="submit" style="padding:6px 10px;border:1px solid #eee;border-radius:6px;background:#fee2e2;color:#991b1b">Delete</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<script>
  const clientData = <?php echo json_encode($clients); ?>;
  const orgData = <?php echo json_encode($organizations); ?>;
</script>

<script src="/assets/js/projects-list-logic.js" defer></script>
