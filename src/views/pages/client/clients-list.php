<?php
// src/views/pages/clients-list.php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/format.php';
$per = null; // show all clients
$pageN = 1;
$offset = 0;
$q = trim($_GET['q'] ?? '');
$where = '';
$params = [];
if ($q !== '') { $where = 'WHERE name LIKE ?'; $params[] = '%'.$q.'%'; }

// Guard for older DBs without 'archived' column
$hasArchived = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='archived'")->fetchColumn();
$activeFilter = $hasArchived ? 'archived=0' : '1=1';

// fetch all clients without pagination
$sql = "SELECT id, name, email, phone, organization, created_at FROM clients ".($where? $where.' AND '.$activeFilter : 'WHERE '.$activeFilter)." ORDER BY name ASC";
$st = $pdo->prepare($sql);
$st->execute($params);
$clients = $st->fetchAll();
?>
<section>
  <h2>Clients</h2>
  <div style="margin:8px 0">
    <a href="/?page=client/archived-clients" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">View Archived</a>
  </div>
  <?php if (!empty($_GET['archived'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;font-size:small;">Client archived.</div>
  <?php elseif (!empty($_GET['restored'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;font-size:small;">Client restored.</div>
  <?php elseif (!empty($_GET['deleted'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#fff1f2;color:#881337;border:1px solid #fca5a5;font-size:small;">Client permanently deleted.</div>
  <?php endif; ?>
  <?php $selected = isset($_GET['selected_client_id']) ? (int)$_GET['selected_client_id'] : 0; ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start">
  <div>
  <form method="get" action="/" style="display:flex;gap:8px;align-items:end;margin:12px 0;position:relative">
    <input type="hidden" name="page" value="client/clients-list">
    <label style="flex:1;position:relative">
      <div>Search by name</div>
      <input id="clientSearchBox" type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Type to search..." autocomplete="off" style="padding:8px;border-radius:8px;border:1px solid #ddd;width:100%">
      <div id="clientSearchSuggest" style="position:absolute;z-index:60;left:0;right:0;top:100%;background:#fff;border:1px solid #eee;border-radius:8px;display:none;max-height:220px;overflow:auto"></div>
    </label>
    <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Filter</button>
    <a href="/?page=client/clients-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Reset</a>
  </form>
  <script>
    (function(){
      var box = document.getElementById('clientSearchBox');
      var sug = document.getElementById('clientSearchSuggest');
      box.addEventListener('input', function(){
        var t = this.value.trim();
        if(!t){sug.style.display='none';sug.innerHTML='';return;}
  fetch('/?page=clients-search&term='+encodeURIComponent(t))
          .then(r=>r.json())
          .then(list=>{
            if(!Array.isArray(list)||list.length===0){sug.style.display='none';sug.innerHTML='';return;}
            sug.innerHTML = list.map(x=>`<div data-id="${x.id}" data-name="${x.name}" style=\"padding:8px 10px;cursor:pointer\">${x.name}</div>`).join('');
            Array.from(sug.children).forEach(el=>{
              el.addEventListener('click', function(){
                window.location = '/?page=client/clients-list&selected_client_id='+this.dataset.id;
              });
            });
            sug.style.display='block';
          }).catch(()=>{sug.style.display='none'});
      });
      document.addEventListener('click', function(e){ if(!sug.contains(e.target) && e.target!==box){ sug.style.display='none'; } });
    })();
  </script>
  <?php if (!empty($_GET['created'])): ?>
    <div style="margin:10px 0;padding:10px 12px;border-radius:8px;background:#e6fffa;color:#065f46;border:1px solid #99f6e4">Client created.</div>
  <?php endif; ?>
  <div style="overflow:auto">
    <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(11,18,32,0.06)">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid #eee">
          <th style="padding:10px">Name</th>
          <th style="padding:10px">Email</th>
          <th style="padding:10px">Phone</th>
          <th style="padding:10px">Organization</th>
          <!-- <th style="padding:10px">Created</th> -->
          <th style="padding:10px">Edit</th>
          <th style="padding:10px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($clients as $c): ?>
          <tr style="border-top:1px solid #f3f4f6">
            <td style="padding:10px"><a href="/?page=client/clients-list&selected_client_id=<?php echo (int)$c['id']; ?>" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($c['name']); ?></a></td>
            <td style="padding:10px"><?php echo htmlspecialchars($c['email'] ?? ''); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars(format_phone($c['phone'] ?? '')); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($c['organization'] ?? ''); ?></td>
            <!-- <td style="padding:10px"><?php echo htmlspecialchars($c['created_at']); ?></td> -->
            <td style="padding:10px"><a href="/?page=client/clients-edit&id=<?php echo (int)$c['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: medium;">Edit</a></td>
            <td style="padding:10px">
              <form method="post" action="/?page=clients-delete" onsubmit="return confirm('Archive client <?php echo addslashes($c['name']); ?>? This moves the client to Archived Clients.');" style="display:inline">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                <button type="submit" style="padding:6px 10px;border:1px solid #fca5a5;border-radius:8px;background:#fff;color:#b91c1c">Archive</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <!-- Pagination removed: showing all clients -->
  </div>
  <div>
    <h3 style="margin:0 0 8px">Related Projects</h3>
    <?php if ($selected>0): ?>
      <?php
      // Gather distinct project codes for this client
      $proj = $pdo->prepare("SELECT project_code FROM (
        SELECT project_code FROM quotes WHERE client_id=? AND project_code IS NOT NULL
        UNION DISTINCT SELECT project_code FROM contracts WHERE client_id=? AND project_code IS NOT NULL
        UNION DISTINCT SELECT project_code FROM invoices WHERE client_id=? AND project_code IS NOT NULL
      ) t ORDER BY project_code DESC");
      $proj->execute([$selected,$selected,$selected]);
      $projects = $proj->fetchAll(PDO::FETCH_COLUMN);
      ?>
      <?php if ($projects): ?>
        <div style="display:grid;gap:12px">
          <?php foreach ($projects as $pc): ?>
            <div style="border:1px solid #eee;border-radius:8px;background:#fff;overflow:hidden">
              <div style="padding:10px 12px;border-bottom:1px solid #eee;font-weight:600">Project <?php echo htmlspecialchars($pc); ?></div>
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;padding:12px">
                <?php
                  $q = $pdo->prepare('SELECT id, doc_number, total, status, created_at FROM quotes WHERE client_id=? AND project_code=? ORDER BY created_at DESC');
                  $q->execute([$selected, $pc]); $quotes = $q->fetchAll();
                  $co = $pdo->prepare('SELECT id, doc_number, status, created_at FROM contracts WHERE client_id=? AND project_code=? ORDER BY created_at DESC');
                  $co->execute([$selected, $pc]); $contracts = $co->fetchAll();
                  $i = $pdo->prepare('SELECT id, doc_number, total, status, created_at FROM invoices WHERE client_id=? AND project_code=? ORDER BY created_at DESC');
                  $i->execute([$selected, $pc]); $invoices = $i->fetchAll();
                ?>
                <div>
                  <div style="font-weight:600;margin-bottom:6px">Quotes</div>
                  <?php if ($quotes): ?>
                    <ul style="list-style:none;margin:0;padding:0;display:grid;gap:6px">
                      <?php foreach ($quotes as $row): ?>
                        <li><a href="/?page=quote-print&id=<?php echo (int)$row['id']; ?>">Q-<?php echo (int)($row['doc_number'] ?? $row['id']); ?></a> · $<?php echo number_format((float)$row['total'],2); ?> · <?php echo htmlspecialchars($row['status']); ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <div style="color:var(--muted)">None</div>
                  <?php endif; ?>
                </div>
                <div>
                  <div style="font-weight:600;margin-bottom:6px">Contracts</div>
                  <?php if ($contracts): ?>
                    <ul style="list-style:none;margin:0;padding:0;display:grid;gap:6px">
                      <?php foreach ($contracts as $row): ?>
                        <li><a href="/?page=contract-print&id=<?php echo (int)$row['id']; ?>">C-<?php echo (int)($row['doc_number'] ?? $row['id']); ?></a> · <?php echo htmlspecialchars($row['status']); ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <div style="color:var(--muted)">None</div>
                  <?php endif; ?>
                </div>
                <div>
                  <div style="font-weight:600;margin-bottom:6px">Invoices</div>
                  <?php if ($invoices): ?>
                    <ul style="list-style:none;margin:0;padding:0;display:grid;gap:6px">
                      <?php foreach ($invoices as $row): ?>
                        <li><a href="/?page=invoice-print&id=<?php echo (int)$row['id']; ?>">I-<?php echo (int)($row['doc_number'] ?? $row['id']); ?></a> · $<?php echo number_format((float)$row['total'],2); ?> · <?php echo htmlspecialchars($row['status']); ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <div style="color:var(--muted)">None</div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div style="color:var(--muted)">No projects for this client yet.</div>
      <?php endif; ?>
    <?php else: ?>
      <div style="color:var(--muted)">Select a client on the left to view related projects and documents.</div>
    <?php endif; ?>
  </div>
</section>
