<?php
// src/views/pages/clients-list.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/format.php';
$per = (int)($_GET['per_page'] ?? 50); if(!in_array($per, [50,100], true)) $per = 50;
$pageN = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pageN - 1) * $per;
$q = trim($_GET['q'] ?? '');
$where = '';
$params = [];
if ($q !== '') { $where = 'WHERE name LIKE ?'; $params[] = '%'.$q.'%'; }

// Guard for older DBs without 'archived' column
$hasArchived = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='archived'")->fetchColumn();
$activeFilter = $hasArchived ? 'archived=0' : '1=1';

$stc = $pdo->prepare('SELECT COUNT(*) FROM clients '.($where? $where.' AND '.$activeFilter : 'WHERE '.$activeFilter));
$stc->execute($params);
$total = (int)$stc->fetchColumn();

$sql = "SELECT id, name, email, phone, organization, created_at FROM clients ".($where? $where.' AND '.$activeFilter : 'WHERE '.$activeFilter)." ORDER BY created_at DESC LIMIT $per OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$clients = $st->fetchAll();
?>
<section>
  <h2>Clients</h2>
  <?php $selected = isset($_GET['selected_client_id']) ? (int)$_GET['selected_client_id'] : 0; ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start">
  <div>
  <form method="get" action="/" style="display:flex;gap:8px;align-items:end;margin:12px 0">
    <input type="hidden" name="page" value="clients-list">
    <label>
      <div>Search by name</div>
      <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="e.g., Acme" style="padding:8px;border-radius:8px;border:1px solid #ddd">
    </label>
    <button type="submit" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Filter</button>
    <a href="/?page=clients-list" style="padding:8px 12px;border:1px solid #ddd;border-radius:8px;background:#fff; font-size: small;">Reset</a>
  </form>
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
          <th style="padding:10px">Created</th>
          <th style="padding:10px">Edit</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($clients as $c): ?>
          <tr style="border-top:1px solid #f3f4f6">
            <td style="padding:10px"><a href="/?page=clients-list&selected_client_id=<?php echo (int)$c['id']; ?>" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($c['name']); ?></a></td>
            <td style="padding:10px"><?php echo htmlspecialchars($c['email'] ?? ''); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars(format_phone($c['phone'] ?? '')); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($c['organization'] ?? ''); ?></td>
            <td style="padding:10px"><?php echo htmlspecialchars($c['created_at']); ?></td>
            <td style="padding:10px"><a href="/?page=clients-edit&id=<?php echo (int)$c['id']; ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Edit</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php
    $last = (int)ceil(max(1,$total)/$per);
    $qs = $_GET; unset($qs['p']); $base='/?'.http_build_query($qs+['page'=>'clients-list','per_page'=>$per]);
  ?>
  <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
    <div>
      <form method="get" action="/">
        <?php foreach ($_GET as $k=>$v){ if($k==='per_page'||$k==='p'||$k==='page') continue; echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars($v).'">'; }
        ?>
        <input type="hidden" name="page" value="clients-list">
        <label>Per page
          <select name="per_page" onchange="this.form.submit()" style="padding:6px;border-radius:8px;border:1px solid #ddd">
            <option value="50" <?php echo $per===50?'selected':''; ?>>50</option>
            <option value="100" <?php echo $per===100?'selected':''; ?>>100</option>
          </select>
        </label>
      </form>
    </div>
    <div style="display:flex;gap:8px">
      <?php if($pageN>1): ?><a href="<?php echo $base.'&p='.($pageN-1); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Prev</a><?php endif; ?>
      <div style="padding:6px 10px;color:var(--muted)">Page <?php echo $pageN; ?> / <?php echo $last; ?></div>
      <?php if($pageN<$last): ?><a href="<?php echo $base.'&p='.($pageN+1); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:8px;background:#fff">Next</a><?php endif; ?>
    </div>
  </div>
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
