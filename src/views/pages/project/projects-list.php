<?php
// src/views/pages/project/projects-list.php
require_once __DIR__ . '/../../../config/db.php';

$selected = (int)($_GET['id'] ?? 0);
$rows = $pdo->query('SELECT p.*, c.name AS client_name, o.name AS organization_name FROM projects p LEFT JOIN clients c ON c.id=p.client_id LEFT JOIN organizations o ON o.id=p.organization_id ORDER BY p.created_at DESC')->fetchAll();
$clients = $pdo->query('SELECT id,name FROM clients ORDER BY name')->fetchAll();

?>
<section>
  <h2>Projects</h2>
  <div style="display:flex;gap:12px;align-items:center;margin-bottom:12px">
    <a href="/?page=project/projects-create" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:var(--nav-accent);color:#fff;text-decoration:none">Create Project</a>
  </div>
  <?php if (!$rows): ?>
    <div style="color:var(--muted)">No projects created yet.</div>
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
              Created: <?php echo htmlspecialchars($r['created_at']); ?>
              <?php if (!empty($r['estimated_start'])): ?> · Start: <?php echo htmlspecialchars($r['estimated_start']); ?><?php endif; ?>
              <?php if (!empty($r['estimated_end'])): ?> · End: <?php echo htmlspecialchars($r['estimated_end']); ?><?php endif; ?>
            </div>
          </div>
          <div style="display:flex;gap:8px;align-items:center">
            <a href="/?page=project/projects-list&id=<?php echo (int)$r['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;background:#fff">Details</a>
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

  <?php if ($selected):
    $st = $pdo->prepare('SELECT * FROM projects WHERE id=?'); $st->execute([$selected]); $project = $st->fetch();
    if ($project):
  ?>
    <div style="margin-top:12px;border:1px solid #eee;border-radius:8px;padding:12px;background:#fff">
      <h3 id="projectNameHeading">Project <?php echo htmlspecialchars($project['name']); ?></h3>
      <form method="post" action="/?page=project/projects-update" style="display:grid;gap:8px;max-width:520px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf'] ?? ''); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$project['id']; ?>">
        <label><div>Name</div><input type="text" id="projectNameInputUpdate" name="name" value="<?php echo htmlspecialchars($project['name']); ?>" style="width:100%;padding:8px;border-radius:8px;border:1px solid #ddd"></label>

        <label>
          <div>Client (optional)</div>
          <input id="clientSearchBoxProjectUpdate" type="text" name="client_search" value="<?php echo htmlspecialchars($project['client_name'] ?? ''); ?>" placeholder="Search client..." autocomplete="off" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
          <input type="hidden" name="client_id" id="client_id_update" value="<?php echo (int)($project['client_id'] ?? 0); ?>">
          <div id="clientSearchSuggestProjectUpdate" style="position:relative;z-index:60;left:0;right:0;top:0;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:220px;overflow:auto"></div>
        </label>
        <!-- Single client search input above -->
        <!-- Parent Project no longer supported -->
        <label>
          <div>Organization (search)</div>
          <input id="orgInputProjectUpdate" type="text" name="organization_search" placeholder="Search organization..." autocomplete="off" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%" value="<?php echo htmlspecialchars($project['organization_name'] ?? ''); ?>">
          <input type="hidden" name="organization_id" id="organization_id_update" value="<?php echo (int)($project['organization_id'] ?? 0); ?>">
          <div id="orgSuggestProjectUpdate" style="position:relative;z-index:60;left:0;right:0;top:0;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:220px;overflow:auto"></div>
        </label>
        <div style="display:flex;gap:8px">
          <label style="flex:1"><div>Estimated Start</div><input type="date" name="estimated_start" value="<?php echo htmlspecialchars($project['estimated_start'] ?? ''); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%"></label>
          <label style="flex:1"><div>Estimated End</div><input type="date" name="estimated_end" value="<?php echo htmlspecialchars($project['estimated_end'] ?? ''); ?>" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%"></label>
        </div>
        <label><div>Notes</div><textarea name="notes" rows="4" style="width:100%;padding:8px;border-radius:8px;border:1px solid #ddd"><?php echo htmlspecialchars($project['notes'] ?? ''); ?></textarea></label>
        <div style="display:flex;gap:8px"><button type="submit" style="padding:8px 12px;border-radius:8px;background:var(--nav-accent);color:#fff">Save</button><a href="/?page=project/projects-list" style="padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff">Close</a></div>
      </form>
      <div style="margin-top:12px">
        <h4>Documents</h4>
        <form method="post" action="/?page=project/project-add-document" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
          <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
          <select name="document_type" required style="padding:8px;border-radius:8px;border:1px solid #ddd">
            <option value="quote">Quote</option>
            <option value="contract">Contract</option>
            <option value="invoice">Invoice</option>
          </select>
          <input type="text" name="document_id" placeholder="Document ID" required style="padding:8px;border-radius:8px;border:1px solid #ddd">
          <button type="submit" style="padding:8px 12px;border-radius:8px;background:var(--nav-accent);color:#fff">Add</button>
        </form>
        <div>
          <?php
            $docs = $pdo->prepare('SELECT id, document_type, document_id FROM project_documents WHERE project_id=? ORDER BY created_at DESC');
            $docs->execute([$project['id']]);
            $drows = $docs->fetchAll();
            if ($drows):
          ?>
            <ul style="list-style:none;padding:0;margin:0;display:grid;gap:6px">
              <?php foreach($drows as $d): ?>
                <li style="display:flex;gap:8px;align-items:center"><span style="font-weight:600"><?php echo htmlspecialchars($d['document_type']); ?></span> #<?php echo (int)$d['document_id']; ?>
                  <form method="post" action="/?page=project/project-remove-document" style="margin-left:auto">
                    <input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                    <button type="submit" style="padding:4px 8px;border-radius:6px;border:1px solid #eee;background:#fff">Remove</button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div style="color:var(--muted)">No documents linked.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; endif; ?>
</section>
